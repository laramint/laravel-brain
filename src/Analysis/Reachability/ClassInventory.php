<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis\Reachability;

use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Every class, interface, trait and enum declared under the configured source paths.
 *
 * This is the "what exists" side of the reachability question. The graph answers the other
 * side — what a traced call chain arrives at — and the two are only comparable because both
 * are keyed by FQCN.
 */
final class ClassInventory
{
    /**
     * Kinds whose members the call tracer has no edge type for, so their absence from the
     * graph is the expected outcome and carries no information at all.
     *
     * A service provider is booted by the framework, never called; an exception is thrown and
     * caught, and `throw new Foo` is not a call hop. Listing those beside "17 jobs nothing
     * dispatches" would bury the finding under a hundred non-findings, which is how an
     * inventory teaches people to stop reading it.
     *
     * @var list<string>
     */
    public const TRACER_BLIND_KINDS = ['service_provider', 'exception'];

    /**
     * @param  array<string, DeclaredClass>  $classes  FQCN => declaration
     */
    private function __construct(private array $classes) {}

    /**
     * @param  array<string, DeclaredClass>  $classes
     */
    public static function of(array $classes): self
    {
        return new self($classes);
    }

    /**
     * @param  string[]  $sourcePaths  directories or glob patterns, relative to the project root
     */
    public static function scan(string $projectRoot, array $sourcePaths): self
    {
        $directories = SourceDirectories::resolve($projectRoot, $sourcePaths);
        $parser = new PhpFileParser;
        $classes = [];

        foreach (SourceDirectories::phpFiles($projectRoot, $directories) as $file) {
            foreach (self::declarationsIn($parser, $file) as $declared) {
                // First declaration wins, matching the by-file-name lookup every other
                // analyzer falls back to: a duplicated FQCN is a broken autoloader, and
                // guessing differently here would only disagree with the rest of the build.
                $classes[$declared->fqcn] ??= $declared;
            }
        }

        return new self($classes);
    }

    /**
     * @return array<string, DeclaredClass>
     */
    public function all(): array
    {
        return $this->classes;
    }

    public function get(string $fqcn): ?DeclaredClass
    {
        return $this->classes[$fqcn] ?? null;
    }

    public static function isTracerBlind(string $kind): bool
    {
        return in_array($kind, self::TRACER_BLIND_KINDS, true);
    }

    /**
     * The group a class is filed under.
     *
     * The first four names come from the declaration itself; the rest are the same
     * name-shape heuristics {@see GraphBuilder} classifies a
     * traced hop with, in the same precedence order, so a group in this tab is named after
     * the node type the class would carry anywhere else in the graph.
     *
     * It goes further than GraphBuilder does in one direction only: middleware, providers,
     * policies, observers, commands and exceptions get their own names. GraphBuilder never
     * needs them — it reaches those classes through a dedicated edge that already knows what
     * they are — but a report has to group by something, and folding six recognisable kinds
     * into one bucket labelled "service (312)" answers nothing.
     */
    public static function kindOf(string $fqcn, string $surface): string
    {
        if ($surface !== 'class') {
            return $surface;
        }

        // Checked before the \Http\ heuristics below, which would otherwise claim
        // middleware and API resources as controllers.
        if (str_contains($fqcn, '\\Http\\Resources\\')) {
            return 'resource';
        }
        if (str_contains($fqcn, '\\Middleware\\') || str_ends_with($fqcn, 'Middleware')) {
            return 'middleware';
        }
        if (str_ends_with($fqcn, 'ServiceProvider') || str_contains($fqcn, '\\Providers\\')) {
            return 'service_provider';
        }
        if (str_contains($fqcn, '\\Exceptions\\') || str_ends_with($fqcn, 'Exception')) {
            return 'exception';
        }
        if (str_contains($fqcn, '\\Console\\Commands\\') || str_ends_with($fqcn, 'Command')) {
            return 'command';
        }
        if (str_contains($fqcn, '\\Policies\\') || str_ends_with($fqcn, 'Policy')) {
            return 'policy';
        }
        if (str_contains($fqcn, '\\Observers\\') || str_ends_with($fqcn, 'Observer')) {
            return 'observer';
        }
        if (str_contains($fqcn, 'Controller') || str_contains($fqcn, '\\Http\\') || str_contains($fqcn, '\\Livewire\\')) {
            return 'controller';
        }
        if (str_contains($fqcn, '\\Mail\\') || str_ends_with($fqcn, 'Mail') || str_ends_with($fqcn, 'Mailable')) {
            return 'mail';
        }
        if (str_contains($fqcn, '\\Notifications\\') || str_ends_with($fqcn, 'Notification')) {
            return 'notification';
        }
        if (str_contains($fqcn, '\\Listeners\\')) {
            return 'listener';
        }
        if (str_contains($fqcn, 'Repository') || str_contains($fqcn, '\\Repositories\\')) {
            return 'repository';
        }
        if (str_contains($fqcn, 'Job') || str_contains($fqcn, '\\Jobs\\')) {
            return 'job';
        }
        if (str_contains($fqcn, 'Event') || str_contains($fqcn, '\\Events\\')) {
            return 'event';
        }
        if (str_contains($fqcn, '\\Models\\') || str_contains($fqcn, '\\Model\\')) {
            return 'model';
        }

        return 'service';
    }

