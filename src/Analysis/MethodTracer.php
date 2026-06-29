<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpExtendsFqcnResolver;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Kept for backward-compatibility reference by other code. Not used by DeepTracer.
 */
class MethodTrace
{
    public function __construct(
        public string $controller,
        public string $method,
        public array $modelCalls,
        public array $serviceCalls,
        public array $eventDispatches,
        public array $jobDispatches,
    ) {}
}

/**
 * DeepTracer performs recursive lifecycle tracing starting from each controller action.
 *
 * It follows the full chain:
 *   Controller → Service → Repository → Model
 *                        → Job
 *                        → Event
 *
 * Each discovered hop becomes a CallChainEdge. The tracer uses a shared visited
 * guard to prevent infinite recursion in mutually-calling services.
 */
class MethodTracer
{
    /** Sentinel FQCN prefix for Blade views in call-chain edges (actual name: blade::{dot.notation}) */
    public const BLADE_FQCN_PREFIX = 'blade::';

    public const MODEL_STATIC_METHODS = [
        'where', 'whereIn', 'whereNotIn', 'find', 'findOrFail', 'findMany',
        'first', 'firstOrFail', 'firstOrCreate', 'firstOrNew',
        'create', 'updateOrCreate', 'all', 'count', 'sum', 'avg', 'max', 'min',
        'query', 'with', 'without', 'select', 'orderBy', 'groupBy', 'having',
        'paginate', 'simplePaginate', 'cursor', 'latest', 'oldest', 'inRandomOrder',
        'delete', 'forceDelete', 'update', 'insert', 'upsert', 'truncate',
        'withTrashed', 'onlyTrashed', 'save', 'push', 'touch', 'refresh',
    ];

    private PhpFileParser $parser;

    /** @var array<string, true>  "FQCN::method" already being traced (cycle guard) */
    private array $visited = [];

    /** @var array<string, array> FQCN => parsed class info cache */
    private array $classCache = [];

    private array $psr4Map = [];

    private string $projectRoot = '';

    private PhpStructureInspector $structureInspector;

    /** @var array<string, 'enum'|'interface'|'trait'|'abstract_class'|null> */
    private array $declKindCache = [];

    /** Marker hop type for a dispatch verb whose job argument can't be resolved statically. */
    public const UNRESOLVED_DISPATCH = 'unresolved-dispatch';

    /** @var string[] extra global dispatch helper names beyond dispatch()/dispatch_sync() */
    private array $extraDispatchHelpers;

    /** @var array<string, true> "FQCN::method" of methods with an unresolvable dispatch */
    private array $unresolvedDispatchers = [];

    /**
     * @param  string[]  $extraDispatchHelpers  custom dispatch helpers (e.g. dispatch_with_retries)
     *                                          that wrap a queued job, treated like dispatch().
     */
    public function __construct(array $extraDispatchHelpers = [])
    {
        $this->parser = new PhpFileParser;
        $this->structureInspector = new PhpStructureInspector($this->parser);
        $this->extraDispatchHelpers = array_values(array_filter(
            $extraDispatchHelpers,
            static fn ($name): bool => is_string($name) && $name !== '',
        ));
    }

    /**
     * Methods that dispatch a job Brain couldn't resolve statically (the job is a
     * variable, factory result, or other non-literal). Lets a consumer mark such a
     * dispatcher as "may reach unknown jobs" rather than "reaches none".
     *
     * @return string[] "FQCN::method"
     */
    public function unresolvedDispatchers(): array
    {
        return array_keys($this->unresolvedDispatchers);
    }

    private function recordUnresolvedDispatch(string $callerFqcn, string $callerMethod): void
    {
        if ($callerFqcn !== '' && $callerMethod !== '') {
            $this->unresolvedDispatchers[$callerFqcn.'::'.$callerMethod] = true;
        }
    }

    /**
     * Trace a single class method and return all discovered CallChainEdges.
     * Useful for tracing commands, channels, or any non-controller class.
     *
     * @return CallChainEdge[]
     */
    public function traceMethod(string $fqcn, string $method, array $psr4Map = [], string $projectRoot = ''): array
    {
        $this->psr4Map = $psr4Map;
        $this->projectRoot = $projectRoot;
        $this->visited = [];
        // Keep classCache across calls for efficiency when tracing many classes

        return $this->traceDeep($fqcn, $method, depth: 0);
    }

