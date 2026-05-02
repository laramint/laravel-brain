<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

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

    /** @var string[] */
    private array $classSearchBases = [];

    private ProjectStructure $projectStructure;

    private string $projectRoot = '';

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    /**
     * Trace a single class method and return all discovered CallChainEdges.
     * Useful for tracing commands, channels, or any non-controller class.
     *
     * @return CallChainEdge[]
     */
    public function traceMethod(string $fqcn, string $method, array $psr4Map = [], string $projectRoot = ''): array
    {
        $discoveredPsr4Map = $projectRoot !== '' ? $this->projectStructure->buildPsr4Map($projectRoot) : [];
        $this->psr4Map = $psr4Map + $discoveredPsr4Map;
        $this->projectRoot = $projectRoot;
        $this->classSearchBases = $projectRoot !== ''
            ? $this->projectStructure->discoverClassSearchBases($projectRoot)
            : [];
        $this->visited = [];
        // Keep classCache across calls for efficiency when tracing many classes

        return $this->traceDeep($fqcn, $method, depth: 0);
    }

    /**
     * @param  ControllerDefinition[]  $controllers  keyed by FQCN
     * @param  array<string,string>  $psr4Map  namespace prefix => base path
     * @param  string  $projectRoot  root path for fallback file search
     * @return CallChainEdge[]
     */
    public function trace(array $controllers, array $psr4Map = [], string $projectRoot = ''): array
    {
        $discoveredPsr4Map = $projectRoot !== '' ? $this->projectStructure->buildPsr4Map($projectRoot) : [];
        $this->psr4Map = $psr4Map + $discoveredPsr4Map;
        $this->projectRoot = $projectRoot;
        $this->classSearchBases = $projectRoot !== ''
            ? $this->projectStructure->discoverClassSearchBases($projectRoot)
            : [];
        $this->visited = [];
        $this->classCache = [];

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

                $discovered = $this->scanMethod(
                    $methodDef->ast,
                    $deps,
                    $controller->useMap,
                    $controller->fqcn,
                    $controller->parent,
                );

                foreach ($discovered as $hop) {
                    $edges[] = new CallChainEdge(
                        callerFqcn: $controller->fqcn,
                        callerMethod: $methodDef->name,
                        calleeFqcn: $hop['fqcn'],
                        calleeMethod: $hop['method'],
                        type: $hop['type'],
                        visibility: $hop['visibility'],
                    );

                    // Recurse into non-leaf hops (services, repositories)
                    if (in_array($hop['type'], ['service', 'repository', 'action'], true)) {
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

        $classInfo = $this->loadClass($fqcn);
        if ($classInfo === null) {
            return [];
        }

        $methodAst = $classInfo['methods'][$method] ?? null;
        if ($methodAst === null) {
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
            $edges[] = new CallChainEdge(
                callerFqcn: $fqcn,
                callerMethod: $method,
                calleeFqcn: $hop['fqcn'],
                calleeMethod: $hop['method'],
                type: $hop['type'],
                visibility: $hop['visibility'],
            );

            if (in_array($hop['type'], ['service', 'repository', 'action'], true)) {
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
        Node\Stmt\ClassMethod $ast,
        array $varTypeMap,
        array $useMap,
        string $currentFqcn,
        ?string $parentFqcn = null,
    ): array {
        $traverser = new NodeTraverser;

        $visitor = new class($varTypeMap, $useMap, $currentFqcn, $parentFqcn) extends NodeVisitorAbstract
        {
            /** @var list<array{fqcn:string,method:string,type:string,visibility:string}> */
            public array $hops = [];

            private array $varTypeMap;

            private array $useMap;

            private string $currentFqcn;

            private ?string $parentFqcn;

            private const MODEL_NAMESPACES = ['App\\Models\\', 'App\\Model\\', 'Models\\'];

            private const EVENT_FUNCTIONS = ['event'];

            private const DISPATCH_FUNCTIONS = ['dispatch'];

            public function __construct(array $varTypeMap, array $useMap, string $currentFqcn, ?string $parentFqcn = null)
            {
                $this->varTypeMap = $varTypeMap;
                $this->useMap = $useMap;
                $this->currentFqcn = $currentFqcn;
                $this->parentFqcn = $parentFqcn;
            }

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Expr\StaticCall) {
                    $this->handleStaticCall($node);
                } elseif ($node instanceof Node\Expr\MethodCall) {
                    $this->handleMethodCall($node);
                } elseif ($node instanceof Node\Expr\FuncCall) {
                    $this->handleFuncCall($node);
                } elseif ($node instanceof Node\Expr\New_) {
                    $this->handleNew($node);
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
                } elseif (in_array($funcName, self::DISPATCH_FUNCTIONS, true)) {
                    $jobClass = $this->extractNewClass($node->args[0] ?? null);
                    if ($jobClass) {
                        $jobFqcn = $this->useMap[$jobClass] ?? $jobClass;
                        if ($this->looksLikeJob($jobFqcn)) {
                            $this->hops[] = ['fqcn' => $jobFqcn, 'method' => 'handle', 'type' => 'job', 'visibility' => 'public'];
                        }
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

            private function looksLikeModel(string $class): bool
            {
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

        $traverser = new NodeTraverser;
        $visitor = new class extends NodeVisitorAbstract
        {
            public array $constructorDeps = []; // varName/prop => FQCN

            public array $methods = [];          // methodName => ClassMethod

            public array $useMap = [];

            public ?string $extends = null;

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Class_) {
                    $this->extends = $node->extends ? $node->extends->toString() : null;
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

        $result = [
            'methods' => $visitor->methods,
            'deps' => $deps,
            'useMap' => $useMap,
            'parent' => $visitor->extends ? ($useMap[$visitor->extends] ?? $visitor->extends) : null,
        ];

        return $this->classCache[$fqcn] = $result;
    }

    private function resolveFile(string $fqcn): ?string
    {
        foreach ($this->psr4Map as $namespace => $basePath) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = substr($fqcn, strlen($namespace) + 1);
                $path = $basePath.'/'.str_replace('\\', '/', $relative).'.php';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        // Fallback: try common locations using full relative path
        if ($this->projectRoot !== '') {
            $relative = str_replace('\\', '/', $fqcn).'.php';
            foreach ($this->classSearchBases as $base) {
                $path = $base.'/'.$relative;
                if (file_exists($path)) {
                    return $path;
                }
            }

            // Last resort: search by short class name inside discovered class roots
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

        foreach ($this->classSearchBases as $base) {
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
}