    /**
     * @return list<DeclaredClass>
     */
    private static function declarationsIn(PhpFileParser $parser, string $file): array
    {
        $parsed = $parser->parse($file);
        if ($parsed['ast'] === null) {
            return [];
        }

        $visitor = new class($file) extends NodeVisitorAbstract
        {
            /** @var list<DeclaredClass> */
            public array $found = [];

            private string $namespace = '';

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name !== null ? $node->name->toString() : '';

                    return null;
                }

                if (! $node instanceof Node\Stmt\ClassLike || $node->name === null) {
                    // An anonymous class has no name to be reached *by*, so it cannot be
                    // unreachable in the sense this report means.
                    return null;
                }

                $fqcn = $this->namespace !== ''
                    ? $this->namespace.'\\'.$node->name->toString()
                    : $node->name->toString();

                $surface = match (true) {
                    $node instanceof Node\Stmt\Interface_ => 'interface',
                    $node instanceof Node\Stmt\Trait_ => 'trait',
                    $node instanceof Node\Stmt\Enum_ => 'enum',
                    $node instanceof Node\Stmt\Class_ && $node->isAbstract() => 'abstract_class',
                    default => 'class',
                };

                $this->found[] = new DeclaredClass(
                    fqcn: $fqcn,
                    file: $this->file,
                    surface: $surface,
                    kind: ClassInventory::kindOf($fqcn, $surface),
                    parent: $node instanceof Node\Stmt\Class_
                        ? (PhpFileParser::resolvedName($node->extends) ?? '')
                        : '',
                    interfaces: self::implementedNames($node),
                    traits: self::usedTraitNames($node),
                );

                return null;
            }

            /**
             * @return list<string>
             */
            private static function implementedNames(Node\Stmt\ClassLike $node): array
            {
                $names = [];
                $clause = match (true) {
                    $node instanceof Node\Stmt\Class_ => $node->implements,
                    $node instanceof Node\Stmt\Enum_ => $node->implements,
                    $node instanceof Node\Stmt\Interface_ => $node->extends,
                    default => [],
                };
                foreach ($clause as $name) {
                    $resolved = PhpFileParser::resolvedName($name);
                    if ($resolved !== null) {
                        $names[] = $resolved;
                    }
                }

                return $names;
            }

            /**
             * @return list<string>
             */
            private static function usedTraitNames(Node\Stmt\ClassLike $node): array
            {
                $names = [];
                foreach ($node->stmts as $stmt) {
                    if (! $stmt instanceof Node\Stmt\TraitUse) {
                        continue;
                    }
                    foreach ($stmt->traits as $name) {
                        $resolved = PhpFileParser::resolvedName($name);
                        if ($resolved !== null) {
                            $names[] = $resolved;
                        }
                    }
                }

                return $names;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return $visitor->found;
    }
}
