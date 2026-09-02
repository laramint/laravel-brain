<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * One method added to a class that does not declare it.
 */
class MacroDefinition
{
    public function __construct(
        /** The class the method is added to, as written in the registration. */
        public string $receiver,
        /** The method name callers will use. */
        public string $name,
        /** The file the registration is written in. */
        public string $file,
        public int $line,
        /** The class whose public methods were mixed in, when this came from `mixin()`. */
        public ?string $mixin = null,
        /** The class the registration is written in — usually a service provider. */
        public ?string $registrar = null,
    ) {}

    /** How the method got there, for a reader who has to go and find it. */
    public function origin(): string
    {
        return $this->mixin === null ? 'macro' : 'mixin';
    }
}

/**
 * Finds the methods an application adds to classes that do not declare them.
 *
 * A macro is invisible by construction: `$table->money()` resolves to nothing in `Blueprint`,
 * and no amount of reading that class explains where the method came from. The registration is
 * somewhere else entirely — usually a service provider, sometimes not — and the only way to find
 * it today is to know it exists. That is the gap this closes: the method becomes a thing with a
 * name, so looking for `money` finds where `money` is defined.
 *
 * Both registration forms are read:
 *
 *  - `Blueprint::macro('money', fn (…) => …)` — one named method;
 *  - `Builder::mixin(new OrderAnalytics)` — every public method of that class at once.
 *
 * The second multiplies where it is used, though it may not be used at all: the application
 * measured here registers 32 macros directly and no mixins — the `::mixin(` occurrences in it
 * turned out to be docblock prose and `@mixin` annotations, which is exactly the kind of thing
 * an AST pass gets right and a grep does not.
 *
 * Detection keys on the **call**, never on the receiver's traits. Filament ships its own
 * `Macroable` (`Filament\Support\Concerns\Macroable`), separate from Laravel's, with the same
 * `macro()` and `mixin()` signatures — so a check for `Illuminate\Support\Traits\Macroable` would
 * silently drop every Filament component macro. Measured on one application: 13 of its 32 direct
 * registrations are on Filament components.
 *
 * What this does not do is attribute *uses*. Resolving `$table->money()` back to this macro means
 * proving `$table` is a `Blueprint`, and the call sites live in migrations the scan does not read.
 * Where a method is defined can be known; who calls it cannot, and it is left alone rather than
 * guessed at.
 */