    /**
     * Trace an inline route closure and return all discovered CallChainEdges.
     *
     * Uses the same visitor as controller methods; discovered services/repos are recursed
     * up to the standard depth cap. The virtual $callerFqcn (e.g. "route::GET::/uri")
     * is written directly as the edge's callerFqcn so GraphBuilder can map it back to
     * the already-existing route node via nodeIdForHop().
     *
     * @param  array<string,string>  $useMap  Import map from the file where the closure is defined
     * @param  string  $callerFqcn  Virtual FQCN — "route::{METHOD}::{uri}"
     * @return CallChainEdge[]
     */
    public function traceClosure(
        Node\Expr\Closure|Node\Expr\ArrowFunction $closure,
        array $useMap,
        string $callerFqcn,
        array $psr4Map = [],
        string $projectRoot = ''
    ): array {
        $this->psr4Map = $psr4Map;
        $this->projectRoot = $projectRoot;

        $discovered = $this->scanMethod($closure, [], $useMap, $callerFqcn, null);

        $edges = [];
        foreach ($discovered as $hop) {
            if ($hop['type'] === self::UNRESOLVED_DISPATCH) {
                $this->recordUnresolvedDispatch($callerFqcn, '__invoke');

                continue;
            }
            $edges[] = new CallChainEdge(
                callerFqcn: $callerFqcn,
                callerMethod: '__invoke',
                calleeFqcn: $hop['fqcn'],
                calleeMethod: $hop['method'],
                type: $hop['type'],
                visibility: $hop['visibility'],
            );

            if (in_array($hop['type'], ['service', 'repository', 'action', 'mail', 'notification', 'abstract_class'], true)) {
                $subEdges = $this->traceDeep($hop['fqcn'], $hop['method'], depth: 1);
                foreach ($subEdges as $sub) {
                    $edges[] = $sub;
                }
            }
        }

        return $edges;
    }

    /**
     * @param  ControllerDefinition[]  $controllers  keyed by FQCN
     * @param  array<string,string[]>  $psr4Map  namespace prefix => list of base paths
     * @param  string  $projectRoot  root path for fallback file search
     * @return CallChainEdge[]
     */
    public function trace(array $controllers, array $psr4Map = [], string $projectRoot = ''): array
    {
        $this->psr4Map = $psr4Map;
        $this->projectRoot = $projectRoot;
        $this->visited = [];
        $this->classCache = [];
        // NB: unresolvedDispatchers is NOT reset here. trace() runs more than once per analysis
        // (controllers, then Filament pages), and like the returned edges the signal must
        // accumulate across every call for the tracer's lifetime, not be wiped by the second run.

        $edges = [];

        foreach ($controllers as $controller) {
            $allDeps = $controller->constructorDeps; // varName => FQCN

            foreach ($controller->methods as $methodDef) {
                if ($methodDef->ast === null) {
                    continue;
                }

                $deps = array_merge($allDeps, $methodDef->dependencies);

                $key = $controller->fqcn.'::'.$methodDef->name;
                $this->visited[$key] = true;

                $declaringFqcn = $methodDef->declaringFqcn ?? $controller->fqcn;
                $declaringInfo = $this->loadClass($declaringFqcn);
                $parentForLexical = $declaringInfo['parent'] ?? null;

                $discovered = $this->scanMethod(
                    $methodDef->ast,
                    $deps,
                    $methodDef->methodUseMap ?? $controller->useMap,
                    $declaringFqcn,
                    $parentForLexical,
                );

                foreach ($discovered as $hop) {
                    if ($hop['type'] === self::UNRESOLVED_DISPATCH) {
                        $this->recordUnresolvedDispatch($controller->fqcn, $methodDef->name);

                        continue;
                    }
                    $edges[] = new CallChainEdge(
                        callerFqcn: $controller->fqcn,
                        callerMethod: $methodDef->name,
                        calleeFqcn: $hop['fqcn'],
                        calleeMethod: $hop['method'],
                        type: $hop['type'],
                        visibility: $hop['visibility'],
                    );

                    // Recurse into non-leaf hops (services, repositories)
                    if (in_array($hop['type'], ['service', 'repository', 'action', 'mail', 'notification', 'abstract_class'], true)) {
                        $subEdges = $this->traceDeep(
                            $hop['fqcn'],
                            $hop['method'],
                            depth: 1,
                        );
                        foreach ($subEdges as $sub) {
                            $edges[] = $sub;
                        }
                    }
                }

                unset($this->visited[$key]);
            }
        }

        return $edges;
    }

    // ─── Private recursion ────────────────────────────────────────────────────

