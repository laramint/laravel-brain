<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;

/**
 * Recognises a cache call in an expression and reads what it can out of the source.
 *
 * Scope is deliberately the two entry points whose receiver is unambiguous — the `Cache` facade
 * and the `cache()` helper, in any `store()`/`driver()`/`tags()` chain over them. An injected
 * `$this->cache` repository is a real cache read too, but nothing in the source proves the
 * property holds a cache rather than anything else with a `get()`, and a panel that guesses is
 * worse than one that is silent: the whole value of this section is that a row on it is a fact.
 */
class CacheOperationDetector
{
    /**
     * Cache methods, mapped to what the call does to cache state.
     *
     * `remember`/`rememberForever` are reads: they write only on a miss, and what a reader wants
     * from them is "this value can be stale". `pull` is the opposite case and is filed as an
     * invalidation — it is the one read that also clears the key, and someone tracing "what
     * forgets this key" must find it. The method name travels alongside the kind either way, so
     * neither classification hides what the call really was.
     *
     * @var array<string, string>
     */
    private const OPERATIONS = [
        'get' => 'read',
        'many' => 'read',
        'getMultiple' => 'read',
        'has' => 'read',
        'missing' => 'read',
        'remember' => 'read',
        'rememberForever' => 'read',
        'sear' => 'read',
        'flexible' => 'read',
        'put' => 'write',
        'set' => 'write',
        'putMany' => 'write',
        'setMultiple' => 'write',
        'add' => 'write',
        'forever' => 'write',
        'increment' => 'write',
        'decrement' => 'write',
        'forget' => 'invalidate',
        'delete' => 'invalidate',
        'deleteMultiple' => 'invalidate',
        'pull' => 'invalidate',
        'flush' => 'invalidate',
        'clear' => 'invalidate',
        'lock' => 'lock',
        'restoreLock' => 'lock',
    ];

    /**
     * Which argument holds a TTL, for the methods that take one in seconds.
     *
     * `flexible()` is absent on purpose: its second argument is a `[fresh, stale]` pair, not a
     * duration, and reporting the first half of it as "the TTL" would be wrong rather than
     * merely incomplete.
     *
     * @var array<string, int>
     */
    private const TTL_ARGUMENT = [
        'remember' => 1,
        'put' => 2,
        'set' => 2,
        'add' => 2,
        'lock' => 1,
    ];

    /** Methods that act on the whole store, so there is no key to report. */
    private const KEYLESS = ['flush', 'clear'];

    /** Methods whose first argument is a list or map of keys rather than one key. */
    private const KEY_LIST = ['many', 'getMultiple', 'putMany', 'setMultiple', 'deleteMultiple'];

    /** A rendered key longer than this is truncated — panel rows are one line. */
    private const MAX_KEY_LENGTH = 120;