class MacroAnalyzer
{
    /** Methods that register on a Macroable, in either the Laravel or the Filament trait. */
    private const REGISTRARS = ['macro', 'mixin'];

    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] directories (relative to the project root, globs expanded) that are scanned */
    private array $paths;

    /** @var array<string, list<string>> mixin FQCN => its public method names */
    private array $mixinMethods = [];

    /** @param string[] $paths */
    public function __construct(array $paths = ['app'])
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->paths = $paths !== [] ? $paths : ['app'];
    }

    /**
     * @return list<MacroDefinition> every method registered onto another class, sorted
     */
    public function analyze(string $projectRoot): array
    {
        $directories = SourceDirectories::resolve($projectRoot, $this->paths);
        $files = iterator_to_array(SourceDirectories::phpFiles($projectRoot, $directories), false);

        // The mixin classes are indexed first: a mixin can be registered in one file and declared
        // in another, and a pass that read them in one sweep would resolve only the ones that
        // happened to come later.
        $this->mixinMethods = [];

        foreach ($files as $file) {
            $this->indexClassMethods($file);
        }

        $macros = [];

        foreach ($files as $file) {
            array_push($macros, ...$this->macrosIn($file));
        }

        usort($macros, static fn (MacroDefinition $a, MacroDefinition $b): int => [$a->receiver, $a->name] <=> [$b->receiver, $b->name]);

        return $macros;
    }

    /** Remember every class's public methods, so a mixin can be expanded into them later. */
    private function indexClassMethods(string $file): void
    {
        $parsed = $this->parser->parse($file);

        if ($parsed['ast'] === null) {
            return;
        }

        $namespace = $this->namespaceOf($parsed['ast']);

        foreach ($this->finder->findInstanceOf($parsed['ast'], Node\Stmt\Class_::class) as $class) {
            if ($class->name === null) {
                continue;
            }

            $names = [];

            foreach ($class->getMethods() as $method) {
                // Constructors are not contributed to the receiver, and neither is anything the
                // caller could not reach.
                if (! $method->isPublic() || $method->isStatic() || $method->name->toString() === '__construct') {
                    continue;
                }

                $names[] = $method->name->toString();
            }

            $this->mixinMethods[($namespace !== '' ? $namespace.'\\' : '').$class->name->toString()] = $names;
        }
    }

    /** @return list<MacroDefinition> */
    private function macrosIn(string $file): array
    {
        $parsed = $this->parser->parse($file);

        if ($parsed['ast'] === null) {
            return [];
        }

        $useMap = $parsed['useMap'] ?? [];
        $namespace = $this->namespaceOf($parsed['ast']);
        $registrar = $this->enclosingClass($parsed['ast'], $namespace);
        $found = [];

        foreach ($this->finder->findInstanceOf($parsed['ast'], Node\Expr\StaticCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || ! in_array($call->name->toString(), self::REGISTRARS, true)) {
                continue;
            }

            if (! $call->class instanceof Node\Name) {
                continue;
            }

            $receiver = $this->resolveClass($call->class->toString(), $useMap, $namespace);

            if ($receiver === null) {
                continue;
            }

            $argument = $call->args[0] ?? null;

            if (! $argument instanceof Node\Arg) {
                continue;
            }

            $line = $call->getStartLine();

            if ($call->name->toString() === 'macro') {
                if ($argument->value instanceof Node\Scalar\String_) {
                    $found[] = new MacroDefinition($receiver, $argument->value->value, $file, $line, null, $registrar);
                }

                continue;
            }

            foreach ($this->mixinMethodsOf($argument->value, $useMap, $namespace) as $mixin => $methods) {
                foreach ($methods as $method) {
                    $found[] = new MacroDefinition($receiver, $method, $file, $line, $mixin, $registrar);
                }
            }
        }

        return $found;
    }

    /**
     * The public methods a `mixin()` argument contributes, keyed by the class they came from.
     *
     * Both spellings are read — `mixin(new Analytics)` and `mixin(Analytics::class)` — because
     * Laravel accepts either and the two Macroable traits disagree about which they document.
     * A mixin whose class this pass never saw contributes nothing rather than a guess.
     *
     * @param  array<string, string>  $useMap
     * @return array<string, list<string>>
     */
    private function mixinMethodsOf(Node\Expr $expr, array $useMap, string $namespace): array
    {
        $class = match (true) {
            $expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name => $expr->class->toString(),
            $expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name => $expr->class->toString(),
            default => null,
        };

        if ($class === null) {
            return [];
        }

        $resolved = $this->resolveClass($class, $useMap, $namespace);

        if ($resolved === null || ! isset($this->mixinMethods[$resolved])) {
            return [];
        }

        return [$resolved => $this->mixinMethods[$resolved]];
    }

    /** @param array<string, string> $useMap */
    private function resolveClass(string $name, array $useMap, string $namespace): ?string
    {
        if ($name === 'self' || $name === 'static' || $name === 'parent') {
            return null;
        }

        if (isset($useMap[$name])) {
            return $useMap[$name];
        }

        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    /**
     * The class the file declares, which is the thing a reader has to open to find the macro.
     *
     * @param  Node\Stmt[]  $ast
     */
    private function enclosingClass(array $ast, string $namespace): ?string
    {
        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return null;
        }

        return ($namespace !== '' ? $namespace.'\\' : '').$class->name->toString();
    }

    /** @param Node\Stmt[] $ast */
    private function namespaceOf(array $ast): string
    {
        $node = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);

        return $node instanceof Node\Stmt\Namespace_ && $node->name !== null ? $node->name->toString() : '';
    }
}