    /**
     * Recursively trace a class method, loading its AST from the PSR-4 map.
     *
     * @return CallChainEdge[]
     */
    private function traceDeep(string $fqcn, string $method, int $depth): array
    {
        if ($depth >= 5) {
            return [];
        } // hard recursion cap

        $key = $fqcn.'::'.$method;
        if (isset($this->visited[$key])) {
            return [];
        }
        $this->visited[$key] = true;

        if (str_starts_with($fqcn, self::BLADE_FQCN_PREFIX)) {
            return [];
        }

        $classInfo = $this->loadClass($fqcn);
        if ($classInfo === null) {
            return [];
        }

        $originalMethod = $method;
        $methodAst = $classInfo['methods'][$method] ?? null;
        if ($methodAst === null) {
            $method = $this->fallbackEntryMethod($fqcn, $method, $classInfo['methods']);
            $methodAst = $classInfo['methods'][$method] ?? null;
        }
        if ($methodAst === null) {
            // Method not defined in this class — transparently delegate to parent.
            $parentFqcn = $classInfo['parent'] ?? null;
            if (
                $parentFqcn !== null
                && ! str_starts_with($parentFqcn, 'Illuminate\\')
                && ! str_starts_with($parentFqcn, 'Laravel\\')
            ) {
                return $this->traceDeep($parentFqcn, $originalMethod, $depth);
            }

            return [];
        }

        $discovered = $this->scanMethod(
            $methodAst,
            $classInfo['deps'],
            $classInfo['useMap'],
            $fqcn,
            $classInfo['parent'] ?? null,
        );

        $edges = [];
        foreach ($discovered as $hop) {
            if ($hop['type'] === self::UNRESOLVED_DISPATCH) {
                $this->recordUnresolvedDispatch($fqcn, $method);

                continue;
            }
            $edges[] = new CallChainEdge(
                callerFqcn: $fqcn,
                callerMethod: $method,
                calleeFqcn: $hop['fqcn'],
                calleeMethod: $hop['method'],
                type: $hop['type'],
                visibility: $hop['visibility'],
            );

            if (in_array($hop['type'], ['service', 'repository', 'action', 'mail', 'notification', 'abstract_class'], true)) {
                $subEdges = $this->traceDeep($hop['fqcn'], $hop['method'], $depth + 1);
                foreach ($subEdges as $sub) {
                    $edges[] = $sub;
                }
            }
        }

        return $edges;
    }

