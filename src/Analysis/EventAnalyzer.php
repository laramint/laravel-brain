<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Throwable;

/**
 * Every event class the application defines, whether or not anything listens to it.
 *
 * Nothing looked for these before. The graph knew an event existed when a traced call chain
 * happened to dispatch one, which is a quarter of them: measured on the application this was
 * built against, **179 event classes in the source against 45 the graph had heard of**. An event
 * nobody dispatches from a route is still an event, and an event nobody listens to is the most
 * interesting one on the list.
 *
 * Discovery is by directory, the same convention Laravel's own generator writes to, and
 * configurable for applications that keep them elsewhere. Being under `Events/` is treated as the
 * declaration it is — a class there is an event because its author filed it as one — rather than
 * inferred from a name or from an interface, since events implement no common one.
 */
class EventAnalyzer
{
    /** @var list<string> */
    public const DEFAULT_PATHS = ['app/Events'];

    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var list<string> */
    public const DEFAULT_LISTENER_PATHS = ['app/Listeners'];

    /**
     * @param  list<string>  $paths
     * @param  list<string>  $listenerPaths
     */
    public function __construct(
        private readonly array $paths = self::DEFAULT_PATHS,
        private readonly array $listenerPaths = self::DEFAULT_LISTENER_PATHS,
    ) {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
    }

    /**
     * @return array<string, EventDefinition> keyed by FQCN
     */
    public function analyze(string $projectRoot): array
    {
        $events = [];

        foreach (SourceDirectories::phpFiles($projectRoot, $this->paths) as $file) {
            $definition = $this->read($file);

            if ($definition !== null) {
                $events[$definition->fqcn] = $definition;
            }
        }

        return $events;
    }

    private function read(string $file): ?EventDefinition
    {
        $parsed = $this->parser->parse($file);
        $ast = $parsed['ast'] ?? null;

        if (! is_array($ast) || $ast === []) {
            return null;
        }

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

        if (! $class instanceof Node\Stmt\Class_ || $class->name === null || $class->isAbstract()) {
            return null;
        }

        $namespace = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
        $fqcn = $namespace?->name !== null
            ? $namespace->name->toString().'\\'.$class->name->toString()
            : $class->name->toString();

        return new EventDefinition(
            fqcn: $fqcn,
            file: $file,
            deferred: $this->implementsInterface($fqcn, ShouldDispatchAfterCommit::class),
            broadcast: $this->implementsInterface($fqcn, ShouldBroadcast::class),
            properties: $this->publicProperties($class),
        );
    }

    /**
     * Asked of the loaded class rather than read off the `implements` clause, because both of
     * these interfaces are as often inherited from a base event as written on the class itself.
     * A class the loader does not know answers no, which is the safe direction: the consequence
     * is an event shown without a marker, not one wrongly marked as safe.
     */
    private function implementsInterface(string $fqcn, string $interface): bool
    {
        try {
            return class_exists($fqcn) && is_subclass_of($fqcn, $interface);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The fields a consumer can actually read.
     *
     * Public only, and promoted constructor properties count — that is how nearly every modern
     * event carries its payload, and a listener can branch on nothing else.
     *
     * @return list<string>
     */
    private function publicProperties(Node\Stmt\Class_ $class): array
    {
        $properties = [];

        foreach ($class->getProperties() as $property) {
            if (! $property->isPublic()) {
                continue;
            }

            foreach ($property->props as $prop) {
                $properties[] = $prop->name->toString();
            }
        }

        $constructor = $class->getMethod('__construct');

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                $promoted = ($param->flags & Modifiers::PUBLIC) !== 0;

                if ($promoted && $param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $properties[] = $param->var->name;
                }
            }
        }

        return array_values(array_unique($properties));
    }

    /**
     * The second hop: events a listener dispatches while handling one.
     *
     * This is what separates a choreography from a list of subscriptions. A listener that fires an
     * event of its own extends the chain, and a chain that returns to an event it already visited
     * is a cycle — the failure mode that makes an event system hard to reason about, and one no
     * single subscription can show.
     *
     * Read from the listener directories rather than from traced call chains, for the same reason
     * events are: the tracer only reaches what a route walks into. Measured on the application
     * this was built against, that is 45 of 179 events — a chain hanging off a queued listener,
     * which is where choreography actually lives, is never on a route's path at all.
     *
     * @param  array<string, EventDefinition>  $events  the known events, so only real ones are linked
     * @return array<string, list<string>> listener FQCN => event FQCNs it dispatches
     */
    public function firedBy(string $projectRoot, array $events): array
    {
        $fired = [];

        foreach (SourceDirectories::phpFiles($projectRoot, $this->listenerPaths) as $file) {
            $parsed = $this->parser->parse($file);
            $ast = $parsed['ast'] ?? null;

            if (! is_array($ast) || $ast === []) {
                continue;
            }

            $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);

            if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
                continue;
            }

            $namespace = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Namespace_::class);
            $listenerFqcn = $namespace?->name !== null
                ? $namespace->name->toString().'\\'.$class->name->toString()
                : $class->name->toString();

            $dispatched = [];

            /** @var array<string, string> $useMap */
            $useMap = is_array($parsed['useMap'] ?? null) ? $parsed['useMap'] : [];

            foreach ($this->finder->findInstanceOf($ast, Node\Expr\New_::class) as $new) {
                $fqcn = $this->resolveClassName($new->class, $useMap, $namespace?->name?->toString() ?? '');

                // Only classes already known to be events. Constructing something is not
                // dispatching it, and guessing from the name would link every value object the
                // listener happens to build.
                if ($fqcn !== null && isset($events[$fqcn])) {
                    $dispatched[] = $fqcn;
                }
            }

            if ($dispatched !== []) {
                $fired[$listenerFqcn] = array_values(array_unique($dispatched));
            }
        }

        return $fired;
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function resolveClassName(Node $class, array $useMap, string $namespace): ?string
    {
        if (! $class instanceof Node\Name) {
            return null;
        }

        $name = $class->toString();

        if ($class->isFullyQualified()) {
            return ltrim($name, '\\');
        }

        $root = explode('\\', $name)[0];

        if (isset($useMap[$root])) {
            $suffix = substr($name, strlen($root));

            return ltrim($useMap[$root].$suffix, '\\');
        }

        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    /**
     * Event classes named by a set of listener edges, so an event kept outside the configured
     * directories still appears once something listens to it.
     *
     * @param  CallChainEdge[]  $listenerEdges
     * @return list<string>
     */
    public static function fqcnsFrom(array $listenerEdges): array
    {
        $fqcns = [];

        foreach ($listenerEdges as $edge) {
            if ($edge->type === 'listener' && $edge->callerFqcn !== '') {
                $fqcns[] = ltrim($edge->callerFqcn, '\\');
            }
        }

        return array_values(array_unique($fqcns));
    }
}
