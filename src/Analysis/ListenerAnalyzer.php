<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Links dispatched events to the listeners that handle them.
 *
 * Edges are discovered from every way Laravel wires a listener to an event:
 *
 *  - by convention   — a class under the listener directories whose `handle()`
 *                      or `__invoke()` type-hints an event in its first parameter;
 *  - by attribute    — a class or method marked `#[AsEventListener]`, with the
 *                      event taken from the attribute or the method's first parameter;
 *  - by `$listen`     — the `EventServiceProvider::$listen` map of event => listeners;
 *  - by `$subscribe`  — a subscriber listed in `$subscribe`, whose `subscribe()`
 *                      method registers handlers via `$events->listen(...)` or a
 *                      returned `[event => method]` map.
 *
 * Each pairing becomes one event → listener CallChainEdge, so the graph can
 * answer "what runs when this event is dispatched", regardless of how the
 * listener was registered.
 */
class ListenerAnalyzer
{
    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] directories (relative to project root) scanned for listeners */
    private array $listenerPaths;

    /** @var string[] provider directories (relative to project root) scanned for listen/subscribe registrations */
    private array $providerPaths;

    /** @var array<string, string[]> namespace prefix => base paths, used to locate subscriber files */
    private array $psr4Map = [];

    /**
     * @param  string[]  $listenerPaths
     * @param  string[]  $providerPaths
     */
    public function __construct(array $listenerPaths = ['app/Listeners'], array $providerPaths = ['app/Providers'])
    {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->listenerPaths = $listenerPaths !== [] ? $listenerPaths : ['app/Listeners'];
        $this->providerPaths = $providerPaths !== [] ? $providerPaths : ['app/Providers'];
    }

    /**
     * @param  array<string, string[]>  $psr4Map  namespace prefix => base paths (locates $subscribe classes)
     * @return CallChainEdge[] event → listener edges
     */
    public function analyze(string $projectRoot, array $psr4Map = []): array
    {
        $this->psr4Map = $psr4Map;
        $edges = [];
        foreach ($this->listenerFiles($projectRoot) as $file) {
            array_push($edges, ...$this->edgesFromListenerFile($file));
        }
        foreach ($this->providerFiles($projectRoot) as $file) {
            array_push($edges, ...$this->edgesFromProviderFile($file, $projectRoot));
        }

        return $this->dedupe($edges);
    }

    /**
     * Convention + attribute discovery for a single listener class file.
     *
     * @return CallChainEdge[]
     */
    private function edgesFromListenerFile(string $file): array
    {
        $context = $this->context($file);
        if ($context === null) {
            return [];
        }
        [$ast, $useMap, $namespace] = $context;

        $class = $this->finder->findFirstInstanceOf($ast, Node\Stmt\Class_::class);
        if (! $class instanceof Node\Stmt\Class_ || $class->name === null) {
            return [];
        }
        $listenerFqcn = $this->qualify($class->name->toString(), $namespace);

        $edges = [];

        // Convention: handle()/__invoke() whose first parameter type-hints the event.
        foreach ($class->getMethods() as $method) {
            $name = $method->name->toString();
            if ($name !== 'handle' && $name !== '__invoke') {
                continue;
            }
            $event = $this->paramEvent($method, $useMap, $namespace);
            if ($event !== null) {
                $edges[] = $this->edge($event, $listenerFqcn, $name);
            }
        }

        // Attribute: #[AsEventListener] on the class, or on individual methods.
        // A class-level attribute names its event explicitly; without one there is
        // no method to infer from, so the convention pass above already covers it.
        foreach ($this->attributeEvents($class->attrGroups, $useMap, $namespace) as [$event, $method]) {
            if ($event !== null) {
                $edges[] = $this->edge($event, $listenerFqcn, $method ?? 'handle');
            }
        }
        foreach ($class->getMethods() as $method) {
            foreach ($this->attributeEvents($method->attrGroups, $useMap, $namespace) as [$event, $named]) {
                $resolved = $event ?? $this->paramEvent($method, $useMap, $namespace);
                if ($resolved !== null) {
                    $edges[] = $this->edge($resolved, $listenerFqcn, $named ?? $method->name->toString());
                }
            }
        }

        return $edges;
    }

    /**
     * $listen + $subscribe discovery for a single service-provider file.
     *
     * @return CallChainEdge[]
     */
    private function edgesFromProviderFile(string $file, string $projectRoot): array
    {
        $context = $this->context($file);
        if ($context === null) {
            return [];
        }
        [$ast, $useMap, $namespace] = $context;

        $edges = [];
        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\Property::class) as $property) {
            foreach ($property->props as $prop) {
                $default = $prop->default;
                if (! $default instanceof Node\Expr\Array_) {
                    continue;
                }
                if ($prop->name->toString() === 'listen') {
                    array_push($edges, ...$this->edgesFromListenMap($default, $useMap, $namespace));
                }
                if ($prop->name->toString() === 'subscribe') {
                    array_push($edges, ...$this->edgesFromSubscribeList($default, $useMap, $namespace, $projectRoot));
                }
            }
        }

        return $edges;
    }

    /**
     * Parse an `EventServiceProvider::$listen` map: event => [listeners].
     *
     * @return CallChainEdge[]
     */
    private function edgesFromListenMap(Node\Expr\Array_ $map, array $useMap, string $namespace): array
    {
        $edges = [];
        foreach ($map->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem || $item->key === null) {
                continue;
            }
            $event = $this->classRef($item->key, $useMap, $namespace);
            if ($event === null) {
                continue;
            }
            // Listeners may be an array (the common form) or a single class — Laravel allows both.
            $listenerValues = [];
            if ($item->value instanceof Node\Expr\Array_) {
                foreach ($item->value->items as $listener) {
                    if ($listener instanceof Node\Expr\ArrayItem) {
                        $listenerValues[] = $listener->value;
                    }
                }
            } else {
                $listenerValues[] = $item->value;
            }
            foreach ($listenerValues as $value) {
                $ref = $this->listenerRef($value, $useMap, $namespace, null);
                if ($ref !== null) {
                    $edges[] = $this->edge($event, $ref[0], $ref[1]);
                }
            }
        }

        return $edges;
    }

    /**
     * Parse an `$subscribe` list and each named subscriber's `subscribe()` method.
     *
     * @return CallChainEdge[]
     */
    private function edgesFromSubscribeList(Node\Expr\Array_ $list, array $useMap, string $namespace, string $projectRoot): array
    {
        $edges = [];
        foreach ($list->items as $item) {
            if (! $item instanceof Node\Expr\ArrayItem) {
                continue;
            }
            $subscriber = $this->classRef($item->value, $useMap, $namespace);
            if ($subscriber !== null) {
                array_push($edges, ...$this->edgesFromSubscriber($subscriber, $projectRoot));
            }
        }

        return $edges;
    }

    /**
     * Resolve a subscriber class to its file and read the event → handler
     * pairings registered inside its `subscribe()` method.
     *
     * @return CallChainEdge[]
     */
    private function edgesFromSubscriber(string $subscriberFqcn, string $projectRoot): array
    {
        $file = $this->resolveClassFile($subscriberFqcn, $projectRoot);
        if ($file === null) {
            return [];
        }
        $context = $this->context($file);
        if ($context === null) {
            return [];
        }
        [$ast, $useMap, $namespace] = $context;

        $subscribe = null;
        foreach ($this->finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class) as $method) {
            if ($method->name->toString() === 'subscribe') {
                $subscribe = $method;
                break;
            }
        }
        if ($subscribe === null) {
            return [];
        }

        $edges = [];

        // Imperative form: $events->listen(Event::class, handler).
        foreach ($this->finder->findInstanceOf($subscribe, Node\Expr\MethodCall::class) as $call) {
            if (! $call->name instanceof Node\Identifier || $call->name->toString() !== 'listen') {
                continue;
            }
            $args = $call->getArgs();
            $event = isset($args[0]) ? $this->classRef($args[0]->value, $useMap, $namespace) : null;
            if ($event === null) {
                continue;
            }
            $ref = isset($args[1]) ? $this->listenerRef($args[1]->value, $useMap, $namespace, $subscriberFqcn) : null;
            if ($ref !== null) {
                $edges[] = $this->edge($event, $ref[0], $ref[1]);
            }
        }

        // Return-map form: return [Event::class => 'method' | [self::class, 'method']].
        foreach ($this->finder->findInstanceOf($subscribe, Node\Stmt\Return_::class) as $return) {
            if (! $return->expr instanceof Node\Expr\Array_) {
                continue;
            }
            foreach ($return->expr->items as $item) {
                if (! $item instanceof Node\Expr\ArrayItem || $item->key === null) {
                    continue;
                }
                $event = $this->classRef($item->key, $useMap, $namespace);
                $ref = $event === null ? null : $this->listenerRef($item->value, $useMap, $namespace, $subscriberFqcn);
                if ($event !== null && $ref !== null) {
                    $edges[] = $this->edge($event, $ref[0], $ref[1]);
                }
            }
        }

        return $edges;
    }

    /**
     * Resolve a listener reference into [classFqcn, method].
     *
     * Accepts `Listener::class`, `[Listener::class, 'method']`, `'Listener@method'`,
     * a bare `'method'` string (handler on $defaultClass), or `self::class`.
     *
     * @return array{0: string, 1: string}|null
     */
    private function listenerRef(Node\Expr $value, array $useMap, string $namespace, ?string $defaultClass): ?array
    {
        if ($value instanceof Node\Expr\Array_) {
            $items = array_values(array_filter(
                $value->items,
                static fn ($i): bool => $i instanceof Node\Expr\ArrayItem,
            ));
            $class = isset($items[0]) ? $this->classRef($items[0]->value, $useMap, $namespace, $defaultClass) : null;
            $method = isset($items[1]) && $items[1]->value instanceof Node\Scalar\String_
                ? $items[1]->value->value
                : 'handle';

            return $class === null ? null : [$class, $method];
        }

        if ($value instanceof Node\Scalar\String_) {
            if (str_contains($value->value, '@')) {
                [$class, $method] = explode('@', $value->value, 2);

                return [$this->qualify($class, $namespace, $useMap), $method];
            }
            // A bare string is a method name on the subscriber, else a listener class.
            if ($defaultClass !== null && ! str_contains($value->value, '\\')) {
                return [$defaultClass, $value->value];
            }

            return [$this->qualify($value->value, $namespace, $useMap), 'handle'];
        }

        $class = $this->classRef($value, $useMap, $namespace, $defaultClass);

        return $class === null ? null : [$class, 'handle'];
    }

    /**
     * Resolve `Event::class`, `self::class`, or a string literal to an FQCN.
     */
    private function classRef(Node\Expr $expr, array $useMap, string $namespace, ?string $self = null): ?string
    {
        if ($expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toString() === 'class') {
            $name = $expr->class->toString();
            if ($self !== null && ($name === 'self' || $name === 'static')) {
                return $self;
            }

            return $this->qualify($name, $namespace, $useMap);
        }

        if ($expr instanceof Node\Scalar\String_) {
            return ltrim($expr->value, '\\');
        }

        return null;
    }

    /**
     * Read the event/method pairs declared by `#[AsEventListener]` attributes.
     *
     * @param  Node\AttributeGroup[]  $groups
     * @return list<array{0: ?string, 1: ?string}> [eventFqcn|null, method|null]
     */
    private function attributeEvents(array $groups, array $useMap, string $namespace): array
    {
        $found = [];
        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->getLast() !== 'AsEventListener') {
                    continue;
                }
                $event = null;
                $method = null;
                $positional = 0;
                foreach ($attr->args as $arg) {
                    $key = $arg->name?->toString();
                    if ($key === 'event' || ($key === null && $positional === 0)) {
                        $event = $this->classRef($arg->value, $useMap, $namespace);
                    } elseif (($key === 'method' || ($key === null && $positional === 1)) && $arg->value instanceof Node\Scalar\String_) {
                        $method = $arg->value->value;
                    }
                    if ($key === null) {
                        $positional++;
                    }
                }
                $found[] = [$event, $method];
            }
        }

        return $found;
    }

    private function paramEvent(Node\Stmt\ClassMethod $method, array $useMap, string $namespace): ?string
    {
        $param = $method->params[0] ?? null;
        if ($param !== null && $param->type instanceof Node\Name) {
            return $this->qualify($param->type->toString(), $namespace, $useMap);
        }

        return null;
    }

    /**
     * Resolve a (possibly short or aliased) class name to an FQCN.
     *
     * @param  array<string, string>  $useMap
     */
    private function qualify(string $name, string $namespace, array $useMap = []): string
    {
        $name = ltrim($name, '\\');
        if (isset($useMap[$name])) {
            return $useMap[$name];
        }
        $head = strtok($name, '\\');
        if ($head !== false && $head !== $name && isset($useMap[$head])) {
            return $useMap[$head].substr($name, strlen($head));
        }
        if (str_contains($name, '\\')) {
            return $name;
        }

        return $namespace !== '' ? $namespace.'\\'.$name : $name;
    }

    /**
     * @return array{0: Node\Stmt[], 1: array<string, string>, 2: string}|null [ast, useMap, namespace]
     */
    private function context(string $file): ?array
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }
        $namespaceNode = $this->finder->findFirstInstanceOf($parsed['ast'], Node\Stmt\Namespace_::class);
        $namespace = $namespaceNode instanceof Node\Stmt\Namespace_ && $namespaceNode->name !== null
            ? $namespaceNode->name->toString()
            : '';

        return [$parsed['ast'], $parsed['useMap'], $namespace];
    }

    private function edge(string $eventFqcn, string $listenerFqcn, string $method): CallChainEdge
    {
        return new CallChainEdge(
            callerFqcn: $eventFqcn,
            callerMethod: '__construct',
            calleeFqcn: $listenerFqcn,
            calleeMethod: $method,
            type: 'listener',
        );
    }

    /**
     * @param  CallChainEdge[]  $edges
     * @return CallChainEdge[]
     */
    private function dedupe(array $edges): array
    {
        $unique = [];
        foreach ($edges as $edge) {
            $key = "{$edge->callerFqcn}|{$edge->calleeFqcn}|{$edge->calleeMethod}";
            $unique[$key] = $edge;
        }

        return array_values($unique);
    }

    /**
     * @return iterable<string>
     */
    private function listenerFiles(string $projectRoot): iterable
    {
        return $this->phpFiles($this->listenerPaths, $projectRoot);
    }

    /**
     * @return iterable<string>
     */
    private function providerFiles(string $projectRoot): iterable
    {
        return $this->phpFiles($this->providerPaths, $projectRoot);
    }

    /**
     * @param  string[]  $relativePaths
     * @return iterable<string>
     */
    private function phpFiles(array $relativePaths, string $projectRoot): iterable
    {
        foreach ($relativePaths as $relativePath) {
            $basePath = rtrim($projectRoot, '/').'/'.ltrim($relativePath, '/');
            if (! is_dir($basePath)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
                    yield $fileInfo->getPathname();
                }
            }
        }
    }

    private function resolveClassFile(string $fqcn, string $projectRoot): ?string
    {
        // Prefer the real PSR-4 map (handles non-App\ roots, packages, custom layouts).
        foreach ($this->psr4Map as $prefix => $basePaths) {
            if (str_starts_with($fqcn, $prefix)) {
                $relative = str_replace('\\', '/', substr($fqcn, strlen($prefix))).'.php';
                foreach ($basePaths as $basePath) {
                    $path = rtrim($basePath, '/').'/'.ltrim($relative, '/');
                    if (is_file($path)) {
                        return $path;
                    }
                }
            }
        }

        // Fallback for when no map was supplied: the conventional App\ => app/ root.
        $candidates = [];
        if (str_starts_with($fqcn, 'App\\')) {
            $candidates[] = 'app/'.str_replace('\\', '/', substr($fqcn, 4)).'.php';
        }
        $candidates[] = 'app/'.str_replace('\\', '/', $fqcn).'.php';
        $candidates[] = str_replace('\\', '/', $fqcn).'.php';

        foreach ($candidates as $candidate) {
            $path = rtrim($projectRoot, '/').'/'.$candidate;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