    /**
     * Scan a single method AST and return all discovered hops as raw arrays:
     *   [ ['fqcn'=>..., 'method'=>..., 'type'=>...], ... ]
     */
    private function scanMethod(
        Node $ast,
        array $varTypeMap,
        array $useMap,
        string $currentFqcn,
        ?string $parentFqcn = null,
    ): array {
        $traverser = new NodeTraverser;

        $dispatchFunctions = array_values(array_unique(['dispatch', 'dispatch_sync', ...$this->extraDispatchHelpers]));

        $visitor = new class($varTypeMap, $useMap, $currentFqcn, $parentFqcn, $this, $dispatchFunctions) extends NodeVisitorAbstract
        {
            /** @var list<array{fqcn:string,method:string,type:string,visibility:string}> */
            public array $hops = [];

            private array $varTypeMap;

            private array $useMap;

            private string $currentFqcn;

            private ?string $parentFqcn;

            private const MODEL_NAMESPACES = ['App\\Models\\', 'App\\Model\\', 'Models\\'];

            private const EVENT_FUNCTIONS = ['event'];

            /** @var string[] global dispatch helpers: built-in plus any configured extras */
            private array $dispatchFunctions;

            /**
             * @param  string[]  $dispatchFunctions
             */
            public function __construct(
                array $varTypeMap,
                array $useMap,
                string $currentFqcn,
                ?string $parentFqcn,
                private MethodTracer $tracer,
                array $dispatchFunctions = ['dispatch', 'dispatch_sync'],
            ) {
                $this->varTypeMap = $varTypeMap;
                $this->useMap = $useMap;
                $this->currentFqcn = $currentFqcn;
                $this->parentFqcn = $parentFqcn;
                $this->dispatchFunctions = $dispatchFunctions;
            }

            private function markUnresolvedDispatch(): void
            {
                $this->hops[] = ['fqcn' => '', 'method' => '', 'type' => MethodTracer::UNRESOLVED_DISPATCH, 'visibility' => 'public'];
            }

            /**
             * True when a dispatch argument is a job we couldn't read statically (a variable, property,
             * or factory call) rather than nothing, or a string/array literal. A string/array first
             * argument means an event dispatch ($this->dispatch('saved', ...) in Livewire/Filament),
             * not a queued job, so it must not be reported as an unresolved job dispatch.
             */
            private function isOpaqueJobArg(?Node $arg): bool
            {
                $value = $arg instanceof Node\Arg ? $arg->value : $arg;

                return $value !== null
                    && ! $value instanceof Node\Scalar\String_
                    && ! $value instanceof Node\Expr\Array_;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Expr\StaticCall) {
                    $this->handleStaticCall($node);
                } elseif ($node instanceof Node\Expr\MethodCall) {
                    $this->handleMethodCall($node);
                } elseif ($node instanceof Node\Expr\FuncCall) {
                    $this->handleFuncCall($node);
                } elseif ($node instanceof Node\Expr\New_) {
                    $this->handleNew($node);
                } elseif ($node instanceof Node\Expr\Assign) {
                    $this->handleAssign($node);
                } elseif ($node instanceof Node\Expr\ClassConstFetch) {
                    $this->handleClassConstFetch($node);
                }

                return null;
            }

            // ── Static calls: Model::find(), Job::dispatch(), Event::dispatch() ──

            private function handleStaticCall(Node\Expr\StaticCall $node): void
            {
                if (! $node->class instanceof Node\Name) {
                    return;
                }
                $class = $node->class->toString();
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                if ($method === null) {
                    return;
                }

                $lowerClass = strtolower($class);
                if ($lowerClass === 'self' || $lowerClass === 'static') {
                    $fqcn = $this->currentFqcn;
                } elseif ($lowerClass === 'parent') {
                    if ($this->parentFqcn === null) {
                        return;
                    }
                    $fqcn = $this->parentFqcn;
                } else {
                    $fqcn = $this->useMap[$class] ?? $class;
                }

                // Job::dispatch()
                if ($method === 'dispatch' && $this->looksLikeJob($fqcn)) {
                    $this->hops[] = ['fqcn' => $fqcn, 'method' => 'handle', 'type' => 'job', 'visibility' => 'public'];

                    return;
                }

                // Event::dispatch() facade
                if (in_array($class, ['Event', 'Illuminate\\Support\\Facades\\Event'], true) && $method === 'dispatch') {
                    $eventClass = $this->extractNewClass($node->args[0] ?? null);
                    if ($eventClass) {
                        $this->hops[] = [
                            'fqcn' => $this->useMap[$eventClass] ?? $eventClass,
                            'method' => '__construct',
                            'type' => 'event',
                            'visibility' => 'public',
                        ];
                    }

                    return;
                }

                // Bus facade: Bus::dispatch(new Job) / Bus::dispatchSync(new Job)
                // and the array forms Bus::chain([new A, new B]) / Bus::batch([new A, new B]).
                if (in_array($class, ['Bus', 'Illuminate\\Support\\Facades\\Bus'], true)
                    && in_array($method, ['dispatch', 'dispatchSync', 'chain', 'batch'], true)) {
                    $arg = $node->args[0] ?? null;
                    $isGroup = in_array($method, ['chain', 'batch'], true);
                    $jobClasses = $isGroup
                        ? $this->extractNewClassesFromArray($arg)
                        : array_filter([$this->extractNewClass($arg)]);

                    foreach ($jobClasses as $jobClass) {
                        $this->hops[] = [
                            'fqcn' => $this->useMap[$jobClass] ?? $jobClass,
                            'method' => 'handle',
                            'type' => 'job',
                            'visibility' => 'public',
                        ];
                    }

                    if ($this->busDispatchIsUnresolved($arg, $isGroup, count($jobClasses))) {
                        $this->markUnresolvedDispatch();
                    }

                    return;
                }

                // View::make('name')
                if (in_array($class, ['View', 'Illuminate\\Support\\Facades\\View'], true) && $method === 'make') {
                    $vn = $this->extractViewName($node->args[0] ?? null);
                    if ($vn !== null) {
                        $this->hops[] = [
                            'fqcn' => MethodTracer::BLADE_FQCN_PREFIX.$vn,
                            'method' => 'render',
                            'type' => 'view',
                            'visibility' => 'public',
                        ];
                    }

                    return;
                }

                // Notification::send($users, new SomeNotification(...))
                if (in_array($class, ['Notification', 'Illuminate\\Support\\Facades\\Notification'], true) && $method === 'send') {
                    $notifClass = $this->extractNewClass($node->args[1] ?? null);
                    if ($notifClass) {
                        $nf = $this->useMap[$notifClass] ?? $notifClass;
                        if ($this->tracer->looksLikeNotification($nf)) {
                            $this->hops[] = [
                                'fqcn' => $nf,
                                'method' => 'via',
                                'type' => 'notification',
                                'visibility' => 'public',
                            ];
                        }
                    }

                    return;
                }

                // Eloquent static queries: User::find(), Order::create() …
                if ($this->looksLikeModel($fqcn) && in_array($method, MethodTracer::MODEL_STATIC_METHODS, true)) {
                    $this->hops[] = ['fqcn' => $fqcn, 'method' => $method, 'type' => 'model', 'visibility' => 'public'];
                }
            }

            // ── Instance method calls: $this->service->method() ──────────────

            private function handleMethodCall(Node\Expr\MethodCall $node): void
            {
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                if ($method === null) {
                    return;
                }

                // $this->authorize('ability', Model::class) or $this->authorize('ability', $model)
                if ($method === 'authorize'
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'this'
                ) {
                    $this->handleAuthorize($node);

                    return;
                }

                // $this->dispatch(new Job) / $this->dispatchSync(new Job) — DispatchesJobs trait.
                if (in_array($method, ['dispatch', 'dispatchSync'], true)
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'this'
                ) {
                    $arg = $node->args[0] ?? null;
                    $jobClass = $this->extractNewClass($arg);
                    if ($jobClass !== null) {
                        $jobFqcn = $this->useMap[$jobClass] ?? $jobClass;
                        if ($this->looksLikeJob($jobFqcn)) {
                            $this->hops[] = ['fqcn' => $jobFqcn, 'method' => 'handle', 'type' => 'job', 'visibility' => 'public'];
                        }
                    } elseif ($this->isOpaqueJobArg($arg)) {
                        // $this->dispatch($job) with a non-literal job — unresolved. A string/array arg is
                        // a Livewire/Filament event dispatch, not a job, so isOpaqueJobArg() excludes it.
                        $this->markUnresolvedDispatch();
                    }

                    return;
                }

                if ($method === 'view' && ! empty($node->args)) {
                    $vn = $this->extractViewName($node->args[0] ?? null);
                    if ($vn !== null) {
                        $this->hops[] = [
                            'fqcn' => MethodTracer::BLADE_FQCN_PREFIX.$vn,
                            'method' => 'render',
                            'type' => 'view',
                            'visibility' => 'public',
                        ];

                        return;
                    }
                }

                if ($method === 'send' && ! empty($node->args)) {
                    $first = $node->args[0];
                    $val = $first instanceof Node\Arg ? $first->value : $first;
                    if ($val instanceof Node\Expr\New_ && $val->class instanceof Node\Name) {
                        $short = $val->class->toString();
                        $nf = $this->useMap[$short] ?? $short;
                        if ($this->tracer->looksLikeMail($nf)) {
                            $this->hops[] = [
                                'fqcn' => $nf,
                                'method' => 'build',
                                'type' => 'mail',
                                'visibility' => 'public',
                            ];

                            return;
                        }
                        if ($this->tracer->looksLikeNotification($nf)) {
                            $this->hops[] = [
                                'fqcn' => $nf,
                                'method' => 'via',
                                'type' => 'notification',
                                'visibility' => 'public',
                            ];

                            return;
                        }
                    }
                }

                $fqcn = $this->resolveVar($node->var);
                if ($fqcn === null) {
                    return;
                }

                if ($this->looksLikeModel($fqcn)) {
                    $this->hops[] = ['fqcn' => $fqcn, 'method' => $method, 'type' => 'model', 'visibility' => 'public'];
                } elseif (! $this->isFrameworkClass($fqcn)) {
                    $type = $this->classifyFqcn($fqcn);
                    $visibility = $this->resolveVisibility($node);
                    $this->hops[] = ['fqcn' => $fqcn, 'method' => $method, 'type' => $type, 'visibility' => $visibility];
                }
            }

            // ── Function calls: event(new Xyz), dispatch(new Job) ────────────

            private function handleFuncCall(Node\Expr\FuncCall $node): void
            {
                if (! $node->name instanceof Node\Name) {
                    return;
                }
                $funcName = $node->name->toString();

                if ($funcName === 'view') {
                    $vn = $this->extractViewName($node->args[0] ?? null);
                    if ($vn !== null) {
                        $this->hops[] = [
                            'fqcn' => MethodTracer::BLADE_FQCN_PREFIX.$vn,
                            'method' => 'render',
                            'type' => 'view',
                            'visibility' => 'public',
                        ];
                    }

                    return;
                }

                if (in_array($funcName, self::EVENT_FUNCTIONS, true)) {
                    $eventClass = $this->extractNewClass($node->args[0] ?? null);
                    if ($eventClass) {
                        $this->hops[] = [
                            'fqcn' => $this->useMap[$eventClass] ?? $eventClass,
                            'method' => '__construct',
                            'type' => 'event',
                            'visibility' => 'public',
                        ];
                    }
                } elseif (in_array($funcName, $this->dispatchFunctions, true)) {
                    $arg = $node->args[0] ?? null;
                    $jobClass = $this->extractNewClass($arg);
                    if ($jobClass !== null) {
                        $jobFqcn = $this->useMap[$jobClass] ?? $jobClass;
                        if ($this->looksLikeJob($jobFqcn)) {
                            $this->hops[] = ['fqcn' => $jobFqcn, 'method' => 'handle', 'type' => 'job', 'visibility' => 'public'];
                        }
                    } elseif ($this->isOpaqueJobArg($arg)) {
                        // A dispatch verb whose job is a variable / factory result, not a literal new — unresolved.
                        $this->markUnresolvedDispatch();
                    }
                }
            }

            // ── new SomeClass() — catches direct instantiation of services ───

            private function handleNew(Node\Expr\New_ $node): void
            {
                if (! $node->class instanceof Node\Name) {
                    return;
                }
                $class = $node->class->toString();
                $fqcn = $this->useMap[$class] ?? $class;

                if ($this->looksLikeJob($fqcn)) {
                    // Caught by dispatch() later; skip to avoid double-counting
                    return;
                }
                if ($this->tracer->looksLikeMail($fqcn)) {
                    $this->hops[] = [
                        'fqcn' => $fqcn,
                        'method' => 'build',
                        'type' => 'mail',
                        'visibility' => 'public',
                    ];

                    return;
                }
                if ($this->tracer->looksLikeNotification($fqcn)) {
                    $this->hops[] = [
                        'fqcn' => $fqcn,
                        'method' => 'via',
                        'type' => 'notification',
                        'visibility' => 'public',
                    ];

                    return;
                }
                if (! $this->looksLikeModel($fqcn) && ! $this->isFrameworkClass($fqcn) && str_contains($fqcn, '\\')) {
                    $type = $this->classifyFqcn($fqcn);
                    $this->hops[] = [
                        'fqcn' => $fqcn,
                        'method' => '__construct',
                        'type' => $type,
                        'visibility' => 'public',
                    ];
                }
            }

            // ── $var = new SomeClass() — register local var for later method calls ─

            private function handleAssign(Node\Expr\Assign $node): void
            {
                if (! $node->expr instanceof Node\Expr\New_) {
                    return;
                }
                if (! $node->var instanceof Node\Expr\Variable || ! is_string($node->var->name)) {
                    return;
                }
                if (! $node->expr->class instanceof Node\Name) {
                    return;
                }
                $varName = $node->var->name;
                $class = $node->expr->class->toString();
                $fqcn = $this->useMap[$class] ?? $class;

                if (! $this->looksLikeModel($fqcn) && ! $this->isFrameworkClass($fqcn) && str_contains($fqcn, '\\')) {
                    $this->varTypeMap[$varName] = $fqcn;
                }
            }

            private function handleClassConstFetch(Node\Expr\ClassConstFetch $node): void
            {
                if (! $node->class instanceof Node\Name) {
                    return;
                }
                if ($node->name instanceof Node\Identifier && $node->name->toString() === 'class') {
                    return;
                }
                $short = $node->class->toString();
                $lower = strtolower($short);
                if (in_array($lower, ['self', 'static', 'parent'], true)) {
                    return;
                }
                $fqcn = $this->useMap[$short] ?? $short;
                if (! str_contains($fqcn, '\\')) {
                    return;
                }
                if (! $this->tracer->declKindIsEnum($fqcn)) {
                    return;
                }
                $cons = $node->name instanceof Node\Identifier ? $node->name->toString() : 'case';
                $this->hops[] = [
                    'fqcn' => $fqcn,
                    'method' => $cons,
                    'type' => 'enum',
                    'visibility' => 'public',
                ];
            }

            /**
             * Extract the model FQCN from $this->authorize('ability', Model::class|$model).
             * Emits a 'model' hop so the policy target appears in the graph.
             */
            private function handleAuthorize(Node\Expr\MethodCall $node): void
            {
                $abilityArg = $node->args[0] ?? null;
                $ability = 'authorize';
                if ($abilityArg) {
                    $av = $abilityArg instanceof Node\Arg ? $abilityArg->value : $abilityArg;
                    if ($av instanceof Node\Scalar\String_) {
                        $ability = $av->value;
                    }
                }

                $modelArg = $node->args[1] ?? null;
                if ($modelArg === null) {
                    return;
                }
                $val = $modelArg instanceof Node\Arg ? $modelArg->value : $modelArg;

                // Model::class form
                if ($val instanceof Node\Expr\ClassConstFetch
                    && $val->class instanceof Node\Name
                    && $val->name instanceof Node\Identifier
                    && $val->name->toString() === 'class'
                ) {
                    $short = $val->class->toString();
                    $fqcn = $this->useMap[$short] ?? $short;
                    if ($this->looksLikeModel($fqcn)) {
                        $this->hops[] = ['fqcn' => $fqcn, 'method' => $ability, 'type' => 'model', 'visibility' => 'public'];
                    }

                    return;
                }

                // $model variable form
                if ($val instanceof Node\Expr\Variable && is_string($val->name)) {
                    $fqcn = $this->varTypeMap[$val->name] ?? null;
                    if ($fqcn !== null && $this->looksLikeModel($fqcn)) {
                        $this->hops[] = ['fqcn' => $fqcn, 'method' => $ability, 'type' => 'model', 'visibility' => 'public'];
                    }
                }
            }

            private function extractViewName(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $value = $node instanceof Node\Arg ? $node->value : $node;
                if ($value instanceof Node\Scalar\String_) {
                    return $value->value;
                }

                return null;
            }

            private function resolveVisibility(Node\Expr\MethodCall|Node\Expr\StaticCall|Node\Expr\New_ $node): string
            {
                // Note: We can't easily know the visibility of the callee without re-parsing its class.
                // However, MethodTracer->traceDeep() will load the class anyway.
                // For now, let's default to 'public' here and let traceDeep or ensureNode refine it if needed.
                // Actually, let's just make it 'public' for now in the hop, and have the actual node creation
                // in GraphBuilder find the real visibility.
                return 'public';
            }

            // ── Helpers ───────────────────────────────────────────────────────

            /** Resolve a variable node to its FQCN, handling $this->prop chains */
            private function resolveVar(Node\Expr $node): ?string
            {
                // $this->prop OR $this (direct call)
                if ($node instanceof Node\Expr\Variable && $node->name === 'this') {
                    return $this->currentFqcn;
                }

                if (
                    $node instanceof Node\Expr\PropertyFetch
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier
                ) {
                    $prop = $node->name->toString();

                    return $this->varTypeMap[$prop] ?? null;
                }
                // $localVar
                if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
                    return $this->varTypeMap[$node->name] ?? null;
                }

                return null;
            }

