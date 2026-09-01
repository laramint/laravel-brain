<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpExtendsFqcnResolver;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Reads what each service provider declares about its own loading: whether it is deferred, which
 * service keys it promises through provides(), which events would register it through when(), and
 * which keys it actually registers with the container.
 *
 * Scans the same directories as {@see ContainerBindingAnalyzer} and shares its parse results —
 * {@see PhpFileParser} caches per process, so the second walk over the provider tree costs a tree
 * traversal, not a re-parse.
 *
 * Deliberately separate from ContainerBindingAnalyzer rather than folded into it: that analyzer
 * answers "which concrete satisfies this abstract", keyed by abstract and last-one-wins, and only
 * records two-argument registrations of class-shaped keys. None of those three properties suit
 * this question. A deferred provider is judged per provider, its `provides()` routinely names
 * string aliases like `'mailer'` that carry no backslash, and `$this->app->singleton(Foo::class)`
 * with one argument is a perfectly good registration.
 */
final class ServiceProviderAnalyzer
{
    public const DEFERRABLE_PROVIDER = 'Illuminate\Contracts\Support\DeferrableProvider';

    /**
     * Container registrations whose first argument is a service key the provider now answers for.
     *
     * `alias()` is here because an alias is also a resolvable key, and both of its arguments are
     * keys. `extend()` is deliberately absent: it decorates a binding somebody else made, so a
     * provider that only extends does not provide.
     *
     * @var list<string>
     */
    private const REGISTRATION_METHODS = [
        'bind', 'bindIf', 'singleton', 'singletonIf', 'scoped', 'scopedIf', 'instance', 'alias',
    ];

    private PhpFileParser $parser;

    /** @var string[] provider directories, relative to the project root */
    private array $providerPaths;

    /**
     * @param  string[]  $providerPaths  provider directories, relative to the project root;
     *                                   glob patterns are expanded
     */
    public function __construct(
        ?PhpFileParser $parser = null,
        array $providerPaths = ContainerBindingAnalyzer::DEFAULT_PROVIDER_PATHS,
    ) {
        $this->parser = $parser ?? new PhpFileParser;
        $this->providerPaths = $providerPaths;
    }

    public function analyze(string $projectRoot): ServiceProviderRegistry
    {
        $registry = new ServiceProviderRegistry;
        $root = rtrim($projectRoot, '/');
        $directories = SourceDirectories::resolve($root, $this->providerPaths);

        $paths = iterator_to_array(SourceDirectories::phpFiles($root, $directories), false);
        sort($paths);

        foreach ($paths as $file) {
            $this->scanFile($file, $registry);
        }

        return $registry;
    }