    private PrettyPrinter $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter;
    }

    /**
     * @param  array<string, string>  $useMap  alias => FQCN, as produced by PhpFileParser
     */
    public function detect(Node\Expr $expr, array $useMap): ?CacheOperation
    {
        if ($expr instanceof Node\Expr\StaticCall) {
            $args = $this->argsOf($expr);
            if ($args === null || ! $this->isCacheFacade($expr->class, $useMap)) {
                return null;
            }

            return $this->build($this->methodName($expr->name), $args, '', []);
        }

        if ($expr instanceof Node\Expr\MethodCall) {
            $args = $this->argsOf($expr);
            $chain = ['store' => '', 'tags' => [], 'lock' => null];
            if ($args === null || ! $this->walkReceiver($expr->var, $useMap, $chain)) {
                return null;
            }

            // A lock that is used stays a lock. `Cache::lock($key)->get(fn)` is the only way
            // anyone writes one, and reading the outer verb instead would file it as a plain
            // read of a key the lock owns — or, as it did before, drop it entirely: measured on
            // a 60-module application, 16 `Cache::lock` calls produced 0 lock operations.
            if ($chain['lock'] instanceof CacheOperation) {
                return $chain['lock'];
            }

            return $this->build($this->methodName($expr->name), $args, $chain['store'], $chain['tags']);
        }

        if ($expr instanceof Node\Expr\FuncCall) {
            return $this->detectHelperCall($expr);
        }

        return null;
    }

    /**
     * `cache('key')` reads, `cache(['key' => $value], $ttl)` writes, and bare `cache()` is only a
     * repository — an operation on it arrives here as a MethodCall instead.
     *
     * The two forms are told apart by the shape of the first argument, which is exactly how the
     * helper itself decides: an array is a write and anything else is a key to read.
     */
    private function detectHelperCall(Node\Expr\FuncCall $expr): ?CacheOperation
    {
        if (! $expr->name instanceof Node\Name || $expr->name->toString() !== 'cache') {
            return null;
        }

        $args = $this->argsOf($expr);
        if ($args === null || $args === []) {
            return null;
        }

        $first = $args[0]->value;
        if ($first instanceof Node\Expr\Array_) {
            [$key, $keyKind] = $this->renderArrayKeys($first);

            return new CacheOperation('write', 'cache', $key, $keyKind, '', [], $this->literalTtl($args, 1));
        }

        [$key, $keyKind] = $this->renderKey($first);

        return new CacheOperation('read', 'cache', $key, $keyKind);
    }

    /**
     * Walk back down a `->tags()->store()->…` chain to see whether it starts at the cache.
     *
     * @param  array<string, string>  $useMap
     * @param  array{store: string, tags: string[], lock?: CacheOperation|null}  $chain
     */
    private function walkReceiver(Node\Expr $receiver, array $useMap, array &$chain): bool
    {
        if ($receiver instanceof Node\Expr\FuncCall) {
            return $receiver->name instanceof Node\Name
                && $receiver->name->toString() === 'cache'
                && $this->argsOf($receiver) === [];
        }

        if ($receiver instanceof Node\Expr\StaticCall) {
            $args = $this->argsOf($receiver);

            return $args !== null
                && $this->isCacheFacade($receiver->class, $useMap)
                && $this->recordChainHop($this->methodName($receiver->name), $args, $chain);
        }

        if ($receiver instanceof Node\Expr\MethodCall) {
            $args = $this->argsOf($receiver);

            return $args !== null
                && $this->recordChainHop($this->methodName($receiver->name), $args, $chain)
                && $this->walkReceiver($receiver->var, $useMap, $chain);
        }

        return false;
    }

    /**
     * @param  Node\Arg[]  $args
     * @param  array{store: string, tags: string[], lock?: CacheOperation|null}  $chain
     */
    private function recordChainHop(string $method, array $args, array &$chain): bool
    {
        if ($method === 'store' || $method === 'driver') {
            $chain['store'] = isset($args[0]) ? $this->literalString($args[0]->value) : '';

            return true;
        }

        if ($method === 'tags') {
            $chain['tags'] = $this->renderTags($args);

            return true;
        }

        // `Cache::memo()` hands back a memoising repository, so the operation is whatever is
        // called on it. Passing through rather than terminating is what makes that reachable.
        if ($method === 'memo') {
            return true;
        }

        if ($method === 'lock' || $method === 'restoreLock') {
            $chain['lock'] = $this->build($method, $args, $chain['store'], $chain['tags']);

            return $chain['lock'] instanceof CacheOperation;
        }

        return false;
    }

    /**
     * @param  Node\Arg[]  $args
     * @param  string[]  $tags
     */
    private function build(string $method, array $args, string $store, array $tags): ?CacheOperation
    {
        $kind = self::OPERATIONS[$method] ?? null;
        if ($kind === null) {
            return null;
        }

        [$key, $keyKind] = $this->keyOf($method, $args);

        return new CacheOperation($kind, $method, $key, $keyKind, $store, $tags, $this->literalTtl($args, self::TTL_ARGUMENT[$method] ?? -1));
    }

    /**
     * @param  Node\Arg[]  $args
     * @return array{0: string, 1: string}
     */
    private function keyOf(string $method, array $args): array
    {
        if (in_array($method, self::KEYLESS, true)) {
            return ['', 'none'];
        }
        if (! isset($args[0])) {
            return ['', 'computed'];
        }

        $first = $args[0]->value;
        if (in_array($method, self::KEY_LIST, true) && $first instanceof Node\Expr\Array_) {
            return $this->renderArrayKeys($first);
        }

        return $this->renderKey($first);
    }

    /**
     * A list or map of keys, rendered as the comma-separated keys it names.
     *
     * `putMany` is keyed by the cache key, while `many`/`deleteMultiple` take a plain list of
     * them, so the key of an item is used where there is one and its value otherwise.
     *
     * @return array{0: string, 1: string}
     */
    private function renderArrayKeys(Node\Expr\Array_ $array): array
    {
        $rendered = [];
        $anyLiteral = false;
        $allLiteral = true;

        foreach ($array->items as $item) {
            [$text, $hasLiteral] = $this->renderKeyParts($item->key ?? $item->value);
            $rendered[] = $text;
            $anyLiteral = $anyLiteral || $hasLiteral;
            $allLiteral = $allLiteral && ($item->key ?? $item->value) instanceof Node\Scalar\String_;
        }

        if ($rendered === []) {
            return ['', 'computed'];
        }
        if (! $anyLiteral) {
            return ['', 'computed'];
        }

        return [$this->truncate(implode(', ', $rendered)), $allLiteral ? 'literal' : 'constructed'];
    }

    /**
     * @return array{0: string, 1: string} the rendered key and how far it can be trusted
     */
    private function renderKey(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [$this->truncate($expr->value), 'literal'];
        }

        [$text, $hasLiteral] = $this->renderKeyParts($expr);

        // Nothing literal anywhere in it — `$key`, or `$a . $b` — so there is no key to show and
        // no honest way to guess one. The panel says "computed" rather than printing the variable
        // as though it were the key.
        return $hasLiteral ? [$this->truncate($text), 'constructed'] : ['', 'computed'];
    }

    /**
     * @return array{0: string, 1: bool} rendered text, and whether any literal chunk contributed
     */
    private function renderKeyParts(Node\Expr $expr): array
    {
        if ($expr instanceof Node\Scalar\String_) {
            return [$expr->value, true];
        }

        if ($expr instanceof Node\Scalar\Int_) {
            return [(string) $expr->value, true];
        }

        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $text = '';
            $hasLiteral = false;
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $text .= $part->value;
                    $hasLiteral = $hasLiteral || $part->value !== '';

                    continue;
                }
                $text .= '{'.$this->print($part).'}';
            }

            return [$text, $hasLiteral];
        }

        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            [$leftText, $leftLiteral] = $this->renderKeyParts($expr->left);
            [$rightText, $rightLiteral] = $this->renderKeyParts($expr->right);

            return [$leftText.$rightText, $leftLiteral || $rightLiteral];
        }

        // `CacheKeys::USER` names the key without spelling it. Printing the reference is not a
        // guess about its value, so it counts as literal content: it is what the source says.
        if ($expr instanceof Node\Expr\ClassConstFetch || $expr instanceof Node\Expr\ConstFetch) {
            return [$this->print($expr), true];
        }

        return ['{'.$this->print($expr).'}', false];
    }

    /**
     * @param  Node\Arg[]  $args
     * @return string[]
     */
    private function renderTags(array $args): array
    {
        $values = [];
        foreach ($args as $arg) {
            if ($arg->value instanceof Node\Expr\Array_) {
                foreach ($arg->value->items as $item) {
                    $values[] = $item->value;
                }

                continue;
            }
            $values[] = $arg->value;
        }

        $tags = [];
        foreach ($values as $value) {
            $tags[] = $value instanceof Node\Scalar\String_
                ? $value->value
                : $this->truncate($this->renderKeyParts($value)[0]);
        }

        return $tags;
    }

    /**
     * @param  Node\Arg[]  $args
     */
    private function literalTtl(array $args, int $index): ?int
    {
        if ($index < 0 || ! isset($args[$index])) {
            return null;
        }
        $value = $args[$index]->value;

        return $value instanceof Node\Scalar\Int_ ? $value->value : null;
    }

    private function literalString(Node\Expr $expr): string
    {
        return $expr instanceof Node\Scalar\String_ ? $expr->value : '';
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function isCacheFacade(Node\Name|Node\Expr $class, array $useMap): bool
    {
        if (! $class instanceof Node\Name) {
            return false;
        }

        $name = $class->toString();

        return $name === 'Cache' || ($useMap[$name] ?? $name) === 'Illuminate\\Support\\Facades\\Cache';
    }

    /**
     * A first-class callable (`Cache::get(...)`) has a placeholder where its arguments go, and
     * php-parser's getArgs() asserts against being asked for them. It creates a closure rather
     * than touching the cache, so there is nothing here to report either way.
     *
     * @return Node\Arg[]|null
     */
    private function argsOf(Node\Expr\CallLike $call): ?array
    {
        return $call->isFirstClassCallable() ? null : $call->getArgs();
    }

    private function methodName(Node\Identifier|Node\Expr $name): string
    {
        return $name instanceof Node\Identifier ? $name->toString() : '';
    }

    private function print(Node\Expr $expr): string
    {
        try {
            return $this->printer->prettyPrintExpr($expr);
        } catch (\Throwable) {
            return '…';
        }
    }

    private function truncate(string $text): string
    {
        return strlen($text) > self::MAX_KEY_LENGTH
            ? substr($text, 0, self::MAX_KEY_LENGTH).'…'
            : $text;
    }
}
