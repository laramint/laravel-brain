<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

use Illuminate\Support\Str;
use LaraMint\LaravelBrain\Analysis\BladeViewAnalyzer;
use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\ContainerBindingRecord;
use LaraMint\LaravelBrain\Analysis\ContainerBindingRegistry;
use LaraMint\LaravelBrain\Analysis\ControllerDefinition;
use LaraMint\LaravelBrain\Analysis\DbQuery;
use LaraMint\LaravelBrain\Analysis\FacadeRegistry;
use LaraMint\LaravelBrain\Analysis\FilamentPageDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPanelDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentRelationManagerDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentResourceDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentWidgetDefinition;
use LaraMint\LaravelBrain\Analysis\FlowExtractor;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelDefinition;
use LaraMint\LaravelBrain\Analysis\PhpStructureInspector;
use LaraMint\LaravelBrain\Analysis\ProjectFileIndex;
use LaraMint\LaravelBrain\Analysis\RelationAutoloading;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;
use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Analysis\ValidationRulesExtractor;
use LaraMint\LaravelBrain\Parser\PhpExtendsFqcnResolver;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node as PhpNode;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

class GraphBuilder
{
    private Graph $graph;

    /** @var array<string, int> content-key => times emitted, for stable duplicate-edge id suffixes */
    private array $edgeIdOccurrence = [];

    private FlowExtractor $flowExtractor;

    private PhpFileParser $parser;

    private array $psr4Map = [];

    /** @var array<string, string> FQCN => resolved path ('' when unresolvable), per build */
    private array $resolveFileMemo = [];

    /**
     * Per-file method map, use map and resolved parent — see fileClassInfo().
     *
     * @var array<string, array{methods: array<string, PhpNode\Stmt\ClassMethod>, useMap: array<string,string>, parent: ?string}|null>
     */
    private array $fileClassInfo = [];

    /**
     * Memoized findMethodNodeInChain() results, keyed by "fqcn\0method".
     *
     * @var array<string, array{methodNode: PhpNode\Stmt\ClassMethod, useMap: array<string,string>, file: string, declaringFqcn: string}|null>
     */
    private array $methodNodeMemo = [];

    /**
     * Accumulator for fat-class detection.
     * Maps controllerId/nodeId => ['totalLines' => int, 'methodCount' => int]
     *
     * @var array<string, array>
     */
    private array $classMetrics = [];

    /**
     * @var array<string, true> dedupe keys "source|target" for controller-extends edges
     */
    private array $seenControllerExtendsEdges = [];

    /** @var array<string, DbQuery[]> "FQCN::method" => DbQuery[] */
    private array $dbQueryMap = [];

    private string $projectRoot = '';

    /** @var string[] Directories (relative to project root) searched for Livewire namespace::dot.notation components */
    private array $livewireComponentPaths = [
        'app/Http/Livewire',
        'app/Livewire',
        'app/View/Components',
    ];

    private ?PhpStructureInspector $structureInspector = null;

    /** @var array<string, 'enum'|'interface'|'trait'|'abstract_class'|null> */
    private array $surfaceKindCache = [];

    /** @var string[] view roots, relative to the project root */
    private array $viewPaths = BladeViewAnalyzer::DEFAULT_PATHS;

    /** @var string[] class-file search roots, relative to the project root */
    private array $sourcePaths = SourceDirectories::DEFAULT_SOURCE_PATHS;

    private ?ContainerBindingRegistry $bindingRegistry = null;

    private ?FacadeRegistry $facadeRegistry = null;

    private ?ValidationRulesExtractor $validationRulesExtractor = null;

    /**
     * Dedupe keys for container binding and facade resolution edges.
     *
     * @var array<string, true>
     */
    private array $seenBindingWires = [];

    /**
     * Security surface map produced by SecurityAnalyzer.
     * routeId => ['exposure' => string, 'riskLevel' => string, 'issues' => array[]]
     *
     * @var array<string, array>
     */
    private array $securityMap = [];

    public function __construct()
    {
        $this->graph = new Graph;
        $this->flowExtractor = new FlowExtractor(RelationAutoloading::isEnabled());
        $this->parser = new PhpFileParser;
    }

    /** @param  string[]  $paths  Directories relative to project root */
    public function setLivewireComponentPaths(array $paths): void
    {
        if ($paths !== []) {
            $this->livewireComponentPaths = $paths;
        }
    }

    /**
     * The view roots a view name is resolved against. Must match what
     * {@see BladeViewAnalyzer} was given, or the two
     * disagree about which templates exist.
     *
     * @param  string[]  $paths  relative to the project root; glob patterns are expanded
     */
    public function setViewPaths(array $paths): void
    {
        if ($paths !== []) {
            $this->viewPaths = $paths;
        }
    }

    /**
     * The directories searched by file name when the PSR-4 map cannot place a class.
     *
     * @param  string[]  $paths  relative to the project root; glob patterns are expanded
     */
    public function setSourcePaths(array $paths): void
    {
        if ($paths !== []) {
            $this->sourcePaths = $paths;
        }
    }

    /**
     * Build a PSR-4 map that covers both project classes and vendor packages
     * by reading vendor/composer/autoload_psr4.php (generated by Composer).
     */
    private function buildFullPsr4Map(string $projectRoot): array
    {
        $autoloadFile = $projectRoot.'/vendor/composer/autoload_psr4.php';
        if (! file_exists($autoloadFile)) {
            return [];
        }

        // The file uses $vendorDir / $baseDir variables
        $vendorDir = $projectRoot.'/vendor';
        $baseDir = $projectRoot;

        $raw = require $autoloadFile;
        $map = [];
        foreach ($raw as $namespace => $paths) {
            foreach ((array) $paths as $path) {
                $map[rtrim($namespace, '\\')] = rtrim($path, '/');
                break; // take first path per namespace
            }
        }

        return $map;
    }

    /**
     * Resolve an FQCN to an absolute file path using the PSR-4 map.
     *
     * Memoized for the build: the answer depends only on the FQCN, the PSR-4 map and the
     * project root, all fixed once buildGraph() starts.
     */
    private function resolveFile(string $fqcn): string
    {
        return $this->resolveFileMemo[$fqcn] ??= $this->resolveFileUncached($fqcn);
    }

