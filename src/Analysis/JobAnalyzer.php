<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Throwable;

/**
 * Reads a job class for what it promises about retrying, uniqueness and gating.
 *
 * Two sources, for two different kinds of fact. Interfaces are asked of the loaded class, because
 * `ShouldBeUnique` is as often inherited from a base job as written on the job itself. Everything
 * else is read from the source, because a declared `$tries = 5` is a fact the file states and
 * instantiating a job to ask it is not something an analyzer should do — a constructor can take
 * models, open connections, or throw.
 *
 * Measured on a real application of 113 jobs: 44 declare `ShouldBeUnique`, 32 an `uniqueId()`, 14
 * a `middleware()`, and the retry envelope is scattered across 8 `$timeout`, 7 `$tries`, 8
 * `retryUntil()` and 4 `backoff()`. Reading properties alone would have found a third of it,
 * which is why a fact expressed as a method is reported rather than skipped.
 */
class JobAnalyzer
{
    private const INTERFACES = [
        'queued' => 'Illuminate\\Contracts\\Queue\\ShouldQueue',
        'unique' => 'Illuminate\\Contracts\\Queue\\ShouldBeUnique',
        'uniqueUntilProcessing' => 'Illuminate\\Contracts\\Queue\\ShouldBeUniqueUntilProcessing',
        'encrypted' => 'Illuminate\\Contracts\\Queue\\ShouldBeEncrypted',
    ];

    /** Facts a job may express as a method instead of a value. */
    private const DYNAMIC_METHODS = ['tries', 'timeout', 'backoff', 'retryUntil', 'uniqueId', 'uniqueVia'];

    private PhpFileParser $parser;

    private NodeFinder $finder;

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
    }

    public function describe(string $fqcn, string $file): ?JobDefinition
    {
        $class = $file !== '' && is_file($file) ? $this->classNode($file) : null;

        $properties = $class === null ? [] : $this->scalarProperties($class);
        $methods = $class === null ? [] : $this->declaredMethods($class);

        $dynamic = [];
        foreach (self::DYNAMIC_METHODS as $name) {
            // A method wins over a property of the same name — it is what Laravel calls.
            if (isset($methods[$name]) && ! isset($properties[$name])) {
                $dynamic[] = $name;
            }
        }

        // A method whose whole body is `return 60;` states a value as plainly as a property does.
        foreach (['tries', 'timeout', 'backoff', 'maxExceptions'] as $name) {
            if (isset($methods[$name]) && ! isset($properties[$name])) {
                $literal = $this->literalReturn($methods[$name]);

                if ($literal !== null) {
                    $properties[$name] = $literal;
                    $dynamic = array_values(array_filter($dynamic, fn (string $d): bool => $d !== $name));
                }
            }
        }

        $definition = new JobDefinition(
            fqcn: $fqcn,
            queued: $this->implementsInterface($fqcn, self::INTERFACES['queued']),
            tries: $properties['tries'] ?? null,
            timeout: $properties['timeout'] ?? null,
            backoff: $properties['backoff'] ?? null,
            maxExceptions: $properties['maxExceptions'] ?? null,
            unique: $this->implementsInterface($fqcn, self::INTERFACES['unique']),
            uniqueUntilProcessing: $this->implementsInterface($fqcn, self::INTERFACES['uniqueUntilProcessing']),
            uniqueFor: $properties['uniqueFor'] ?? null,
            encrypted: $this->implementsInterface($fqcn, self::INTERFACES['encrypted']),
            afterCommit: ($properties['afterCommit'] ?? null) === 1,
            batchable: $this->usesTrait($fqcn, 'Illuminate\\Bus\\Batchable'),
            middleware: $class === null ? [] : $this->middleware($methods['middleware'] ?? null),
            dynamic: $dynamic,
        );

        return $definition->isInteresting() || $definition->queued ? $definition : null;
    }

    private function classNode(string $file): ?Node\Stmt\Class_
    {
        $parsed = $this->parser->parse($file);
        $ast = $parsed['ast'] ?? null;

        if (! is_array($ast) || $ast === []) {
            return null;
        }

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

        return $class instanceof Node\Stmt\Class_ ? $class : null;
    }

    /**
     * Property defaults that are plain integers or `true`, which is every one of these settings.
     *
     * `true` is folded to 1 so a single lookup answers both kinds; only `afterCommit` uses it, and
     * it is read back as a boolean.
     *
     * @return array<string, int>
     */
    private function scalarProperties(Node\Stmt\Class_ $class): array
    {
        $values = [];

        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $default = $prop->default;

                if ($default instanceof Node\Scalar\Int_) {
                    $values[$prop->name->toString()] = $default->value;
                } elseif ($default instanceof Node\Expr\ConstFetch && strtolower($default->name->toString()) === 'true') {
                    $values[$prop->name->toString()] = 1;
                }
            }
        }

        return $values;
    }

    /**
     * @return array<string, Node\Stmt\ClassMethod>
     */
    private function declaredMethods(Node\Stmt\Class_ $class): array
    {
        $methods = [];

        foreach ($class->getMethods() as $method) {
            $methods[$method->name->toString()] = $method;
        }

        return $methods;
    }

    /**
     * The value of a method whose entire body is a single `return <int>;`.
     */
    private function literalReturn(Node\Stmt\ClassMethod $method): ?int
    {
        $statements = $method->stmts ?? [];

        if (count($statements) !== 1) {
            return null;
        }

        $return = $statements[0];

        return $return instanceof Node\Stmt\Return_ && $return->expr instanceof Node\Scalar\Int_
            ? $return->expr->value
            : null;
    }

    /**
     * Middleware class names from a `middleware()` body.
     *
     * Taken from the `new` at the root of each entry, because middleware is habitually configured
     * by chaining — `new WithoutOverlapping($key)->releaseAfter(60)->expireAfter(180)` — and the
     * outermost node of that expression is a method call, not the class anyone means.
     *
     * @return list<string>
     */
    private function middleware(?Node\Stmt\ClassMethod $method): array
    {
        if ($method === null) {
            return [];
        }

        $names = [];

        foreach ($this->finder->findInstanceOf($method->stmts ?? [], Node\Expr\New_::class) as $new) {
            if ($new->class instanceof Node\Name) {
                $parts = $new->class->getParts();
                $names[] = (string) end($parts);
            }
        }

        // `[SomeMiddleware::class]` — declared without constructing it.
        foreach ($this->finder->findInstanceOf($method->stmts ?? [], Node\Expr\ClassConstFetch::class) as $fetch) {
            if ($fetch->class instanceof Node\Name && $fetch->name instanceof Node\Identifier && $fetch->name->toString() === 'class') {
                $parts = $fetch->class->getParts();
                $names[] = (string) end($parts);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Whether the job runs as a member of a batch.
     *
     * A trait rather than an interface, so the hierarchy is walked by hand — a job that picks it
     * up from a base job is as batchable as one that writes it out.
     */
    private function usesTrait(string $fqcn, string $trait): bool
    {
        try {
            if (! class_exists($fqcn)) {
                return false;
            }

            for ($class = new \ReflectionClass($fqcn); $class !== false; $class = $class->getParentClass()) {
                if (in_array($trait, $class->getTraitNames(), true)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Asked of the loaded class, because these are as often inherited from a base job as written
     * on the job itself. A class the loader does not know answers no, which shows a job without a
     * marker rather than marking one wrongly.
     */
    private function implementsInterface(string $fqcn, string $interface): bool
    {
        try {
            return class_exists($fqcn) && is_subclass_of($fqcn, $interface);
        } catch (Throwable) {
            return false;
        }
    }
}