    private function scanFile(string $file, ServiceProviderRegistry $registry): void
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return;
        }
        $ast = $parsed['ast'];
        $useMap = $parsed['useMap'];
        $ns = PhpExtendsFqcnResolver::namespaceFromAst($ast);

        // A leading declare(strict_types=1) shifts the namespace off index 0; find it wherever it
        // sits, the way ContainerBindingAnalyzer does.
        $stmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $stmts = $stmt->stmts;
                break;
            }
        }

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Class_ || $stmt->name === null) {
                continue;
            }

            $short = $stmt->name->toString();
            $fqcn = $ns !== '' ? $ns.'\\'.$short : $short;

            $registry->add($this->recordFor($stmt, $fqcn, $file, $ns, $useMap));

            break;
        }
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function recordFor(
        Class_ $class,
        string $fqcn,
        string $file,
        string $namespace,
        array $useMap,
    ): ServiceProviderRecord {
        [$provides, $providesIsDynamic] = $this->declaredServiceKeys($class, 'provides', $namespace, $useMap);
        [$when] = $this->declaredServiceKeys($class, 'when', $namespace, $useMap);

        return new ServiceProviderRecord(
            fqcn: $fqcn,
            file: $file,
            deferred: $this->implementsDeferrable($class, $namespace, $useMap),
            legacyDeferProperty: $this->hasLegacyDeferProperty($class),
            provides: $provides,
            providesIsDynamic: $providesIsDynamic,
            when: $when,
            bindingKeys: $this->registeredKeys($class, $namespace, $useMap),
        );
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function implementsDeferrable(Class_ $class, string $namespace, array $useMap): bool
    {
        foreach ($class->implements as $interface) {
            if ($this->resolveNameToFqcn($interface, $namespace, $useMap) === self::DEFERRABLE_PROVIDER) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the class carries the pre-5.8 `$defer` property set to true.
     *
     * Only `true` counts: `$defer = false` was the explicit opt-out and says nothing was intended.
     */
    private function hasLegacyDeferProperty(Class_ $class): bool
    {
        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (! $prop->name instanceof Identifier || $prop->name->toString() !== 'defer') {
                    continue;
                }
                $default = $prop->default;
                if ($default instanceof Expr\ConstFetch && strtolower($default->name->toString()) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Read the array of service keys a no-argument declaration method returns.
     *
     * Returns [keys, dynamic]. `dynamic` is the honest "we cannot tell": the method exists but
     * returns something other than an array literal of resolvable keys — `array_keys($this->x)`,
     * a merge, a property. Nothing downstream may report a defect on a dynamic list, because a
     * dynamic list may well be correct.
     *
     * A method that is absent entirely is NOT dynamic: the inherited
     * `ServiceProvider::provides()` returns `[]`, which is a real, and empty, answer.
     *
     * @param  array<string, string>  $useMap
     * @return array{0: list<string>, 1: bool}
     */
    private function declaredServiceKeys(
        Class_ $class,
        string $methodName,
        string $namespace,
        array $useMap,
    ): array {
        $method = $this->findMethod($class, $methodName);
        if ($method === null || $method->stmts === null) {
            return [[], false];
        }

        $returns = $this->topLevelReturns($method);
        if ($returns === []) {
            // Declared but returning nothing we can see — an abstract body, or a `throw`. Say
            // "unknown" rather than "empty", so no defect is reported on a guess.
            return [[], true];
        }

        $keys = [];
        $dynamic = false;

        foreach ($returns as $return) {
            if (! $return->expr instanceof Expr\Array_) {
                $dynamic = true;

                continue;
            }

            foreach ($return->expr->items as $item) {
                if (! $item instanceof Node\ArrayItem || $item->unpack) {
                    $dynamic = true;

                    continue;
                }
                $key = $this->resolveServiceKey($item->value, $namespace, $useMap);
                if ($key === null) {
                    $dynamic = true;

                    continue;
                }
                $keys[$key] = true;
            }
        }

        return [array_keys($keys), $dynamic];
    }

    /**
     * Return statements belonging to the method itself.
     *
     * Stops at any nested function-like or class-like body: a `return` inside a closure passed to
     * `array_map()` inside provides() belongs to the closure, and reading it as the method's
     * answer would invent a service list out of unrelated code.
     *
     * @return list<Node\Stmt\Return_>
     */
    private function topLevelReturns(ClassMethod $method): array
    {
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var list<Node\Stmt\Return_> */
            public array $returns = [];

            public function enterNode(Node $node): ?int
            {
                if (
                    $node instanceof Expr\Closure
                    || $node instanceof Expr\ArrowFunction
                    || $node instanceof Node\Stmt\Function_
                    || $node instanceof Node\Stmt\ClassLike
                ) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Node\Stmt\Return_) {
                    $this->returns[] = $node;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($collector);
        $traverser->traverse($method->stmts ?? []);

        return $collector->returns;
    }

    /**
     * Every service key this provider registers with the container.
     *
     * Covers the `$bindings` / `$singletons` property arrays and the registration calls on an
     * app-like receiver, including the single-argument `singleton(Foo::class)` form that
     * ContainerBindingAnalyzer skips because it has no concrete to pair with.
     *
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    private function registeredKeys(Class_ $class, string $namespace, array $useMap): array
    {
        $keys = [];

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Property || $stmt->isStatic()) {
                continue;
            }
            foreach ($stmt->props as $prop) {
                if (
                    ! $prop->name instanceof Identifier
                    || ! in_array($prop->name->toString(), ['bindings', 'singletons'], true)
                    || ! $prop->default instanceof Expr\Array_
                ) {
                    continue;
                }
                foreach ($prop->default->items as $item) {
                    if (! $item instanceof Node\ArrayItem || ! $item->key instanceof Expr) {
                        continue;
                    }
                    $key = $this->resolveServiceKey($item->key, $namespace, $useMap);
                    if ($key !== null) {
                        $keys[$key] = true;
                    }
                }
            }
        }

        $visitor = new class($this, $namespace, $useMap) extends NodeVisitorAbstract
        {
            /** @var array<string, true> */
            public array $keys = [];

            /**
             * @param  array<string, string>  $useMap
             */
            public function __construct(
                private ServiceProviderAnalyzer $analyzer,
                private string $namespace,
                private array $useMap,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Expr\MethodCall) {
                    foreach ($this->analyzer->registrationKeysOf($node, $this->namespace, $this->useMap) as $key) {
                        $this->keys[$key] = true;
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && $stmt->stmts !== null) {
                $traverser->traverse($stmt->stmts);
            }
        }

        foreach (array_keys($visitor->keys) as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }

    /**
     * Service keys introduced by one container registration call, or an empty list when the call
     * is not one.
     *
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    public function registrationKeysOf(Expr\MethodCall $node, string $namespace, array $useMap): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }
        $method = $node->name->toString();
        if (! in_array($method, self::REGISTRATION_METHODS, true)) {
            return [];
        }
        if (! $this->isAppLikeReceiver($node->var)) {
            return [];
        }

        // alias($abstract, $alias) names two resolvable keys; everything else names one.
        $positions = $method === 'alias' ? [0, 1] : [0];
        $keys = [];

        foreach ($positions as $position) {
            $arg = $node->args[$position] ?? null;
            if (! $arg instanceof Node\Arg) {
                continue;
            }
            $key = $this->resolveServiceKey($arg->value, $namespace, $useMap);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function isAppLikeReceiver(?Expr $var): bool
    {
        if ($var === null) {
            return false;
        }

        if (
            $var instanceof Expr\PropertyFetch
            && $var->var instanceof Expr\Variable
            && $var->var->name === 'this'
            && $var->name instanceof Identifier
            && $var->name->toString() === 'app'
        ) {
            return true;
        }

        if ($var instanceof Expr\Variable && is_string($var->name) && $var->name === 'app') {
            return true;
        }

        return $var instanceof Expr\FuncCall
            && $var->name instanceof Name
            && $var->name->toString() === 'app';
    }

    /**
     * A container key as Laravel sees it: `Foo::class` and `'App\Foo'` alike become the FQCN,
     * and a bare alias like `'mailer'` stays exactly as written, because that is the string the
     * deferred manifest is keyed by.
     *
     * @param  array<string, string>  $useMap
     */
    private function resolveServiceKey(?Expr $expr, string $namespace, array $useMap): ?string
    {
        if (
            $expr instanceof Expr\ClassConstFetch
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'class'
            && $expr->class instanceof Name
        ) {
            return $this->resolveNameToFqcn($expr->class, $namespace, $useMap);
        }

        if ($expr instanceof Scalar\String_) {
            $value = ltrim($expr->value, '\\');

            return $value === '' ? null : $value;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function resolveNameToFqcn(Name $name, string $namespace, array $useMap): string
    {
        if ($name instanceof Name\FullyQualified) {
            return ltrim($name->toString(), '\\');
        }

        $short = $name->toString();
        if (isset($useMap[$short])) {
            return $useMap[$short];
        }

        // A partially qualified name resolves against the file namespace, unless its first
        // segment is itself imported (`use App\Contracts;` then `Contracts\Clock::class`).
        if (str_contains($short, '\\')) {
            $head = strstr($short, '\\', true);
            if ($head !== false && isset($useMap[$head])) {
                return $useMap[$head].substr($short, strlen($head));
            }

            return ($namespace !== '' ? $namespace.'\\' : '').$short;
        }

        return $namespace !== '' ? $namespace.'\\'.$short : $short;
    }

    private function findMethod(Class_ $class, string $name): ?ClassMethod
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof ClassMethod && strcasecmp($stmt->name->toString(), $name) === 0) {
                return $stmt;
            }
        }

        return null;
    }
}