    private function resolveFileUncached(string $fqcn): string
    {
        // Livewire v2 namespace::dot.notation (e.g. 'pages::password.create')
        if (str_contains($fqcn, '::') && ! str_contains($fqcn, '\\')) {
            return $this->resolveLivewireStringComponent($fqcn);
        }

        foreach ($this->psr4Map as $namespace => $basePath) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = substr($fqcn, strlen($namespace) + 1);
                $path = $basePath.'/'.str_replace('\\', '/', $relative).'.php';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        if ($this->projectRoot !== '') {
            $relative = str_replace('\\', '/', $fqcn).'.php';
            foreach (SourceDirectories::classFilePrefixes($this->projectRoot, $this->sourcePaths) as $prefix) {
                $path = $this->projectRoot.'/'.$prefix.$relative;
                if (file_exists($path)) {
                    return $path;
                }
            }

            $found = $this->searchProjectForClassFile($fqcn);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    /**
     * Last-resort lookup when Composer's PSR-4 map is unavailable (e.g. fixture trees without vendor/).
     */
    private function searchProjectForClassFile(string $fqcn): string
    {
        $shortName = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;

        $filename = $shortName.'.php';

        return ProjectFileIndex::findFile(
            $this->projectRoot,
            SourceDirectories::resolve($this->projectRoot, $this->sourcePaths),
            $filename,
        ) ?? '';
    }

    /**
     * Resolve a Livewire v2 namespace::dot.notation component string to a file path.
     * E.g. 'pages::password.create' → app/Http/Livewire/Pages/Password/Create.php
     */
    private function resolveLivewireStringComponent(string $component): string
    {
        [$prefix, $dotPath] = explode('::', $component, 2);

        $pathParts = array_map(fn ($p) => Str::studly($p), explode('.', $dotPath));
        $relativePath = implode('/', $pathParts);
        $prefixPath = Str::studly($prefix);

        foreach ($this->livewireComponentPaths as $baseDir) {
            foreach ([$prefixPath.'/'.$relativePath, $relativePath] as $sub) {
                $path = $this->projectRoot.'/'.$baseDir.'/'.$sub.'.php';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        return '';
    }

    /**
     * Parse a class file (cached) and extract flow steps for a specific method.
     */
    private function extractMethodFlowSteps(string $fqcn, string $method): array
    {
        $found = $this->findMethodNodeInChain($fqcn, $method);
        if ($found === null) {
            return [];
        }

        return $this->flowExtractor->extract($found['methodNode'], $found['useMap']);
    }

    /**
     * Compute complexity metrics for a specific method in a class file (cached).
     * Returns an empty array if the file or method cannot be resolved.
     */
    private function extractMethodMetrics(string $fqcn, string $method): array
    {
        $found = $this->findMethodNodeInChain($fqcn, $method);
        if ($found === null) {
            return [];
        }

        return $this->flowExtractor->metrics($found['methodNode']);
    }

    /**
     * Locate a method's AST node by walking the class inheritance chain.
     * When the method is not defined on $fqcn itself, the search continues
     * into the parent class, up to a depth of five hops.
     *
     * Returns null when the method cannot be found anywhere in the chain.
     *
     * @return array{methodNode: PhpNode\Stmt\ClassMethod, useMap: array<string,string>, file: string, declaringFqcn: string}|null
     */
    private function findMethodNodeInChain(string $fqcn, string $method, int $depth = 0): ?array
    {
        if ($depth > 5) {
            return null;
        }

        // Entry calls only: a nested call has already spent part of the five-hop budget, so its
        // result is not the answer to the same question.
        $memoKey = $fqcn."\0".$method;
        if ($depth === 0 && array_key_exists($memoKey, $this->methodNodeMemo)) {
            return $this->methodNodeMemo[$memoKey];
        }

        $found = $this->findMethodNodeInChainUncached($fqcn, $method, $depth);

        if ($depth === 0) {
            $this->methodNodeMemo[$memoKey] = $found;
        }

        return $found;
    }

    /**
     * @return array{methodNode: PhpNode\Stmt\ClassMethod, useMap: array<string,string>, file: string, declaringFqcn: string}|null
     */
    private function findMethodNodeInChainUncached(string $fqcn, string $method, int $depth): ?array
    {
        $file = $this->resolveFile($fqcn);
        if ($file === '') {
            return null;
        }

        $info = $this->fileClassInfo($file);
        if ($info === null) {
            return null;
        }

        if (isset($info['methods'][$method])) {
            return [
                'methodNode' => $info['methods'][$method],
                'useMap' => $info['useMap'],
                'file' => $file,
                'declaringFqcn' => $fqcn,
            ];
        }

        // Method not in this class — walk up to the parent if it is an app class.
        $parentFqcn = $info['parent'];
        if (
            $parentFqcn !== null
            && ! str_starts_with($parentFqcn, 'Illuminate\\')
            && ! str_starts_with($parentFqcn, 'Laravel\\')
        ) {
            return $this->findMethodNodeInChain($parentFqcn, $method, $depth + 1);
        }

        return null;
    }

    /**
     * Everything the chain walk needs from one file, collected in a single traversal and kept
     * for the build: its methods by name, its use map, and its resolved parent FQCN. The
     * traversal does not descend into method bodies.
     *
     * @return array{methods: array<string, PhpNode\Stmt\ClassMethod>, useMap: array<string,string>, parent: ?string}|null
     */
    private function fileClassInfo(string $file): ?array
    {
        if (array_key_exists($file, $this->fileClassInfo)) {
            return $this->fileClassInfo[$file];
        }

        $parsed = $this->parser->parse($file);
        if (! $parsed['ast']) {
            return $this->fileClassInfo[$file] = null;
        }

        $traverser = new NodeTraverser;
        $collector = new class extends NodeVisitorAbstract
        {
            /** @var array<string, PhpNode\Stmt\ClassMethod> */
            public array $methods = [];

            public function enterNode(PhpNode $node): ?int
            {
                if ($node instanceof PhpNode\Stmt\ClassMethod) {
                    // First declaration wins, as with the previous stop-at-first-match.
                    $this->methods[$node->name->toString()] ??= $node;

                    // The node keeps its body for later use; collecting names does not need it.
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        };
        $traverser->addVisitor($collector);
        $traverser->traverse($parsed['ast']);

        $ns = PhpExtendsFqcnResolver::namespaceFromAst($parsed['ast']);

        return $this->fileClassInfo[$file] = [
            'methods' => $collector->methods,
            'useMap' => $parsed['useMap'] ?? [],
            'parent' => $this->extractExtendsFromAst($parsed['ast'], $ns, $parsed['useMap'] ?? []),
        ];
    }

    /**
     * Extract the fully-qualified parent class name from a parsed file's AST.
     * Returns null when the class has no extends clause or it cannot be resolved.
     *
     * @param  PhpNode[]  $ast
     * @param  array<string, string>  $useMap
     */
    private function extractExtendsFromAst(array $ast, string $ns, array $useMap): ?string
    {
        // Find the namespace wherever it sits: a leading `declare(strict_types=1);` shifts it off
        // index 0, which used to break the inheritance walk for strict-typed child classes.
        $stmts = $ast;
        foreach ($ast as $stmt) {
            if ($stmt instanceof PhpNode\Stmt\Namespace_) {
                $stmts = $stmt->stmts;
                break;
            }
        }
        foreach ($stmts as $stmt) {
            if ($stmt instanceof PhpNode\Stmt\Class_ && $stmt->extends !== null) {
                return PhpExtendsFqcnResolver::resolveExtends($stmt->extends, $ns, $useMap);
            }
        }

        return null;
    }

    /**
     * @param  RouteDefinition[]  $routes
     * @param  array<string, ControllerDefinition>  $controllers
     * @param  CallChainEdge[]  $callChain
     * @param  array<string, ModelDefinition>  $models
     * @param  array<string, DbQuery[]>  $dbQueryMap  "FQCN::method" => queries
     * @param  array<string, array>  $securityMap  routeId => ['exposure'=>string, 'riskLevel'=>string, 'issues'=>array[]]
     */
    public function build(
        string $projectName,
        array $routes,
        MiddlewareRegistry $middlewareRegistry,
        array $controllers,
        array $callChain,
        array $models,
        string $projectRoot = '',
        array $dbQueryMap = [],
        ?ContainerBindingRegistry $bindingRegistry = null,
        ?FacadeRegistry $facadeRegistry = null,
        array $securityMap = [],
    ): Graph {
        if ($projectRoot !== '') {
            $this->psr4Map = $this->buildFullPsr4Map($projectRoot);
        }
        $this->projectRoot = $projectRoot;
        $this->classMetrics = [];
        $this->edgeIdOccurrence = [];
        $this->seenControllerExtendsEdges = [];
        $this->seenBindingWires = [];
        $this->bindingRegistry = $bindingRegistry;
        $this->facadeRegistry = $facadeRegistry;
        $this->dbQueryMap = $dbQueryMap;
        $this->securityMap = $securityMap;
        $this->graph->setMeta([
            'project' => $projectName,
            'analyzedAt' => date('c'),
        ]);

        // ── 1. Routes, Controllers, Actions, Middlewares ──────────────────────

        foreach ($routes as $route) {
            $routeId = $this->routeId($route);
            $this->addRouteNode($route, $routeId);

            if ($route->controller !== 'Closure' && $route->controller !== '') {
                $controllerId = $this->controllerId($route->controller);
                $routeControllerDef = $controllers[$route->controller] ?? null;
                $this->addControllerNode($route->controller, $routeControllerDef, $controllerId);
                $this->addEdge($routeId, $controllerId, 'calls', 'route-to-controller');
                $this->wireControllerAncestorEdges($route->controller, $routeControllerDef, $controllers);

                if ($route->action) {
                    $actionId = $this->actionId($route->controller, $route->action);
                    $this->addActionNode($route->controller, $route->action, $routeControllerDef, $actionId);

                    $declaringFqcn = $this->declaringFqcnForAction($routeControllerDef, $route->action)
                        ?? $route->controller;
                    $handlersId = $this->controllerId($declaringFqcn);
                    if ($declaringFqcn !== $route->controller) {
                        $this->addControllerNode($declaringFqcn, $controllers[$declaringFqcn] ?? null, $handlersId);
                    }
                    $this->addEdge($handlersId, $actionId, 'handles', 'controller-to-action');

                    if ($projectRoot !== '') {
                        $this->wireActionFormRequests(
                            $route->action,
                            $actionId,
                            $routeControllerDef,
                            $models,
                        );
                    }
                }
            }

            // Route-level middleware (from route file)
            $resolvedMiddlewares = $this->resolveMiddlewares($route->middlewares, $middlewareRegistry);
            foreach ($resolvedMiddlewares as $mw) {
                $mwId = $this->middlewareId($mw);
                $this->addMiddlewareNode($mw, $mwId);
                $this->addEdge($routeId, $mwId, 'guarded by', 'route-to-middleware');
            }

            // Controller-level middleware (HasMiddleware or $this->middleware() in __construct)
            // filtered by only/except against the route's action method
            $controllerDef = $controllers[$route->controller] ?? null;
            if ($controllerDef !== null && ! empty($controllerDef->middlewares)) {
                foreach ($controllerDef->middlewares as $cm) {
                    if (! $cm->appliesToAction($route->action)) {
                        continue;
                    }
                    $resolved = $this->resolveMiddlewares([$cm->middleware], $middlewareRegistry);
                    foreach ($resolved as $mw) {
                        $mwId = $this->middlewareId($mw);
                        $this->addMiddlewareNode($mw, $mwId);
                        $this->addEdge($routeId, $mwId, 'guarded by', 'route-to-middleware');
                    }
                }
            }
        }

        // ── 2. Deep call chain edges ──────────────────────────────────────────
        //
        // Each CallChainEdge looks like:
        //   OrderController::store  →  OrderService::createOrder  (service)
        //   OrderService::createOrder → OrderRepository::create   (repository)
        //   OrderRepository::create  → Order::create              (model)
        //   OrderService::createOrder → SendOrderConfirmationJob::handle (job)

        foreach ($callChain as $edge) {
            $callerNode = $this->nodeIdForHop($edge->callerFqcn, $edge->callerMethod);
            $calleeNode = $this->hopCalleeNodeId($edge);

            $calleeGraphType = $this->effectiveCalleeGraphType($edge->calleeFqcn, $edge->type);

            // Ensure callee node exists
            $this->ensureNode($edge->calleeFqcn, $edge->calleeMethod, $calleeGraphType, $models);

            // Ensure caller node exists (may be a service/repo itself in deep chains)
            // The controller action node is already created in step 1; for others, create here.
            if (! $this->graph->hasNode($callerNode)) {
                // Determine caller type by re-classifying
                $callerClassified = $this->classifyFqcn($edge->callerFqcn);
                $callerGraphType = $this->effectiveCalleeGraphType($edge->callerFqcn, $callerClassified);
                $this->ensureNode($edge->callerFqcn, $edge->callerMethod, $callerGraphType, $models);
            }

            $edgeLabel = $this->edgeLabelForType($calleeGraphType);
            $edgeType = 'action-to-'.$calleeGraphType;

            $this->addEdge($callerNode, $calleeNode, $edgeLabel, $edgeType);
            $this->maybeWireContainerBinding($edge, $models);
            $this->maybeWireFacadeResolution($edge, $models);
        }

        $this->supplementEnumAndInterfaceNodes($controllers, $callChain);
        $this->wireControllerInterfaceHints($routes, $controllers);

        // Free the two largest inputs — no longer needed for the remaining passes.
        unset($callChain, $routes);

        // ── 3. Model-to-model relationships and model-fired events ───────────

        foreach ($models as $modelFqcn => $modelDef) {
            $modelId = $this->modelId($modelFqcn);
            if (! $this->graph->hasNode($modelId)) {
                $this->addModelNode($modelFqcn, $modelDef, $modelId);
            }

            foreach ($modelDef->firedEvents as $eventFqcn) {
                $eventId = $this->eventId($eventFqcn);
                $this->addEventNode($eventFqcn, $eventId);
                $this->addEdge($modelId, $eventId, 'fires', 'model-to-event');
            }
            foreach ($modelDef->relationships as $rel) {
                $relatedId = $this->modelId($rel['related']);
                if (! $this->graph->hasNode($relatedId)) {
                    $this->addModelNode($rel['related'], null, $relatedId);
                }
                $this->addEdge($modelId, $relatedId, $rel['type'], 'model-relationship');
            }
        }

        // ── 4. Fat-class pass ─────────────────────────────────────────────────
        // After all nodes are built, mark controllers/services as fat classes.

        foreach ($this->classMetrics as $nodeId => $agg) {
            if (! $this->graph->hasNode($nodeId)) {
                continue;
            }
            if ($agg['totalLines'] > 300 || $agg['methodCount'] > 10) {
                $node = $this->graph->getNode($nodeId);
                if ($node !== null) {
                    $this->graph->updateNodeData($nodeId, array_merge(
                        $node->data,
                        ['fatClass' => true, 'classMetrics' => $agg],
                    ));
                }
            }
        }

        return $this->graph;
    }

    // ── Node creation helpers ─────────────────────────────────────────────────

    /**
     * Given a callee from a CallChainEdge, create the appropriate typed node.
     */
    private function ensureNode(string $fqcn, string $method, string $type, array $models): void
    {
        $id = match ($type) {
            'enum' => $this->enumNodeId($fqcn),
            'view' => $this->viewNodeId($fqcn),
            'interface' => $method !== '' ? $this->nodeIdForHop($fqcn, $method) : $this->interfaceNodeId($fqcn),
            'trait' => $this->traitNodeId($fqcn),
            default => $this->nodeIdForHop($fqcn, $method),
        };
        if ($this->graph->hasNode($id)) {
            return;
        }

        switch ($type) {
            case 'view':
                $viewDot = str_starts_with($fqcn, MethodTracer::BLADE_FQCN_PREFIX)
                    ? substr($fqcn, strlen(MethodTracer::BLADE_FQCN_PREFIX))
                    : $fqcn;
                $blade = $this->resolveBladePath($viewDot);
                $refs = $blade !== null ? $this->parseBladeRefs($blade) : [];
                $this->graph->addNode(new Node($id, 'view', $viewDot, [
                    'view' => $viewDot,
                    'file' => $blade ?? '',
                    'members' => $refs,
                ]));
                break;

            case 'enum':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $info = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->inspectFile($file)
                    : null;
                $members = $info['members'] ?? [];
                $this->graph->addNode(new Node($id, 'enum', $short, [
                    'fqcn' => $fqcn,
                    'file' => $file,
                    'members' => $members,
                ]));
                break;

            case 'interface':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $info = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->inspectFile($file)
                    : null;
                $members = $info['members'] ?? [];
                $this->graph->addNode(new Node($id, 'interface', $method !== '' ? "{$short}::{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'members' => $members,
                ]));
                break;

            case 'trait':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $info = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->inspectFile($file)
                    : null;
                $members = $info['members'] ?? [];
                $this->graph->addNode(new Node($id, 'trait', $method !== '' ? "{$short}::{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'members' => $members,
                ]));
                break;

            case 'abstract_class':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $this->extractMethodFlowSteps($fqcn, $method);
                $absMetrics = $this->extractMethodMetrics($fqcn, $method);
                $absInfo = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->inspectFile($file)
                    : null;
                $members = $absInfo['members'] ?? [];
                $this->graph->addNode(new Node($id, 'abstract_class', "{$short}@{$method}", [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'members' => $members,
                    'visibility' => $this->extractVisibility($fqcn, $method),
                    ...($absMetrics ? ['metrics' => $absMetrics] : []),
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                    ...($this->isFatMethod($absMetrics) ? ['fatMethod' => true] : []),
                ]));
                break;

            case 'mail':
            case 'notification':
            case 'resource':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $method !== '' ? $this->extractMethodFlowSteps($fqcn, $method) : [];
                $members = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->listClassMethods($file)
                    : [];
                $svcMetrics = $this->extractMethodMetrics($fqcn, $method);
                // What the response carries. The graph already says a route reaches this resource;
                // the payload's own shape is the part a consumer of the API sees.
                $payloadKeys = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->payloadKeys($file)
                    : [];
                $this->graph->addNode(new Node($id, $type, "{$short}@{$method}", [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'members' => $members,
                    'visibility' => $this->extractVisibility($fqcn, $method),
                    ...($svcMetrics ? ['metrics' => $svcMetrics] : []),
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                    ...($this->isFatMethod($svcMetrics) ? ['fatMethod' => true] : []),
                    ...($payloadKeys === [] ? [] : ['payloadKeys' => $payloadKeys]),
                ]));
                break;

            case 'model':
                $short = class_basename($fqcn);
                $file = isset($models[$fqcn]) ? $models[$fqcn]->file : $this->resolveFile($fqcn);
                $flowSteps = $method ? $this->extractMethodFlowSteps($fqcn, $method) : [];
                $this->graph->addNode(new Node($id, 'model', $method ? "{$short}::{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'visibility' => 'public',
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                ]));
                break;

            case 'job':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $method ? $this->extractMethodFlowSteps($fqcn, $method) : [];
                $this->graph->addNode(new Node($id, 'job', $method ? "{$short}@{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'visibility' => 'public',
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                ]));
                break;

            case 'event':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $method ? $this->extractMethodFlowSteps($fqcn, $method) : [];
                $this->graph->addNode(new Node($id, 'event', $method ? "{$short}@{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'visibility' => 'public',
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                ]));
                break;

            case 'listener':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $method ? $this->extractMethodFlowSteps($fqcn, $method) : [];
                $this->graph->addNode(new Node($id, 'listener', $method ? "{$short}@{$method}" : $short, [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'visibility' => 'public',
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                ]));
                break;

            case 'repository':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $flowSteps = $this->extractMethodFlowSteps($fqcn, $method);
                $repoMetrics = $this->extractMethodMetrics($fqcn, $method);
                $validationRules = $this->validationRulesForFile($file);
                $this->graph->addNode(new Node($id, 'service', "{$short}@{$method}", [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'subtype' => 'repository',
                    'file' => $file,
                    'flowSteps' => $flowSteps,
                    'visibility' => $this->extractVisibility($fqcn, $method),
                    ...($repoMetrics ? ['metrics' => $repoMetrics] : []),
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                    ...($this->isFatMethod($repoMetrics) ? ['fatMethod' => true] : []),
                    ...(empty($validationRules) ? [] : ['validationRules' => $validationRules]),
                ]));
                break;

            case 'validation_request':
                $this->addHopServiceClassNode($id, $fqcn, $method, 'validation_request', 'validation_request');
                break;

            case 'facade':
                $short = class_basename($fqcn);
                $file = $this->resolveFile($fqcn);
                $members = ($file !== '' && is_file($file))
                    ? $this->getStructureInspector()->listClassMethods($file)
                    : [];
                $facadeRecord = $this->facadeRegistry !== null ? $this->facadeRegistry->get($fqcn) : null;
                $methodLocation = $this->findMethodNodeInChain($fqcn, $method);
                $flowSteps = $methodLocation !== null
                    ? $this->flowExtractor->extract($methodLocation['methodNode'], $methodLocation['useMap'])
                    : [];
                $this->graph->addNode(new Node($id, 'facade', "{$short}@{$method}", [
                    'fqcn' => $fqcn,
                    'method' => $method,
                    'file' => $file,
                    'members' => $members,
                    'flowSteps' => $flowSteps,
                    'accessor' => $facadeRecord !== null ? $facadeRecord->accessor : '',
                    'concreteFqcn' => $facadeRecord !== null ? $facadeRecord->concreteFqcn : null,
                    ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                ]));
                if ($methodLocation !== null && $methodLocation['declaringFqcn'] !== $fqcn) {
                    $this->wireInheritedMethodDelegation($id, $methodLocation, $method);
                }
                break;

            default: // service
                $this->addHopServiceClassNode($id, $fqcn, $method, 'service', 'service');
                break;
        }
    }

    /**
     * Service-like lifecycle hops (app services vs Form Request rule hosts).
     *
     * @param  'service'|'validation_request'  $graphNodeType
     * @param  'service'|'validation_request'  $dataSubtype
     */
    private function addHopServiceClassNode(
        string $id,
        string $fqcn,
        string $method,
        string $graphNodeType,
        string $dataSubtype,
    ): void {
        $short = class_basename($fqcn);
        $file = $this->resolveFile($fqcn);
        $methodLocation = $this->findMethodNodeInChain($fqcn, $method);
        $flowSteps = $methodLocation !== null
            ? $this->flowExtractor->extract($methodLocation['methodNode'], $methodLocation['useMap'])
            : [];
        $svcMetrics = $this->extractMethodMetrics($fqcn, $method);
        $validationRules = $this->validationRulesForFile($file);
        $this->graph->addNode(new Node($id, $graphNodeType, "{$short}@{$method}", [
            'fqcn' => $fqcn,
            'method' => $method,
            'subtype' => $dataSubtype,
            'file' => $file,
            'flowSteps' => $flowSteps,
            'visibility' => $this->extractVisibility($fqcn, $method),
            ...($svcMetrics ? ['metrics' => $svcMetrics] : []),
            ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
            ...($this->isFatMethod($svcMetrics) ? ['fatMethod' => true] : []),
            ...(empty($validationRules) ? [] : ['validationRules' => $validationRules]),
        ]));
        if ($methodLocation !== null && $methodLocation['declaringFqcn'] !== $fqcn) {
            $this->wireInheritedMethodDelegation($id, $methodLocation, $method);
        }
    }

    /**
     * The node ID for a hop. Controller actions already have a known ID from step 1;
     * we use the same format so edges connect correctly.
     */
    private function nodeIdForHop(string $fqcn, string $method): string
    {
        if (str_starts_with($fqcn, MethodTracer::BLADE_FQCN_PREFIX)) {
            return $this->viewNodeId($fqcn);
        }

        // Closure route virtual FQCN — the string IS the route node ID already
        if (str_starts_with($fqcn, 'route::')) {
            return $fqcn;
        }

        // Controller action nodes use the existing format
        if ($this->isController($fqcn)) {
            return $this->actionId($fqcn, $method);
        }

        // Everything else: fqcn::method
        return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn)).'::'.$method;
    }

    private function isController(string $fqcn): bool
    {
        return str_contains($fqcn, 'Controller')
            || str_contains($fqcn, '\\Http\\')
            || str_contains($fqcn, '\\Livewire\\');
    }

    private function classifyFqcn(string $fqcn): string
    {
        // API resources live under \Http\Resources\ — check before the controller
        // heuristic, which would otherwise claim any \Http\ class as an action.
        if (str_contains($fqcn, '\\Http\\Resources\\')) {
            return 'resource';
        }
        if ($this->isController($fqcn)) {
            return 'action';
        }
        $surface = $this->declarationSurfaceKind($fqcn);
        if ($surface === 'interface') {
            return 'interface';
        }
        if ($surface === 'enum') {
            return 'enum';
        }
        if ($surface === 'trait') {
            return 'trait';
        }
        if ($surface === 'abstract_class') {
            return 'abstract_class';
        }
        if ($this->looksLikeMailFqcn($fqcn)) {
            return 'mail';
        }
        if ($this->looksLikeNotificationFqcn($fqcn)) {
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
     * Map traced edge types to graph node types (e.g. Form Request classes → validation_request).
     */
    private function effectiveCalleeGraphType(string $fqcn, string $traceType): string
    {
        if ($traceType !== 'service') {
            return $traceType;
        }

        if ($this->facadeRegistry !== null && $this->facadeRegistry->get($fqcn) !== null) {
            return 'facade';
        }

        $file = $this->resolveFile($fqcn);
        if ($file !== '' && is_file($file) && $this->getValidationRulesExtractor()->hasNonAbstractRulesMethod($file)) {
            return 'validation_request';
        }

        return 'service';
    }

    private function edgeLabelForType(string $type): string
    {
        return match ($type) {
            'model' => 'queries',
            'job' => 'dispatches',
            'event' => 'dispatches',
            'resource' => 'transforms',
            'listener' => 'handled by',
            'repository' => 'calls',
            'validation_request' => 'validates',
            'view' => 'renders',
            'mail' => 'sends',
            'notification' => 'notifies',
            'enum' => 'uses',
            'interface' => 'uses',
            'trait' => 'uses',
            'abstract_class' => 'calls',
            'facade' => 'calls',
            default => 'calls',
        };
    }

    private function getStructureInspector(): PhpStructureInspector
    {
        return $this->structureInspector ??= new PhpStructureInspector($this->parser);
    }

    private function getValidationRulesExtractor(): ValidationRulesExtractor
    {
        return $this->validationRulesExtractor ??= new ValidationRulesExtractor($this->parser);
    }

    /**
     * @return list<array{field: string, rules: string}>
     */
    private function validationRulesForFile(string $file): array
    {
        if ($file === '' || ! is_file($file)) {
            return [];
        }

        return $this->getValidationRulesExtractor()->extractFromFile($file);
    }

    /**
     * Enum, interface, trait, or abstract class as the primary declaration in the FQCN's file.
     *
     * @return 'enum'|'interface'|'trait'|'abstract_class'|null
     */
    private function declarationSurfaceKind(string $fqcn): ?string
    {
        if (array_key_exists($fqcn, $this->surfaceKindCache)) {
            return $this->surfaceKindCache[$fqcn];
        }
        $file = $this->resolveFile($fqcn);
        if ($file === '' || ! is_file($file)) {
            return $this->surfaceKindCache[$fqcn] = null;
        }
        $info = $this->getStructureInspector()->inspectFile($file);
        if ($info === null) {
            return $this->surfaceKindCache[$fqcn] = null;
        }
        $k = $info['kind'];
        if (! in_array($k, ['enum', 'interface', 'trait', 'abstract_class'], true)) {
            return $this->surfaceKindCache[$fqcn] = null;
        }

        return $this->surfaceKindCache[$fqcn] = $k;
    }

    private function hopCalleeNodeId(CallChainEdge $edge): string
    {
        return match ($edge->type) {
            'enum' => $this->enumNodeId($edge->calleeFqcn),
            'view' => $this->viewNodeId($edge->calleeFqcn),
            'interface' => $this->nodeIdForHop($edge->calleeFqcn, $edge->calleeMethod),
            'trait' => $this->traitNodeId($edge->calleeFqcn),
            default => $this->nodeIdForHop($edge->calleeFqcn, $edge->calleeMethod),
        };
    }

    private function maybeWireContainerBinding(CallChainEdge $edge, array $models): void
    {
        if ($this->bindingRegistry === null) {
            return;
        }

        $abstract = $edge->calleeFqcn;
        if ($abstract === '' || str_starts_with($abstract, MethodTracer::BLADE_FQCN_PREFIX)) {
            return;
        }

        $surface = $this->declarationSurfaceKind($abstract);
        $isAbstraction = $edge->type === 'interface'
            || $edge->type === 'abstract_class'
            || $surface === 'interface'
            || $surface === 'abstract_class';

        if (! $isAbstraction) {
            return;
        }

        $record = $this->bindingRegistry->get($abstract);
        if ($record === null) {
            return;
        }

        $from = $this->hopCalleeNodeId($edge);
        if (! $this->graph->hasNode($from)) {
            return;
        }

        $provId = $this->ensureServiceProviderNode($record->providerFqcn);
        $regKey = $from.'|in|'.$provId;
        if (! isset($this->seenBindingWires[$regKey])) {
            $this->seenBindingWires[$regKey] = true;
            $label = $this->bindingRegistrationLabel($record);
            $this->addEdge($from, $provId, $label, 'binding-registered-in');
        }

        $concrete = $record->concreteFqcn;
        if ($concrete === null || $concrete === $abstract) {
            return;
        }

        $concreteClassified = $this->classifyFqcn($concrete);
        $concreteType = $this->effectiveCalleeGraphType($concrete, $concreteClassified);
        $this->ensureNode($concrete, $edge->calleeMethod, $concreteType, $models);
        $to = $this->nodeIdForHop($concrete, $edge->calleeMethod);
        $resKey = $from.'|res|'.$to;
        if (isset($this->seenBindingWires[$resKey])) {
            return;
        }
        $this->seenBindingWires[$resKey] = true;
        $short = class_basename($concrete);
        $this->addEdge(
            $from,
            $to,
            '→ '.$short.'::'.$edge->calleeMethod,
            'binding-resolution',
        );
    }

    private function maybeWireFacadeResolution(CallChainEdge $edge, array $models): void
    {
        if ($this->facadeRegistry === null) {
            return;
        }

        $record = $this->facadeRegistry->get($edge->calleeFqcn);
        if ($record === null) {
            return;
        }

        $concrete = $record->concreteFqcn;
        if ($concrete === null) {
            return;
        }

        $from = $this->hopCalleeNodeId($edge);
        if (! $this->graph->hasNode($from)) {
            return;
        }

        $concreteClassified = $this->classifyFqcn($concrete);
        $concreteType = $this->effectiveCalleeGraphType($concrete, $concreteClassified);
        $this->ensureNode($concrete, $edge->calleeMethod, $concreteType, $models);
        $to = $this->nodeIdForHop($concrete, $edge->calleeMethod);

        $key = $from.'|facade-resolves-to|'.$to;
        if (isset($this->seenBindingWires[$key])) {
            return;
        }
        $this->seenBindingWires[$key] = true;

        $short = class_basename($concrete);
        $this->addEdge(
            $from,
            $to,
            '→ '.$short.'::'.$edge->calleeMethod,
            'facade-resolves-to',
        );
    }

    /**
     * When a method is inherited from a parent class, create a node for the
     * declaring class and wire an "inherits-method" edge.
     *
     * @param  array{methodNode: mixed, useMap: array<string,string>, file: string, declaringFqcn: string}  $methodLocation
     */
    private function wireInheritedMethodDelegation(string $childNodeId, array $methodLocation, string $method): void
    {
        $declaringFqcn = $methodLocation['declaringFqcn'];
        $parentNodeId = $this->nodeIdForHop($declaringFqcn, $method);

        $key = $childNodeId.'|inherits-method|'.$parentNodeId;
        if (isset($this->seenBindingWires[$key])) {
            return;
        }
        $this->seenBindingWires[$key] = true;

        if (! $this->graph->hasNode($parentNodeId)) {
            $short = class_basename($declaringFqcn);
            $flowSteps = $this->flowExtractor->extract($methodLocation['methodNode'], $methodLocation['useMap']);
            $classified = $this->classifyFqcn($declaringFqcn);
            $parentType = $this->effectiveCalleeGraphType($declaringFqcn, $classified);
            $this->graph->addNode(new Node($parentNodeId, $parentType, "{$short}@{$method}", [
                'fqcn' => $declaringFqcn,
                'method' => $method,
                'file' => $methodLocation['file'],
                'members' => [],
                'flowSteps' => $flowSteps,
                ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
            ]));
        }

        $short = class_basename($declaringFqcn);
        $this->addEdge(
            $childNodeId,
            $parentNodeId,
            '→ '.$short.'::'.$method,
            'inherits-method',
        );
    }

    private function ensureServiceProviderNode(string $providerFqcn): string
    {
        $id = $this->serviceProviderId($providerFqcn);
        if ($this->graph->hasNode($id)) {
            return $id;
        }

        $short = class_basename($providerFqcn);
        $this->graph->addNode(new Node($id, 'service_provider', $short, [
            'fqcn' => $providerFqcn,
            'file' => $this->resolveFile($providerFqcn),
        ]));

        return $id;
    }

    private function serviceProviderId(string $fqcn): string
    {
        return 'service_provider::'.strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn));
    }

    private function bindingRegistrationLabel(ContainerBindingRecord $record): string
    {
        $kind = match ($record->kind) {
            'singleton', 'singletons' => 'Singleton',
            'scoped' => 'Scoped',
            default => 'Bind',
        };
        $prov = class_basename($record->providerFqcn);

        return "{$kind} in {$prov}";
    }

    private function viewNodeId(string $bladeFqcn): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9._]/', '_', $bladeFqcn));

        return 'view::'.$slug;
    }

    private function enumNodeId(string $fqcn): string
    {
        return 'enum::'.strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn));
    }

    private function interfaceNodeId(string $fqcn): string
    {
        return 'interface::'.strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn));
    }

    private function traitNodeId(string $fqcn): string
    {
        return 'trait::'.strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn));
    }

    private function abstractClassDeclarationNodeId(string $fqcn): string
    {
        return 'abstract_class::'.strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', $fqcn));
    }

    private function looksLikeMailFqcn(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Mail\\')
            || str_contains($fqcn, '\\Mails\\')
            || str_contains($fqcn, 'Mailable');
    }

    private function looksLikeNotificationFqcn(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Notifications\\');
    }

    /**
     * @param  array<string, ControllerDefinition>  $controllers
     * @param  CallChainEdge[]  $callChain
     */
    private function supplementEnumAndInterfaceNodes(array $controllers, array $callChain): void
    {
        $seen = [];
        foreach ($callChain as $e) {
            foreach ([$e->callerFqcn, $e->calleeFqcn] as $fq) {
                if ($fq === '' || str_starts_with($fq, MethodTracer::BLADE_FQCN_PREFIX)) {
                    continue;
                }
                $seen[$fq] = true;
            }
        }
        foreach ($controllers as $def) {
            foreach ($def->constructorDeps as $short) {
                $fq = $def->useMap[$short] ?? $short;
                if (is_string($fq) && str_contains($fq, '\\')) {
                    $seen[$fq] = true;
                }
            }
            foreach ($def->methods as $m) {
                foreach ($m->dependencies as $short) {
                    $fq = $def->useMap[$short] ?? $short;
                    if (is_string($fq) && str_contains($fq, '\\')) {
                        $seen[$fq] = true;
                    }
                }
            }
        }

        foreach (array_keys($seen) as $fqcn) {
            $file = $this->resolveFile($fqcn);
            if ($file === '' || ! is_file($file)) {
                continue;
            }
            $info = $this->getStructureInspector()->inspectFile($file);
            if ($info === null || ! in_array($info['kind'], ['enum', 'interface', 'trait', 'abstract_class'], true)) {
                continue;
            }
            $kind = $info['kind'];
            // Interface nodes are created per-method in ensureNode; skip class-level stubs here.
            if ($kind === 'interface') {
                continue;
            }
            $nid = match ($kind) {
                'enum' => $this->enumNodeId($fqcn),
                'trait' => $this->traitNodeId($fqcn),
                default => $this->abstractClassDeclarationNodeId($fqcn),
            };
            if ($nid === '') {
                continue;
            }
            if ($this->graph->hasNode($nid)) {
                continue;
            }
            $this->graph->addNode(new Node($nid, $kind, class_basename($fqcn), [
                'fqcn' => $fqcn,
                'file' => $file,
                'members' => $info['members'],
            ]));
        }
    }

    /**
     * Link controller actions to Form Request (or similar) classes type-hinted on the action or constructor
     * when they declare a concrete {@see rules()} method — mirrors container injection for validation.
     *
     * @param  array<string, ModelDefinition>  $models
     */
    private function wireActionFormRequests(
        string $action,
        string $actionId,
        ?ControllerDefinition $def,
        array $models,
    ): void {
        if ($def === null) {
            return;
        }

        $methodDef = null;
        foreach ($def->methods as $m) {
            if ($m->name === $action) {
                $methodDef = $m;
                break;
            }
        }

        $shortNames = array_merge(
            array_values($def->constructorDeps),
            $methodDef !== null ? array_values($methodDef->dependencies) : [],
        );

        foreach (array_unique($shortNames, SORT_STRING) as $short) {
            $fqcn = $def->useMap[$short] ?? $short;
            if (! is_string($fqcn) || ! str_contains($fqcn, '\\')) {
                continue;
            }
            if ($this->isFrameworkDependencyFqcn($fqcn)) {
                continue;
            }

            $file = $this->resolveFile($fqcn);
            if ($file === '' || ! is_file($file)) {
                continue;
            }
            if (! $this->getValidationRulesExtractor()->hasNonAbstractRulesMethod($file)) {
                continue;
            }

            // Always wire to the `validated` node so duplicate `rules` nodes are not created.
            // `ensureNode` is a no-op when the node was already created by flow tracing.
            $targetMethod = 'validated';
            $targetId = $this->nodeIdForHop($fqcn, $targetMethod);
            if ($this->hasDirectedEdge($actionId, $targetId)) {
                continue;
            }

            $this->ensureNode($fqcn, $targetMethod, 'validation_request', $models);
            $this->addEdge($actionId, $targetId, 'validates', 'action-to-form-request');
        }
    }

    private function hasDirectedEdge(string $source, string $target): bool
    {
        return $this->graph->hasDirectedEdge($source, $target);
    }

    private function isFrameworkDependencyFqcn(string $fqcn): bool
    {
        return str_starts_with($fqcn, 'Illuminate\\')
            || str_starts_with($fqcn, 'Laravel\\')
            || in_array($fqcn, ['Request', 'Response', 'Validator', 'Auth', 'DB', 'Cache', 'Log', 'Storage'], true);
    }

    private function wireControllerInterfaceHints(array $routes, array $controllers): void
    {
        foreach ($routes as $route) {
            if ($route->controller === '' || $route->controller === 'Closure') {
                continue;
            }
            $def = $controllers[$route->controller] ?? null;
            if ($def === null) {
                continue;
            }
            $cid = $this->controllerId($route->controller);
            if (! $this->graph->hasNode($cid)) {
                continue;
            }
            $hints = array_merge(
                array_values($def->constructorDeps),
                ...array_map(fn ($m) => array_values($m->dependencies), $def->methods)
            );
            foreach (array_unique($hints) as $short) {
                $fq = $def->useMap[$short] ?? $short;
                if (! is_string($fq) || ! str_contains($fq, '\\')) {
                    continue;
                }
                $file = $this->resolveFile($fq);
                if ($file === '' || ! is_file($file)) {
                    continue;
                }
                $info = $this->getStructureInspector()->inspectFile($file);
                if (($info['kind'] ?? '') !== 'interface') {
                    continue;
                }
                $iid = $this->interfaceNodeId($fq);
                if (! $this->graph->hasNode($iid)) {
                    $this->graph->addNode(new Node($iid, 'interface', class_basename($fq), [
                        'fqcn' => $fq,
                        'file' => $file,
                        'members' => $info['members'],
                    ]));
                }
                $this->addEdge($cid, $iid, 'type-hint', 'controller-to-interface');
            }
        }
    }

    private function resolveBladePath(string $viewDot): ?string
    {
        $root = $this->projectRoot;
        if ($root === '') {
            return null;
        }

        $bladeRel = static fn (string $dotted): string => str_replace('.', '/', $dotted).'.blade.php';
        $viewRoots = SourceDirectories::resolve($root, $this->viewPaths);

        if (str_contains($viewDot, '::')) {
            [$hint, $path] = explode('::', $viewDot, 2);
            if ($path === '') {
                return null;
            }
            $rel = $bladeRel($path);
            $moduleStudly = Str::studly($hint);

            $namespacedCandidates = [
                $root.'/Modules/'.$moduleStudly.'/resources/views/'.$rel,
                $root.'/resources/views/vendor/'.$hint.'/'.$rel,
            ];

            foreach ($namespacedCandidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            $pattern = $root.'/Modules/*/resources/views/'.$rel;
            foreach (glob($pattern) ?: [] as $match) {
                if (is_file($match)) {
                    return $match;
                }
            }

            // A namespace hint is bound to its directory at runtime, by whichever provider
            // registered it, so it cannot be mapped to a path in general. What is left is
            // the file name, and a hint that names one of the roots.
            return $this->uniqueExistingView($root, $viewRoots, $rel, $hint);
        }

        $rel = $bladeRel($viewDot);
        $candidates = [
            $root.'/resources/views/'.$rel,
            $root.'/resources/views/vendor/'.$rel,
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return $this->uniqueExistingView($root, $viewRoots, $rel);
    }

    /**
     * The one view root that holds a template, or null when more than one does.
     *
     * Two roots with the same relative template have no single right answer, and picking by
     * array order produces a confidently wrong edge — worse to read, in a graph answering
     * "what renders this?", than an edge that is simply absent. A hint settles it only when
     * it names exactly one of the roots.
     *
     * @param  string[]  $viewRoots  relative to the project root
     */
    private function uniqueExistingView(string $root, array $viewRoots, string $rel, ?string $hint = null): ?string
    {
        $matches = [];

        foreach ($viewRoots as $viewRoot) {
            $candidate = $root.'/'.trim($viewRoot, '/').'/'.$rel;
            if (is_file($candidate)) {
                $matches[$viewRoot] = $candidate;
            }
        }

        if ($matches === []) {
            return null;
        }

        if ($hint !== null) {
            $named = array_filter(
                $matches,
                fn (string $viewRoot): bool => $this->viewRootCarriesHint($viewRoot, $hint),
                ARRAY_FILTER_USE_KEY,
            );

            if (count($named) === 1) {
                return reset($named);
            }
        }

        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * Whether a view root is named after a namespace hint.
     *
     * Whole path segments only: a substring test accepts `packages/billing` for a hint of
     * `billing-pro`. A namespace is conventionally `<vendor>-<package>` while the directory
     * carries the package alone, so that one spelling is tried too.
     */
    private function viewRootCarriesHint(string $viewRoot, string $hint): bool
    {
        $hint = Str::kebab($hint);
        $names = [$hint];

        if (str_contains($hint, '-')) {
            $names[] = substr($hint, strpos($hint, '-') + 1);
        }

        return array_intersect($names, explode('/', trim($viewRoot, '/'))) !== [];
    }

    /**
     * @return list<array{kind: string, name: string}>
     */
    private function parseBladeRefs(string $bladePath): array
    {
        $content = @file_get_contents($bladePath);
        if ($content === false || $content === '') {
            return [];
        }
        $refs = [];
        if (preg_match_all('/@(?:include|extends|component|includeIf|each)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
            foreach ($m[1] as $name) {
                $refs[] = ['kind' => 'blade_ref', 'name' => $name];
            }
        }

        return $refs;
    }

    /**
     * Wire view → view "renders" edges from the composition map produced by
     * BladeViewAnalyzer. To stay route-anchored, only views already reached from
     * an action seed the walk; the tree is then descended transitively, so every
     * nested component reachable from a rendered view is linked without adding
     * orphan views that no route renders.
     *
     * @param  array<string, list<string>>  $childrenByParent  parent view name => child view names
     */
    public function addViewComposition(array $childrenByParent): void
    {
        $seen = [];
        $queue = [];
        foreach ($this->graph->nodes() as $node) {
            if ($node->type === 'view' && isset($node->data['view'])) {
                $dot = (string) $node->data['view'];
                if (! isset($seen[$dot])) {
                    $seen[$dot] = true;
                    $queue[] = $dot;
                }
            }
        }

        while ($queue !== []) {
            $parent = array_shift($queue);
            $children = $childrenByParent[$parent] ?? [];
            if ($children === []) {
                continue;
            }
            $parentId = $this->ensureBladeViewNode($parent);
            foreach ($children as $child) {
                $childId = $this->ensureBladeViewNode($child);
                $this->addEdge($parentId, $childId, 'renders', 'view-to-view');
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }
    }

    private function ensureBladeViewNode(string $viewDot): string
    {
        $id = $this->viewNodeId(MethodTracer::BLADE_FQCN_PREFIX.$viewDot);
        if (! $this->graph->hasNode($id)) {
            $blade = $this->resolveBladePath($viewDot);
            $refs = $blade !== null ? $this->parseBladeRefs($blade) : [];
            $this->graph->addNode(new Node($id, 'view', $viewDot, [
                'view' => $viewDot,
                'file' => $blade ?? '',
                'members' => $refs,
            ]));
        }

        return $id;
    }

    // ── Existing node adders ──────────────────────────────────────────────────

    private function addRouteNode(RouteDefinition $route, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }

        $nodeData = [
            'method' => $route->method,
            'uri' => $route->uri,
            'name' => $route->name,
            'file' => $route->file,
            'line' => $route->line,
        ];

        if ($route->closureNode !== null) {
            $flowSteps = $this->flowExtractor->extractFromClosure($route->closureNode);
            if ($flowSteps !== []) {
                $nodeData['flowSteps'] = $flowSteps;
            }
            if ($this->hasN1InSteps($flowSteps)) {
                $nodeData['hasN1'] = true;
            }
        }

        // Attach security surface map data when available
        if (isset($this->securityMap[$id])) {
            $sec = $this->securityMap[$id];
            $nodeData['security'] = [
                'exposure' => $sec['exposure'],
                'riskLevel' => $sec['riskLevel'],
                'issues' => $sec['issues'],
            ];
        }

        $this->graph->addNode(new Node($id, 'route', "{$route->method} {$route->uri}", $nodeData));
    }

    private function isLivewireComponent(?ControllerDefinition $def, string $fqcn): bool
    {
        if ($def !== null && $def->parent !== null) {
            return str_starts_with($def->parent, 'Livewire\\');
        }

        $file = $this->resolveFile($fqcn);
        if ($file === '' || ! is_file($file)) {
            return false;
        }
        $contents = file_get_contents($file);

        return $contents !== false
            && str_contains($contents, 'use Livewire\\')
            && (bool) preg_match('/extends\s+\w*Component\b/', $contents);
    }

    private function addControllerNode(string $fqcn, ?ControllerDefinition $def, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        $short = class_basename($fqcn);
        $file = ($def !== null && $def->fqcn === $fqcn)
            ? $def->file
            : $this->resolveFile($fqcn);
        if ($file === '') {
            $file = $this->resolveFile($fqcn);
        }

        $type = $this->isLivewireComponent($def, $fqcn) ? 'livewire_component' : 'controller';

        $this->graph->addNode(new Node($id, $type, $short, [
            'fqcn' => $fqcn,
            'file' => $file,
        ]));
    }

    /**
     * @param  array<string, ControllerDefinition>  $controllers
     */
    private function wireControllerAncestorEdges(string $routeControllerFqcn, ?ControllerDefinition $def, array $controllers): void
    {
        if ($def === null || $def->ancestorFqcns === []) {
            return;
        }
        $prev = $routeControllerFqcn;
        foreach ($def->ancestorFqcns as $ancestorFqcn) {
            $parentDef = $controllers[$ancestorFqcn] ?? null;
            $parentId = $this->controllerId($ancestorFqcn);
            $this->addControllerNode($ancestorFqcn, $parentDef, $parentId);
            $sig = $this->controllerId($prev).'|'.$parentId;
            if (! isset($this->seenControllerExtendsEdges[$sig])) {
                $this->seenControllerExtendsEdges[$sig] = true;
                $this->addEdge($this->controllerId($prev), $parentId, 'extends', 'controller-extends');
            }
            $prev = $ancestorFqcn;
        }
    }

    private function declaringFqcnForAction(?ControllerDefinition $def, string $action): ?string
    {
        if ($def === null) {
            return null;
        }
        foreach ($def->methods as $methodDef) {
            if ($methodDef->name === $action && $methodDef->ast !== null) {
                return $methodDef->declaringFqcn ?? $def->fqcn;
            }
        }

        return null;
    }

    private function addActionNode(string $fqcn, string $action, ?ControllerDefinition $def, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }

        $declaringFqcn = $this->declaringFqcnForAction($def, $action) ?? $fqcn;
        $declaringBasename = class_basename($declaringFqcn);
        $routeBasename = class_basename($fqcn);
        $label = $declaringBasename.'@'.$action;
        if ($declaringFqcn !== $fqcn) {
            $label .= ' ← '.$routeBasename;
        }

        $flowSteps = [];
        $metrics = [];
        if ($def !== null) {
            foreach ($def->methods as $methodDef) {
                if ($methodDef->name === $action && $methodDef->ast !== null) {
                    $um = $methodDef->methodUseMap ?? $def->useMap;
                    $flowSteps = $this->flowExtractor->extract($methodDef->ast, $um);
                    $metrics = $this->flowExtractor->metrics($methodDef->ast);
                    break;
                }
            }
        }

        $metricsControllerId = $this->controllerId($declaringFqcn);
        if (! empty($metrics)) {
            $this->classMetrics[$metricsControllerId] ??= ['totalLines' => 0, 'methodCount' => 0];
            $this->classMetrics[$metricsControllerId]['totalLines'] += $metrics['lineCount'];
            $this->classMetrics[$metricsControllerId]['methodCount'] += 1;
        }

        $declaringFile = $this->resolveFile($declaringFqcn);
        $nodeData = [
            'fqcn' => $fqcn,
            'declaringFqcn' => $declaringFqcn,
            'method' => $action,
            'file' => $declaringFile !== '' ? $declaringFile : ($def !== null ? $def->file : $this->resolveFile($fqcn)),
            'flowSteps' => $flowSteps,
            'visibility' => $this->findActionVisibility($action, $def),
        ];

        if (! empty($metrics)) {
            $nodeData['metrics'] = $metrics;
        }

        if ($this->hasN1InSteps($flowSteps)) {
            $nodeData['hasN1'] = true;
        }

        if ($this->isFatMethod($metrics)) {
            $nodeData['fatMethod'] = true;
        }

        $actionKey = $fqcn.'::'.$action;
        if (! empty($this->dbQueryMap[$actionKey])) {
            $nodeData['dbQueries'] = array_values(
                array_map(fn (DbQuery $q) => $q->toArray(), $this->dbQueryMap[$actionKey])
            );
        }

        $this->graph->addNode(new Node($id, 'action', $label, $nodeData));
    }

    private function findActionVisibility(string $action, ?ControllerDefinition $def): string
    {
        if ($def === null) {
            return 'public';
        }
        foreach ($def->methods as $m) {
            if ($m->name === $action) {
                return $m->visibility;
            }
        }

        return 'public';
    }

    private function extractVisibility(string $fqcn, string $method): string
    {
        // The declaringFqcn check keeps the original semantics: only a method declared in
        // $fqcn's own file has a visibility here; an inherited one reports 'public'.
        $found = $this->findMethodNodeInChain($fqcn, $method);
        if ($found === null || $found['declaringFqcn'] !== $fqcn) {
            return 'public';
        }

        $node = $found['methodNode'];
        if ($node->isPrivate()) {
            return 'private';
        }
        if ($node->isProtected()) {
            return 'protected';
        }

        return 'public';
    }

    private function hasN1InSteps(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step['n1'] ?? false) {
                return true;
            }
            if ($step['type'] === 'if') {
                if ($this->hasN1InSteps($step['then'] ?? [])) {
                    return true;
                }
                if ($this->hasN1InSteps($step['else'] ?? [])) {
                    return true;
                }
            }
            if ($step['type'] === 'loop' && isset($step['body'])) {
                if ($this->hasN1InSteps($step['body'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A method is "fat" if it has more than 30 lines OR cyclomatic complexity > 10.
     */
    private function isFatMethod(array $metrics): bool
    {
        if (empty($metrics)) {
            return false;
        }

        return ($metrics['lineCount'] > 30) || ($metrics['cyclomaticComplexity'] > 10);
    }

    private function addMiddlewareNode(string $fqcn, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        // $fqcn may carry parameters, e.g. "CheckForAnyAbility:view-X,create-Y"
        $parts = explode(':', $fqcn, 2);
        $classPart = $parts[0];
        $params = $parts[1] ?? null;
        $short = class_basename($classPart);
        $this->graph->addNode(new Node($id, 'middleware', $short, [
            'fqcn' => $classPart,
            'params' => $params,
            'file' => $this->resolveFile($classPart),
        ]));
    }

    private function addModelNode(string $fqcn, ?ModelDefinition $def, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        $short = class_basename($fqcn);
        $this->graph->addNode(new Node($id, 'model', $short, [
            'fqcn' => $fqcn,
            'file' => $def !== null ? $def->file : $this->resolveFile($fqcn),
            'relationships' => $def !== null ? $def->relationships : [],
        ]));
    }

    private function addEventNode(string $fqcn, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        $short = class_basename($fqcn);
        $this->graph->addNode(new Node($id, 'event', $short, [
            'fqcn' => $fqcn,
            'file' => $this->resolveFile($fqcn),
        ]));
    }

    /**
     * Wire model → observer edges discovered by ObserverAnalyzer. The edge
     * shares the canonical model node (model::FQCN), so observers sit alongside
     * the model's fired-event and relationship edges rather than on the mangled
     * hop node a call chain would create.
     *
     * @param  array<string, list<string>>  $observerMap  model FQCN => observer FQCNs
     */
    public function addObservers(array $observerMap): void
    {
        foreach ($observerMap as $modelFqcn => $observerFqcns) {
            $modelId = $this->modelId($modelFqcn);
            if (! $this->graph->hasNode($modelId)) {
                $this->addModelNode($modelFqcn, null, $modelId);
            }
            foreach ($observerFqcns as $observerFqcn) {
                $observerId = $this->observerId($observerFqcn);
                $this->addObserverNode($observerFqcn, $observerId);
                $this->addEdge($modelId, $observerId, 'observed by', 'model-to-observer');
            }
        }
    }

    private function addObserverNode(string $fqcn, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        $short = class_basename($fqcn);
        $this->graph->addNode(new Node($id, 'observer', $short, [
            'fqcn' => $fqcn,
            'file' => $this->resolveFile($fqcn),
            'members' => $this->observerMembers($fqcn),
        ]));
    }

    /**
     * The Eloquent lifecycle hooks an observer actually implements (created,
     * updated, deleted, …), so the node can show which events it handles.
     *
     * @return list<array{name: string, static: bool, visibility: string}>
     */
    private function observerMembers(string $fqcn): array
    {
        $file = $this->resolveFile($fqcn);
        if ($file === '' || ! is_file($file)) {
            return [];
        }

        return $this->getStructureInspector()->listClassMethods($file);
    }

    /**
     * Wire model → policy edges discovered by PolicyAnalyzer. The edge shares
     * the canonical model node (model::FQCN), so a model's authorization policy
     * sits alongside its fired-event and relationship edges.
     *
     * @param  array<string, string>  $policyMap  model FQCN => policy FQCN
     */
    public function addPolicies(array $policyMap): void
    {
        foreach ($policyMap as $modelFqcn => $policyFqcn) {
            $modelId = $this->modelId($modelFqcn);
            if (! $this->graph->hasNode($modelId)) {
                $this->addModelNode($modelFqcn, null, $modelId);
            }
            $policyId = $this->policyId($policyFqcn);
            $this->addPolicyNode($policyFqcn, $policyId);
            $this->addEdge($modelId, $policyId, 'authorized by', 'model-to-policy');
        }
    }

    private function addPolicyNode(string $fqcn, string $id): void
    {
        if ($this->graph->hasNode($id)) {
            return;
        }
        $short = class_basename($fqcn);
        $file = $this->resolveFile($fqcn);
        $members = ($file !== '' && is_file($file))
            ? $this->getStructureInspector()->listClassMethods($file)
            : [];
        $this->graph->addNode(new Node($id, 'policy', $short, [
            'fqcn' => $fqcn,
            'file' => $file,
            'members' => $members,
        ]));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveMiddlewares(array $middlewares, MiddlewareRegistry $registry): array
    {
        $resolved = [];
        foreach ($middlewares as $mw) {
            // Split only on the first colon so params like 'ability:view-X,create-Y'
            // or 'throttle:60,1' are preserved after the alias is resolved.
            $parts = explode(':', $mw, 2);
            $alias = $parts[0];
            $params = $parts[1] ?? null;
            $fqcn = $registry->resolveAlias($alias);
            $resolved[] = $params !== null ? "{$fqcn}:{$params}" : $fqcn;
        }

        return array_unique($resolved);
    }

    private function addEdge(string $source, string $target, string $label, string $type): void
    {
        // Prevent edges to/from non-existent nodes (safety guard)
        if (! $this->graph->hasNode($source) || ! $this->graph->hasNode($target)) {
            return;
        }

        // Content-addressed id (graph format v2): derived from the edge itself rather than an
        // insertion counter, so an edge keeps its id as unrelated edges come and go. Identical
        // edges, which the graph keeps on purpose, take a per-occurrence suffix.
        $key = $source."\x1f".$target."\x1f".$type."\x1f".$label;
        $occurrence = $this->edgeIdOccurrence[$key] ?? 0;
        $hash = hash('xxh128', $key);

        // Not "e2_": a v1 id read "e{N}_...", so that prefix already belonged to the third edge.
        $id = 'e_'.($occurrence === 0 ? $hash : $hash.'_'.$occurrence);

        if ($this->graph->hasEdge($id)) {
            return;
        }

        $this->graph->addEdge(new Edge($id, $source, $target, $label, $type));
        $this->edgeIdOccurrence[$key] = $occurrence + 1;
    }

    // ── Console commands ──────────────────────────────────────────────────────

    /**
     * @param  ConsoleCommandDefinition[]  $commands
     * @param  ScheduleEntry[]  $schedules
     * @param  CallChainEdge[]  $callEdges  edges discovered by tracing handle() methods
     */
    public function addConsoleCommands(array $commands, array $schedules, array $callEdges = []): void
    {
        // Build a map from command FQCN → node ID so edges can reference back to command nodes
        $classToCmdId = [];

        foreach ($commands as $cmd) {
            $id = "command::{$cmd->signature}";
            $flowSteps = [];
            $metrics = [];
            $hasN1 = false;

            if ($cmd->class) {
                $flowSteps = $this->extractMethodFlowSteps($cmd->class, 'handle');
                $metrics = $this->extractMethodMetrics($cmd->class, 'handle');
                $hasN1 = $this->hasN1InSteps($flowSteps);
                $classToCmdId[$cmd->class] = $id;
            }

            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'command', $cmd->signature, [
                    'signature' => $cmd->signature,
                    'description' => $cmd->description,
                    'class' => $cmd->class,
                    'file' => $cmd->file,
                    // Where the definition was found ('class' | 'route' | 'kernel'). Not the
                    // command's code: `source` read as source is what put the literal string
                    // "class" into AI context exports inside a ```php fence.
                    'definedIn' => $cmd->source,
                    'flowSteps' => $flowSteps,
                    'metrics' => $metrics ?: null,
                    'hasN1' => $hasN1,
                    'fatMethod' => ! empty($metrics) && $this->isFatMethod($metrics),
                ]));
            }
        }

        // Wire up call chain edges from command handle() methods
        foreach ($callEdges as $edge) {
            $callerNode = $classToCmdId[$edge->callerFqcn]
                ?? $this->nodeIdForHop($edge->callerFqcn, $edge->callerMethod);
            $calleeNode = $this->hopCalleeNodeId($edge);

            $calleeGraphType = $this->effectiveCalleeGraphType($edge->calleeFqcn, $edge->type);
            $this->ensureNode($edge->calleeFqcn, $edge->calleeMethod, $calleeGraphType, []);

            if (! $this->graph->hasNode($callerNode) && ! isset($classToCmdId[$edge->callerFqcn])) {
                $callerClassified = $this->classifyFqcn($edge->callerFqcn);
                $callerGraphType = $this->effectiveCalleeGraphType($edge->callerFqcn, $callerClassified);
                $this->ensureNode($edge->callerFqcn, $edge->callerMethod, $callerGraphType, []);
            }

            $this->addEdge($callerNode, $calleeNode, $this->edgeLabelForType($calleeGraphType), 'command-to-'.$calleeGraphType);
        }

        foreach ($schedules as $entry) {
            $schedId = 'schedule::'.md5($entry->type.$entry->target.$entry->frequency);

            if (! $this->graph->hasNode($schedId)) {
                $label = $entry->frequency
                    ? "{$entry->target} ({$entry->frequency})"
                    : $entry->target;

                $this->graph->addNode(new Node($schedId, 'schedule', $label, [
                    'type' => $entry->type,
                    'target' => $entry->target,
                    'frequency' => $entry->frequency,
                    'file' => $entry->file,
                ]));
            }

            // Edge: schedule → command/job node if it exists
            $targetId = "command::{$entry->target}";
            if ($this->graph->hasNode($targetId)) {
                $this->addEdge($schedId, $targetId, 'runs', 'schedule-to-command');
            }
        }
    }

    // ── Filament page call chains ─────────────────────────────────────────────

    /**
     * Wire call-chain edges discovered by tracing Filament page class methods.
     *
     * Each page class is traced like a controller action; the caller node is mapped
     * to the existing filament_page::{fqcn} node rather than creating a new action
     * node. Callees (services, models, jobs, events) are created as needed.
     *
     * @param  CallChainEdge[]  $edges
     * @param  array<string, string>  $pageNodeIds  FQCN => "filament_page::{fqcn}"
     */
    public function addFilamentPageCallChain(array $edges, array $pageNodeIds): void
    {
        foreach ($edges as $edge) {
            // Prefer the method-level node if it was created in addFilament().
            // Fall back to the page node, then to the generic hop ID for deep chains.
            $methodNodeId = $this->filamentPageMethodId($edge->callerFqcn, $edge->callerMethod);
            if ($this->graph->hasNode($methodNodeId)) {
                $callerNode = $methodNodeId;
            } else {
                $callerNode = $pageNodeIds[$edge->callerFqcn]
                    ?? $this->nodeIdForHop($edge->callerFqcn, $edge->callerMethod);
            }

            $calleeNode = $this->hopCalleeNodeId($edge);

            $calleeGraphType = $this->effectiveCalleeGraphType($edge->calleeFqcn, $edge->type);
            // Ensure callee node exists (model, service, job, event, etc.)
            $this->ensureNode($edge->calleeFqcn, $edge->calleeMethod, $calleeGraphType, []);

            // For intermediate service/repo callers in deep chains
            if (! $this->graph->hasNode($callerNode) && ! isset($pageNodeIds[$edge->callerFqcn])) {
                $callerClassified = $this->classifyFqcn($edge->callerFqcn);
                $callerGraphType = $this->effectiveCalleeGraphType($edge->callerFqcn, $callerClassified);
                $this->ensureNode($edge->callerFqcn, $edge->callerMethod, $callerGraphType, []);
            }

            $this->addEdge(
                $callerNode,
                $calleeNode,
                $this->edgeLabelForType($calleeGraphType),
                'filament-page-to-'.$calleeGraphType,
            );
        }
    }

    // ── Broadcast channels ────────────────────────────────────────────────────

    /**
     * @param  ChannelDefinition[]  $channels
     * @param  CallChainEdge[]  $callEdges  edges discovered by tracing __invoke()/__join() methods
     */
    public function addChannels(array $channels, array $callEdges = []): void
    {
        // Build a map from channel FQCN → node ID
        $classToChannelId = [];

        foreach ($channels as $ch) {
            $id = 'channel::'.md5($ch->name);
            $flowSteps = [];

            if ($ch->class) {
                // Try __invoke first (class-based channels), then join() as fallback
                $flowSteps = $this->extractMethodFlowSteps($ch->class, '__invoke');
                if (empty($flowSteps)) {
                    $flowSteps = $this->extractMethodFlowSteps($ch->class, 'join');
                }
                $classToChannelId[$ch->class] = $id;
            }

            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'channel', $ch->name, [
                    'name' => $ch->name,
                    'class' => $ch->class,
                    'file' => $ch->file,
                    'flowSteps' => $flowSteps,
                ]));
            }
        }

        // Wire up call chain edges from channel authorization methods
        foreach ($callEdges as $edge) {
            $callerNode = $classToChannelId[$edge->callerFqcn]
                ?? $this->nodeIdForHop($edge->callerFqcn, $edge->callerMethod);
            $calleeNode = $this->hopCalleeNodeId($edge);

            $calleeGraphType = $this->effectiveCalleeGraphType($edge->calleeFqcn, $edge->type);
            $this->ensureNode($edge->calleeFqcn, $edge->calleeMethod, $calleeGraphType, []);

            if (! $this->graph->hasNode($callerNode) && ! isset($classToChannelId[$edge->callerFqcn])) {
                $callerClassified = $this->classifyFqcn($edge->callerFqcn);
                $callerGraphType = $this->effectiveCalleeGraphType($edge->callerFqcn, $callerClassified);
                $this->ensureNode($edge->callerFqcn, $edge->callerMethod, $callerGraphType, []);
            }

            $this->addEdge($callerNode, $calleeNode, $this->edgeLabelForType($calleeGraphType), 'channel-to-'.$calleeGraphType);
        }
    }

    // ── ID generators ─────────────────────────────────────────────────────────

    private function routeId(RouteDefinition $r): string
    {
        return "route::{$r->method}::{$r->uri}";
    }

    private function controllerId(string $fqcn): string
    {
        return "controller::{$fqcn}";
    }

    private function actionId(string $fqcn, string $action): string
    {
        return "action::{$fqcn}::{$action}";
    }

    private function middlewareId(string $fqcn): string
    {
        return "middleware::{$fqcn}";
    }

    private function modelId(string $fqcn): string
    {
        return "model::{$fqcn}";
    }

    private function eventId(string $fqcn): string
    {
        return "event::{$fqcn}";
    }

    private function observerId(string $fqcn): string
    {
        return "observer::{$fqcn}";
    }

    private function policyId(string $fqcn): string
    {
        return "policy::{$fqcn}";
    }

    private function filamentPanelId(string $fqcn): string
    {
        return "filament_panel::{$fqcn}";
    }

    private function filamentResourceId(string $fqcn): string
    {
        return "filament_resource::{$fqcn}";
    }

    private function filamentPageId(string $fqcn): string
    {
        return "filament_page::{$fqcn}";
    }

    private function filamentWidgetId(string $fqcn): string
    {
        return "filament_widget::{$fqcn}";
    }

    private function filamentRelationManagerId(string $fqcn): string
    {
        return "filament_relation_manager::{$fqcn}";
    }

    private function filamentPageMethodId(string $fqcn, string $method): string
    {
        return "filament_page_method::{$fqcn}::{$method}";
    }

    // ── Filament panels ───────────────────────────────────────────────────────

    /**
     * @param  FilamentPanelDefinition[]  $panels
     * @param  FilamentResourceDefinition[]  $resources
     * @param  FilamentPageDefinition[]  $pages
     * @param  FilamentWidgetDefinition[]  $widgets
     * @param  FilamentRelationManagerDefinition[]  $relationManagers
     */
    public function addFilament(
        array $panels,
        array $resources,
        array $pages,
        array $widgets,
        array $relationManagers,
    ): void {
        // ── 1. Panel nodes ────────────────────────────────────────────────────
        foreach ($panels as $panel) {
            $id = $this->filamentPanelId($panel->fqcn);
            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'filament_panel', $panel->id, [
                    'fqcn' => $panel->fqcn,
                    'file' => $panel->file,
                    'path' => $panel->path,
                    'panelId' => $panel->id,
                ]));
            }
        }

        // ── 2. Resource nodes + per-page route entry-points ───────────────────
        foreach ($resources as $resource) {
            $id = $this->filamentResourceId($resource->fqcn);
            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'filament_resource', class_basename($resource->fqcn), [
                    'fqcn' => $resource->fqcn,
                    'file' => $resource->file,
                    'modelFqcn' => $resource->modelFqcn,
                    'panelId' => $resource->panelId,
                    'route' => $resource->route,
                ]));
            }

            // Create a route-type node for each known page (index, create, edit, view)
            // so that each Filament page gets a graph tab starting from a route node,
            // mirroring how normal Laravel routes behave.
            foreach ($resource->pageRoutes as $pageKey => [$method, $path]) {
                $routeNodeId = "route::{$method}::{$path}";
                if (! $this->graph->hasNode($routeNodeId)) {
                    $this->graph->addNode(new Node($routeNodeId, 'route', "{$method} {$path}", [
                        'method' => $method,
                        'uri' => $path,
                        'filament' => true,
                        'pageType' => $pageKey,
                        'panelId' => $resource->panelId,
                        'resourceFqcn' => $resource->fqcn,
                        'file' => $resource->file,
                    ]));
                }
                $this->addEdge($routeNodeId, $id, 'handles', 'filament-route-to-resource');
            }

            // If the managed model is not yet in the graph, create a minimal model node
            if ($resource->modelFqcn !== '') {
                $modelNodeId = $this->modelId($resource->modelFqcn);
                if (! $this->graph->hasNode($modelNodeId)) {
                    $this->graph->addNode(new Node($modelNodeId, 'model', class_basename($resource->modelFqcn), [
                        'fqcn' => $resource->modelFqcn,
                        'file' => $this->resolveFile($resource->modelFqcn),
                        'relationships' => [],
                    ]));
                }
            }
        }

        // ── 3. Page nodes + per-method child nodes ────────────────────────────
        foreach ($pages as $page) {
            $id = $this->filamentPageId($page->fqcn);
            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'filament_page', class_basename($page->fqcn), [
                    'fqcn' => $page->fqcn,
                    'file' => $page->file,
                    'pageType' => $page->pageType,
                    'parentResourceFqcn' => $page->parentResourceFqcn,
                    ...($page->panelId !== '' ? ['panelId' => $page->panelId] : []),
                    ...($page->route !== '' ? ['route' => $page->route] : []),
                ]));
            }

            // Custom pages (not resource sub-pages) with a computed route get their own
            // route entry-point node, mirroring how resource page routes work.
            if ($page->parentResourceFqcn === '' && $page->route !== '') {
                $routeNodeId = "route::GET::{$page->route}";
                if (! $this->graph->hasNode($routeNodeId)) {
                    $this->graph->addNode(new Node($routeNodeId, 'route', "GET {$page->route}", [
                        'method' => 'GET',
                        'uri' => $page->route,
                        'filament' => true,
                        'pageType' => 'custom',
                        'panelId' => $page->panelId,
                        'pageFqcn' => $page->fqcn,
                        'file' => $page->file,
                    ]));
                }
                $this->addEdge($routeNodeId, $id, 'handles', 'filament-route-to-page');
            }

            // Create one node per method declared in the page class
            foreach ($page->methods as $method) {
                $methodId = $this->filamentPageMethodId($page->fqcn, $method);
                if (! $this->graph->hasNode($methodId)) {
                    $flowSteps = $this->extractMethodFlowSteps($page->fqcn, $method);
                    $metrics = $this->extractMethodMetrics($page->fqcn, $method);
                    $visibility = $this->extractVisibility($page->fqcn, $method);
                    $this->graph->addNode(new Node($methodId, 'filament_page_method', $method, [
                        'fqcn' => $page->fqcn,
                        'method' => $method,
                        'file' => $page->file,
                        'flowSteps' => $flowSteps,
                        'visibility' => $visibility,
                        ...($metrics ? ['metrics' => $metrics] : []),
                        ...($this->hasN1InSteps($flowSteps) ? ['hasN1' => true] : []),
                        ...($this->isFatMethod($metrics) ? ['fatMethod' => true] : []),
                    ]));
                }
                $this->addEdge($id, $methodId, 'has method', 'filament-page-to-method');
            }
        }

        // ── 4. Widget nodes ───────────────────────────────────────────────────
        foreach ($widgets as $widget) {
            $id = $this->filamentWidgetId($widget->fqcn);
            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'filament_widget', class_basename($widget->fqcn), [
                    'fqcn' => $widget->fqcn,
                    'file' => $widget->file,
                    'widgetType' => $widget->widgetType,
                ]));
            }
        }

        // ── 5. Relation Manager nodes ─────────────────────────────────────────
        foreach ($relationManagers as $rm) {
            $id = $this->filamentRelationManagerId($rm->fqcn);
            if (! $this->graph->hasNode($id)) {
                $this->graph->addNode(new Node($id, 'filament_relation_manager', class_basename($rm->fqcn), [
                    'fqcn' => $rm->fqcn,
                    'file' => $rm->file,
                    'relationship' => $rm->relationship,
                    'parentResourceFqcn' => $rm->parentResourceFqcn,
                ]));
            }
        }

        // ── 6. Edges ──────────────────────────────────────────────────────────

        // Panel → Resource
        foreach ($panels as $panel) {
            $panelId = $this->filamentPanelId($panel->fqcn);
            foreach ($panel->resources as $resourceFqcn) {
                $this->addEdge($panelId, $this->filamentResourceId($resourceFqcn), 'registers', 'filament-panel-to-resource');
            }
            // Panel → custom page
            foreach ($panel->pages as $pageFqcn) {
                $this->addEdge($panelId, $this->filamentPageId($pageFqcn), 'registers', 'filament-panel-to-page');
            }
            // Panel → widget
            foreach ($panel->widgets as $widgetFqcn) {
                $this->addEdge($panelId, $this->filamentWidgetId($widgetFqcn), 'registers', 'filament-panel-to-widget');
            }
        }

        // Resource → Model
        foreach ($resources as $resource) {
            $resourceId = $this->filamentResourceId($resource->fqcn);
            if ($resource->modelFqcn !== '') {
                $this->addEdge($resourceId, $this->modelId($resource->modelFqcn), 'manages', 'filament-resource-to-model');
            }
            // Resource → Page
            foreach ($resource->pages as $pageKey => $pageFqcn) {
                $this->addEdge($resourceId, $this->filamentPageId($pageFqcn), 'has page', 'filament-resource-to-page');
            }
            // Route → Page (direct: each page-specific route points straight to its page)
            foreach ($resource->pageRoutes as $pageKey => [$method, $path]) {
                $pageFqcn = $resource->pages[$pageKey] ?? null;
                if ($pageFqcn !== null) {
                    $this->addEdge(
                        "route::{$method}::{$path}",
                        $this->filamentPageId($pageFqcn),
                        'handled by',
                        'filament-route-to-page',
                    );
                }
            }
            // Resource → Relation Manager
            foreach ($resource->relations as $rmFqcn) {
                $this->addEdge($resourceId, $this->filamentRelationManagerId($rmFqcn), 'has relation', 'filament-resource-to-relation');
            }
        }
    }
}

function class_basename(string $fqcn): string
{
    $parts = explode('\\', $fqcn);

    return end($parts);
}