            private function extractNewClass(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $value = $node instanceof Node\Arg ? $node->value : $node;
                if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name) {
                    return $value->class->toString();
                }

                return null;
            }

            /**
             * A Bus dispatch is unresolved when its job argument isn't a literal Brain can read:
             * a single dispatch whose argument isn't `new Job`, or a chain/batch whose argument
             * isn't an array literal or contains a non-literal entry. A no-arg call isn't a dispatch.
             */
            private function busDispatchIsUnresolved(?Node $arg, bool $isGroup, int $resolvedCount): bool
            {
                $value = $arg instanceof Node\Arg ? $arg->value : $arg;
                if ($value === null) {
                    return false;
                }
                if (! $isGroup) {
                    return ! $value instanceof Node\Expr\New_;
                }
                if (! $value instanceof Node\Expr\Array_) {
                    return true;
                }

                return count(array_filter($value->items)) > $resolvedCount;
            }

            /**
             * The `new X()` items in an array-literal argument, e.g. Bus::chain([new A, new B]).
             *
             * @return string[]
             */
            private function extractNewClassesFromArray(?Node $node): array
            {
                $value = $node instanceof Node\Arg ? $node->value : $node;
                if (! $value instanceof Node\Expr\Array_) {
                    return [];
                }

                $classes = [];
                foreach ($value->items as $item) {
                    $class = $item !== null ? $this->extractNewClass($item->value) : null;
                    if ($class !== null) {
                        $classes[] = $class;
                    }
                }

                return $classes;
            }

            private function looksLikeModel(string $class): bool
            {
                // Covers App\Models\, Modules\Blog\Models\, any \Models\ or \Model\ segment
                if (str_contains($class, '\\Models\\') || str_contains($class, '\\Model\\')) {
                    return true;
                }
                foreach (self::MODEL_NAMESPACES as $ns) {
                    if (str_starts_with($class, $ns)) {
                        return true;
                    }
                }

                return ! str_contains($class, '\\') && ctype_upper($class[0] ?? '');
            }

            private function looksLikeJob(string $class): bool
            {
                return str_contains($class, 'Job') || str_contains($class, '\\Jobs\\');
            }

            private function classifyFqcn(string $fqcn): string
            {
                $decl = $this->tracer->declarationKind($fqcn);
                if ($decl === 'interface') {
                    return 'interface';
                }
                if ($decl === 'enum') {
                    return 'enum';
                }
                if ($decl === 'trait') {
                    return 'trait';
                }
                if ($decl === 'abstract_class') {
                    return 'abstract_class';
                }
                if (str_contains($fqcn, 'Controller') || str_contains($fqcn, '\\Http\\')) {
                    return 'action';
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

            private function isFrameworkClass(string $fqcn): bool
            {
                return str_starts_with($fqcn, 'Illuminate\\')
                    || str_starts_with($fqcn, 'Laravel\\')
                    || in_array($fqcn, ['Request', 'Response', 'Validator', 'Auth', 'DB', 'Cache', 'Log', 'Storage'], true);
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse([$ast]);

        return $visitor->hops;
    }

    // ─── Class file loader ────────────────────────────────────────────────────

    /**
     * Load a class by FQCN, parse it, and return:
     *   [ 'methods' => [name => ClassMethod], 'deps' => [prop => FQCN], 'useMap' => [...] ]
     */
    private function loadClass(string $fqcn): ?array
    {
        if (isset($this->classCache[$fqcn])) {
            return $this->classCache[$fqcn];
        }

        $file = $this->resolveFile($fqcn);
        if ($file === null || ! file_exists($file)) {
            return $this->classCache[$fqcn] = null;
        }

        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return $this->classCache[$fqcn] = null;
        }

        $expectedShort = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;
        $fileNamespace = PhpExtendsFqcnResolver::namespaceFromAst($parsed['ast']);

        $traverser = new NodeTraverser;
        $visitor = new class($expectedShort) extends NodeVisitorAbstract
        {
            public array $constructorDeps = []; // varName/prop => FQCN

            public array $methods = [];          // methodName => ClassMethod

            public array $useMap = [];

            public ?Node $extendsNode = null;

            public function __construct(private string $expectedShort) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Class_) {
                    if ($node->name === null || $node->name->toString() !== $this->expectedShort) {
                        return null;
                    }
                    $this->extendsNode = $node->extends;
                }
                if ($node instanceof Node\Stmt\ClassMethod) {
                    $name = $node->name->toString();

                    // Extract typed params
                    $deps = [];
                    foreach ($node->params as $param) {
                        $varName = $param->var instanceof Node\Expr\Variable ? $param->var->name : null;
                        if (! is_string($varName)) {
                            continue;
                        }
                        $typeName = $this->resolveType($param->type);
                        if ($typeName) {
                            $deps[$varName] = $typeName;
                        }
                    }

                    if ($name === '__construct') {
                        $this->constructorDeps = $deps;
                    }

                    $this->methods[$name] = $node;
                }

                return null;
            }

            private function resolveType(?Node $type): ?string
            {
                if ($type === null) {
                    return null;
                }
                if ($type instanceof Node\Name) {
                    return $type->toString();
                }
                if ($type instanceof Node\NullableType) {
                    return $this->resolveType($type->type);
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        // Resolve short names via useMap
        $useMap = $parsed['useMap'];
        $deps = [];
        foreach ($visitor->constructorDeps as $var => $short) {
            $deps[$var] = $useMap[$short] ?? $short;
        }

        $parentFqcn = PhpExtendsFqcnResolver::resolveExtends(
            $visitor->extendsNode,
            $fileNamespace,
            $useMap,
        );

        $result = [
            'methods' => $visitor->methods,
            'deps' => $deps,
            'useMap' => $useMap,
            'parent' => $parentFqcn,
        ];

        return $this->classCache[$fqcn] = $result;
    }

    private function resolveFile(string $fqcn): ?string
    {
        foreach ($this->psr4Map as $namespace => $basePaths) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace) + 1)).'.php';
                foreach ((array) $basePaths as $basePath) {
                    $filePath = $basePath.'/'.$relative;
                    if (file_exists($filePath)) {
                        return $filePath;
                    }
                }
            }
        }

        // Fallback: try common locations using full relative path
        if ($this->projectRoot !== '') {
            $relative = str_replace('\\', '/', $fqcn).'.php';
            foreach (['app/Http/Controllers/', 'app/', 'src/'] as $prefix) {
                $path = $this->projectRoot.'/'.$prefix.$relative;
                if (file_exists($path)) {
                    return $path;
                }
            }

            // Last resort: search by short class name inside app/ and src/
            return $this->searchByClassName($fqcn);
        }

        return null;
    }

    private function searchByClassName(string $fqcn): ?string
    {
        $shortName = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;

        $filename = $shortName.'.php';

        foreach (['app', 'src'] as $dir) {
            $base = $this->projectRoot.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $filename) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    private function fallbackEntryMethod(string $fqcn, string $requested, array $methods): string
    {
        if (isset($methods[$requested])) {
            return $requested;
        }
        if ($this->looksLikeMail($fqcn)) {
            foreach (['build', 'envelope', 'content', '__construct'] as $m) {
                if (isset($methods[$m])) {
                    return $m;
                }
            }
        }
        if ($this->looksLikeNotification($fqcn)) {
            foreach (['via', 'toMail', 'toArray', 'toBroadcast', '__construct'] as $m) {
                if (isset($methods[$m])) {
                    return $m;
                }
            }
        }

        return $requested;
    }

    /**
     * Release the class-info cache to free ClassMethod AST nodes from memory.
     * Call this after all tracing is done and before the graph-building phase.
     */
    public function releaseClassCache(): void
    {
        $this->classCache = [];
    }

    public function looksLikeMail(string $class): bool
    {
        return str_contains($class, '\\Mail\\')
            || str_contains($class, '\\Mails\\')
            || str_contains($class, 'Mailable');
    }

    public function looksLikeNotification(string $class): bool
    {
        return str_contains($class, '\\Notifications\\');
    }

    public function declKindIsEnum(string $fqcn): bool
    {
        return $this->declKindForFqcn($fqcn) === 'enum';
    }

    /**
     * Surface syntax kind of an FQCN's declaring file (enum, interface, trait, or abstract class only).
     *
     * @return 'enum'|'interface'|'trait'|'abstract_class'|null
     */
    public function declarationKind(string $fqcn): ?string
    {
        return $this->declKindForFqcn($fqcn);
    }

    private function declKindForFqcn(string $fqcn): ?string
    {
        if (array_key_exists($fqcn, $this->declKindCache)) {
            return $this->declKindCache[$fqcn];
        }
        $file = $this->resolveFile($fqcn);
        if ($file === null || ! is_file($file)) {
            return $this->declKindCache[$fqcn] = null;
        }
        $info = $this->structureInspector->inspectFile($file);
        if ($info === null) {
            return $this->declKindCache[$fqcn] = null;
        }

        return $this->declKindCache[$fqcn] = $info['kind'];
    }
}
