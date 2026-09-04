<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpExtendsFqcnResolver;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Scans service providers for Laravel container registrations (bind/singleton/scoped
 * and $bindings).
 *
 * A registration is recorded when the receiver looks like the container
 * ({@see isAppLikeInvokable()}) and the abstract position resolves. The two positions are
 * resolved by different rules on purpose: the abstract is a container KEY, which may be a class
 * name or a bare alias ({@see resolveContainerKey()}), while the concrete has to be a class the
 * rest of the pipeline can locate a file for ({@see resolveClassLike()}).
 *
 * Measured over the Illuminate source and over one application of 60 modules, counting distinct
 * records in the registry: the analyzer used to find 100 and 60, and now finds 167 and 86 — it
 * was missing 40% and 30% of the registrations written in front of it. Of the 67 the framework
 * scan gained, 61 are bare aliases and 6 are single-argument self-bindings; of the application's
 * 26, 25 are single-argument self-bindings and 1 is a bare alias.
 */
final class ContainerBindingAnalyzer
{
    /**
     * Where providers live in a default Laravel skeleton.
     *
     * @var string[]
     */
    public const DEFAULT_PROVIDER_PATHS = ['app/Providers'];

    private PhpFileParser $parser;

    /** @var string[] provider directories, relative to the project root */
    private array $providerPaths;

    /** @var list<string> */
    private const BIND_METHODS = ['bind', 'singleton', 'scoped', 'bindIf', 'singletonIf', 'scopedIf'];

    /**
     * @param  string[]  $providerPaths  provider directories, relative to the project root;
     *                                   glob patterns are expanded
     */
    public function __construct(
        ?PhpFileParser $parser = null,
        array $providerPaths = self::DEFAULT_PROVIDER_PATHS,
    ) {
        $this->parser = $parser ?? new PhpFileParser;
        $this->providerPaths = $providerPaths;
    }

    public function analyze(string $projectRoot): ContainerBindingRegistry
    {
        $registry = new ContainerBindingRegistry;
        $root = rtrim($projectRoot, '/');
        $directories = SourceDirectories::resolve($root, $this->providerPaths);

        // Recursive, where this used to be `*.php` plus `**/*.php`: PHP's glob does not
        // cross directory separators, so the second pattern reached exactly one level down
        // and a provider nested any deeper was silently skipped.
        $paths = iterator_to_array(SourceDirectories::phpFiles($root, $directories), false);
        sort($paths);

        foreach ($paths as $file) {
            $this->scanFile($file, $registry);
        }

        return $registry;
    }

    private function scanFile(string $file, ContainerBindingRegistry $registry): void
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return;
        }
        $ast = $parsed['ast'];
        $useMap = $parsed['useMap'];
        $ns = PhpExtendsFqcnResolver::namespaceFromAst($ast);

        // Find the namespace wherever it sits: a leading `declare(strict_types=1);` shifts it off
        // index 0, which used to make the scan silently skip the whole provider. Same iteration
        // PhpExtendsFqcnResolver::namespaceFromAst() uses to resolve $ns above.
        $stmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                $stmts = $stmt->stmts;
                break;
            }
        }

        foreach ($stmts as $stmt) {
            if (! $stmt instanceof Class_) {
                continue;
            }
            if ($stmt->name === null) {
                continue;
            }
            $short = $stmt->name->toString();
            $providerFqcn = $ns !== '' ? $ns.'\\'.$short : $short;

            $this->walkClassStmts($stmt, $providerFqcn, $ns, $useMap, $registry);

            break;
        }
    }

    private function walkClassStmts(
        Class_ $class,
        string $providerFqcn,
        string $namespace,
        array $useMap,
        ContainerBindingRegistry $registry,
    ): void {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Property && ! $stmt->isStatic()) {
                foreach ($stmt->props as $prop) {
                    if (! $prop->name instanceof Identifier) {
                        continue;
                    }
                    $pname = $prop->name->toString();
                    if (! in_array($pname, ['bindings', 'singletons'], true)) {
                        continue;
                    }
                    $default = $prop->default;
                    if ($default instanceof Expr\Array_) {
                        $kind = $pname === 'singletons' ? 'singletons' : 'bindings';
                        $this->extractBindingArray($default, $providerFqcn, $namespace, $useMap, $kind, $registry);
                    }
                }
            }
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class($providerFqcn, $namespace, $useMap, $registry, $this) extends NodeVisitorAbstract
        {
            public function __construct(
                private string $providerFqcn,
                private string $namespace,
                private array $useMap,
                private ContainerBindingRegistry $registry,
                private ContainerBindingAnalyzer $analyzer,
            ) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof MethodCall) {
                    $this->analyzer->tryExtractFromMethodCall(
                        $node,
                        $this->providerFqcn,
                        $this->namespace,
                        $this->useMap,
                        $this->registry,
                    );
                }

                return null;
            }
        });

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->stmts !== null) {
                $traverser->traverse($stmt->stmts);
            }
        }
    }

    /**
     * @param  'bindings'|'singletons'  $arrayKind
     */
    private function extractBindingArray(
        Expr\Array_ $array,
        string $providerFqcn,
        string $namespace,
        array $useMap,
        string $arrayKind,
        ContainerBindingRegistry $registry,
    ): void {
        $kind = $arrayKind === 'singletons' ? 'singleton' : 'bind';

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            $keyExpr = $item->key;
            $val = $item->value;
            if ($keyExpr instanceof Expr) {
                $abstract = $this->resolveContainerKey($keyExpr, $namespace, $useMap);
                $concrete = $val instanceof Expr\Closure ? null : $this->resolveClassLike($val, $namespace, $useMap);
                if ($abstract !== null) {
                    $registry->add(new ContainerBindingRecord($abstract, $concrete, $providerFqcn, $kind));
                }
            }
        }
    }

    public function tryExtractFromMethodCall(
        MethodCall $node,
        string $providerFqcn,
        string $namespace,
        array $useMap,
        ContainerBindingRegistry $registry,
    ): void {
        if (! $node->name instanceof Identifier) {
            return;
        }
        $m = $node->name->toString();
        if (! in_array($m, self::BIND_METHODS, true)) {
            return;
        }
        if (! $this->isAppLikeInvokable($node->var)) {
            return;
        }

        $kind = match ($m) {
            'bind', 'bindIf' => 'bind',
            'singleton', 'singletonIf' => 'singleton',
            default => 'scoped',
        };

        [$v0, $v1] = $this->registrationArguments($node);
        if ($v0 === null) {
            return;
        }

        // One argument is a self-binding. Container::bind() does `if (is_null($concrete)) {
        // $concrete = $abstract; }`, so `$this->app->singleton(Foo::class)` really does resolve
        // Foo to Foo — the record says so rather than leaving concreteFqcn null, which in this
        // registry already means "bound through a closure, concrete unknown".
        //
        // The single-argument form is deliberately the STRICTER of the two: only a class-shaped
        // argument counts. The receiver check below admits `$app`, a plain variable name that a
        // non-container object can also carry, and a bare one-argument `->bind('x')` or
        // `->singleton($k)` is the shape an unrelated `bind()`/`singleton()` method is most
        // likely to have. Requiring `X::class` (or a namespace-bearing string) costs nothing
        // measurable: across the Illuminate source and one 60-provider application, every
        // single-argument container registration was class-shaped and none was a bare key.
        //
        // Deliberately NOT covered: the one-argument closure form Container::bind() routes to
        // bindBasedOnClosureReturnTypes(), where the abstract is the closure's return type
        // (`singleton(static fn (): Generator => …)`). It is a real registration, but reading it
        // means resolving union and intersection return types to a set of abstracts, and it
        // appeared twice in the two codebases measured here against 31 of the form above.
        if ($v1 === null) {
            $selfBound = $this->resolveClassLike($v0, $namespace, $useMap);
            if ($selfBound === null) {
                return;
            }

            $registry->add(new ContainerBindingRecord($selfBound, $selfBound, $providerFqcn, $kind));

            return;
        }

        $abstract = $this->resolveContainerKey($v0, $namespace, $useMap);
        if ($abstract === null) {
            return;
        }

        // The concrete position stays FQCN-only on purpose. A container key is legal there too
        // (`bind(Foo::class, 'foo')` chains onto another registration), but consumers treat
        // concreteFqcn as a class they can locate a file for — GraphBuilder builds a node out of
        // it — so an alias parked in that field would materialise as a class that does not exist.
        $concrete = $v1 instanceof Expr\Closure ? null : $this->resolveClassLike($v1, $namespace, $useMap);

        $registry->add(new ContainerBindingRecord($abstract, $concrete, $providerFqcn, $kind));
    }

    /**
     * Split a registration call into its abstract and concrete arguments, honouring named
     * arguments.
     *
     * Reading `$args[0]` and `$args[1]` positionally is wrong the moment someone writes
     * `->bind(concrete: Sql::class, abstract: Ledger::class)`: PHP binds those by name, so the
     * positional read records the two the wrong way round and the registry gains an entry that
     * is not merely missing but backwards. Named arguments in this exact call are already in the
     * wild — `->bind(abstract: ..., concrete: ...)` appears in the application measured here —
     * and only their happening to be written in declaration order kept the old read correct.
     *
     * A spread (`->bind(...$args)`) or a first-class callable (`->bind(...)`) carries no
     * argument this can name, so both give up rather than guess.
     *
     * @return array{0: ?Expr, 1: ?Expr} abstract, then concrete; concrete is null for a
     *                                   single-argument self-binding
     */
    private function registrationArguments(MethodCall $node): array
    {
        $abstract = null;
        $concrete = null;
        $position = 0;

        foreach ($node->args as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack) {
                return [null, null];
            }

            if ($arg->name instanceof Identifier) {
                $name = $arg->name->toString();
                if ($name === 'abstract') {
                    $abstract = $arg->value;
                } elseif ($name === 'concrete') {
                    $concrete = $arg->value;
                }

                // Anything else named (`shared:`) is not part of the abstract/concrete pair.
                continue;
            }

            if ($position === 0) {
                $abstract = $arg->value;
            } elseif ($position === 1) {
                $concrete = $arg->value;
            }
            $position++;
        }

        return [$abstract, $concrete];
    }

    /**
     * Resolve the ABSTRACT position of a registration, which is a container key and only
     * sometimes a class name.
     *
     * Laravel keys a large part of its own container by alias — `'mailer'`, `'cache'`, `'db'`,
     * `'view.finder'` — and applications do the same for anything a facade fronts. Requiring a
     * backslash, as {@see resolveClassLike()} does, dropped every one of them: 61 of the 167
     * registrations in the Illuminate source, and with them the whole reason
     * {@see FacadeRegistry::resolveWith()} exists. That method matches a facade's string accessor
     * against this registry, and it only ever looks at facades whose accessor carries no
     * namespace separator — the ones {@see FacadeAnalyzer} could not resolve on its own. While
     * the abstract position demanded a separator the two sets were disjoint by construction, so
     * the branch could not match on any input the two analyzers produce together. Handed a
     * project with `singleton('ledger', Ledger::class)` in a provider and a facade returning
     * `'ledger'`, it left the facade unresolved; it now resolves it to Ledger.
     *
     * The accepted shape is drawn from those 53 keys plus the application ones: an identifier
     * character followed by identifier characters, dots and hyphens. That is deliberately
     * narrower than "any string a container will accept" — the point is to keep an interpolated
     * path, a sentence or an empty string from being filed as a binding, and a key outside the
     * shape is only missed, never mis-recorded.
     *
     * @param  array<string, string>  $useMap
     */
    private function resolveContainerKey(?Expr $expr, string $namespace, array $useMap): ?string
    {
        $classLike = $this->resolveClassLike($expr, $namespace, $useMap);
        if ($classLike !== null) {
            return $classLike;
        }

        if (! $expr instanceof Scalar\String_) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.\-]*$/', $expr->value) === 1
            ? $expr->value
            : null;
    }

    /**
     * Is the receiver of this call the container?
     *
     * Three spellings are accepted: `$this->app` inside a provider, a `$app` variable (the
     * parameter name Laravel gives the container in every `bind`/`extend`/`resolving` closure),
     * and the `app()` helper. This is the whole of the analyzer's protection against recording
     * an unrelated `bind()` or `singleton()` — the scan is otherwise a bare method-name match —
     * so the argument rules above lean on it rather than relaxing it, and the loosest of the
     * three, a plain `$app` variable, is why the single-argument form additionally insists on a
     * class-shaped argument.
     */
    private function isAppLikeInvokable(?Expr $var): bool
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

        if (
            $var instanceof Expr\FuncCall
            && $var->name instanceof Name
            && $var->name->toString() === 'app'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Resolve an expression to a class FQCN, or null when it is not one.
     *
     * `X::class` resolves through the use map; a string only counts when it carries a namespace
     * separator, which is what keeps `'mailer'` out of the CONCRETE position of a registration.
     * The abstract position wants the opposite answer and goes through
     * {@see resolveContainerKey()} instead.
     *
     * @param  array<string, string>  $useMap
     */
    private function resolveClassLike(?Expr $expr, string $namespace, array $useMap): ?string
    {
        if ($expr === null) {
            return null;
        }

        if (
            $expr instanceof Expr\ClassConstFetch
            && $expr->name instanceof Identifier
            && $expr->name->toString() === 'class'
            && $expr->class instanceof Name
        ) {
            return $this->resolveNameToFqcn($expr->class, $namespace, $useMap);
        }

        if ($expr instanceof Scalar\String_) {
            $s = $expr->value;
            if (str_contains($s, '\\') && preg_match('/^\\\\?[\w\\\\]+$/', $s) === 1) {
                return ltrim($s, '\\');
            }
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

        if (str_contains($short, '\\')) {
            return ($namespace !== '' ? $namespace.'\\' : '').$short;
        }

        return $namespace !== '' ? $namespace.'\\'.$short : $short;
    }
}
