<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\GraphSplitter;
use LaraMint\LaravelBrain\Graph\TabManifestEntry;

class AnalysisResult
{
    public function __construct(
        public Graph $fullGraph,
        /** @var array<string, Graph> tabId => subgraph */
        public array $subgraphs,
        /** @var TabManifestEntry[] */
        public array $manifest,
        public string $manifestJson,
        public string $projectName,
        public string $analyzedAt,
        public int $totalRoutes,
        public int $totalCommands = 0,
        public int $totalChannels = 0,
        public int $totalFilamentResources = 0,
    ) {}
}

class ProjectAnalyzer
{
    private RouteAnalyzer $routeAnalyzer;

    private MiddlewareAnalyzer $middlewareAnalyzer;

    private ControllerAnalyzer $controllerAnalyzer;

    private MethodTracer $methodTracer;

    private ModelAnalyzer $modelAnalyzer;

    private ConsoleAnalyzer $consoleAnalyzer;

    private ChannelAnalyzer $channelAnalyzer;

    private FilamentAnalyzer $filamentAnalyzer;

    private QueryTracer $queryTracer;

    private GraphBuilder $graphBuilder;

    private GraphSplitter $graphSplitter;

    /** @var callable(string, array): void */
    private $onProgress;

    public function __construct()
    {
        $routePaths = config('laravel-brain.route_paths', ['routes/*/*.php']);
        $this->routeAnalyzer = new RouteAnalyzer($routePaths);

        $channelPaths = config('laravel-brain.channel_paths', ['routes/*/*.php']);
        $this->channelAnalyzer = new ChannelAnalyzer($channelPaths);

        $cmdConfig = config('laravel-brain.commands', []);
        $this->consoleAnalyzer = new ConsoleAnalyzer(
            consoleRoutePaths: $cmdConfig['console_route_paths'] ?? ['routes/*/*.php'],
            classPaths: $cmdConfig['class_paths'] ?? ['app/Console/Commands/*/*.php'],
            kernelPaths: $cmdConfig['kernel_paths'] ?? ['app/Console/Kernel.php'],
        );

        $this->middlewareAnalyzer = new MiddlewareAnalyzer;
        $this->controllerAnalyzer = new ControllerAnalyzer;
        $this->methodTracer = new MethodTracer;
        $this->modelAnalyzer = new ModelAnalyzer;
        $this->filamentAnalyzer = new FilamentAnalyzer;
        $this->queryTracer = new QueryTracer;
        $this->graphBuilder = new GraphBuilder;
        $livewirePaths = config('laravel-brain.livewire.component_paths', []);
        if (is_array($livewirePaths) && $livewirePaths !== []) {
            $this->graphBuilder->setLivewireComponentPaths($livewirePaths);
        }
        $this->graphSplitter = new GraphSplitter;

        $this->onProgress = static function (string $event, array $data): void {
            echo ($data['message'] ?? $event).PHP_EOL;
        };
    }

    public function analyze(string $projectRoot, ?callable $onProgress = null): AnalysisResult
    {
        if ($onProgress !== null) {
            $this->onProgress = $onProgress;
        }

        $projectRoot = rtrim($projectRoot, '/');
        $appName = function_exists('config') ? config('app.name') : null;
        $projectName = (is_string($appName) && $appName !== '') ? $appName : 'Laravel Brain';
        $analyzedAt = date('c');

        $this->emit('project:start', ['name' => $projectName, 'message' => "Analyzing project: {$projectName}"]);

        $this->emit('step:start', ['step' => 'routes', 'label' => 'Scanning routes', 'message' => '  → Scanning routes...']);
        $routes = $this->routeAnalyzer->analyze($projectRoot);
        $this->emit('step:done', ['step' => 'routes', 'count' => count($routes), 'unit' => 'route', 'message' => '    Found '.count($routes).' route(s)']);

        $this->emit('step:start', ['step' => 'middleware', 'label' => 'Scanning middleware', 'message' => '  → Scanning middleware...']);
        $middlewareRegistry = $this->middlewareAnalyzer->analyze($projectRoot);
        $this->emit('step:done', ['step' => 'middleware', 'count' => null, 'unit' => null, 'message' => '    Done']);

        $this->emit('step:start', ['step' => 'controllers', 'label' => 'Analyzing controllers', 'message' => '  → Analyzing controllers...']);
        $controllers = $this->controllerAnalyzer->analyze($projectRoot, $routes);
        $this->emit('step:done', ['step' => 'controllers', 'count' => count($controllers), 'unit' => 'controller', 'message' => '    Found '.count($controllers).' controller(s)']);

        $this->emit('step:start', ['step' => 'lifecycle', 'label' => 'Tracing full lifecycle', 'message' => '  → Tracing full lifecycle (deep)...']);
        $psr4Map = $this->controllerAnalyzer->getPsr4Map();
        $callChain = $this->methodTracer->trace($controllers, $psr4Map, $projectRoot);
        $this->emit('step:done', ['step' => 'lifecycle', 'count' => count($callChain), 'unit' => 'call edge', 'message' => '    Discovered '.count($callChain).' call chain edge(s)']);

        $this->emit('step:start', ['step' => 'models', 'label' => 'Analyzing models', 'message' => '  → Analyzing models...']);
        $modelFqcns = [];
        foreach ($callChain as $edge) {
            if ($edge->type === 'model') {
                $modelFqcns[] = $edge->calleeFqcn;
            }
        }
        $modelFqcns = array_unique($modelFqcns);
        $models = $this->modelAnalyzer->analyze($projectRoot, $modelFqcns);
        $this->emit('step:done', ['step' => 'models', 'count' => count($models), 'unit' => 'model', 'message' => '    Found '.count($models).' model(s)']);

        $this->emit('step:start', ['step' => 'commands', 'label' => 'Scanning console commands', 'message' => '  → Scanning console commands...']);
        $consoleResult = $this->consoleAnalyzer->analyze($projectRoot);
        $commands = $consoleResult['commands'];
        $schedules = $consoleResult['schedule'];
        $this->emit('step:done', ['step' => 'commands', 'count' => count($commands), 'unit' => 'command', 'extra' => count($schedules).' scheduled', 'message' => '    Found '.count($commands).' command(s), '.count($schedules).' schedule(s)']);

        $this->emit('step:start', ['step' => 'channels', 'label' => 'Scanning broadcast channels', 'message' => '  → Scanning broadcast channels...']);
        $channels = $this->channelAnalyzer->analyze($projectRoot);
        $this->emit('step:done', ['step' => 'channels', 'count' => count($channels), 'unit' => 'channel', 'message' => '    Found '.count($channels).' channel(s)']);

        $this->emit('step:start', ['step' => 'cmd_chains', 'label' => 'Tracing command call chains', 'message' => '  → Tracing command call chains...']);
        $commandEdges = [];
        foreach ($commands as $cmd) {
            if ($cmd->class) {
                $edges = $this->methodTracer->traceMethod($cmd->class, 'handle', $psr4Map, $projectRoot);
                $commandEdges = array_merge($commandEdges, $edges);
            }
        }
        $this->emit('step:done', ['step' => 'cmd_chains', 'count' => count($commandEdges), 'unit' => 'call edge', 'message' => '    Discovered '.count($commandEdges).' command call chain edge(s)']);

        $this->emit('step:start', ['step' => 'ch_chains', 'label' => 'Tracing channel call chains', 'message' => '  → Tracing channel call chains...']);
        $channelEdges = [];
        foreach ($channels as $ch) {
            if ($ch->class) {
                $edges = $this->methodTracer->traceMethod($ch->class, '__invoke', $psr4Map, $projectRoot);
                if (empty($edges)) {
                    $edges = $this->methodTracer->traceMethod($ch->class, 'join', $psr4Map, $projectRoot);
                }
                $channelEdges = array_merge($channelEdges, $edges);
            }
        }
        $this->emit('step:done', ['step' => 'ch_chains', 'count' => count($channelEdges), 'unit' => 'call edge', 'message' => '    Discovered '.count($channelEdges).' channel call chain edge(s)']);

        $this->emit('step:start', ['step' => 'filament', 'label' => 'Scanning Filament panels', 'message' => '  → Scanning Filament panels...']);
        $filamentResult = $this->filamentAnalyzer->analyze($projectRoot);
        $filamentResourceCount = count($filamentResult['resources']);
        $this->emit('step:done', ['step' => 'filament', 'count' => $filamentResourceCount, 'unit' => 'resource', 'message' => "    Found {$filamentResourceCount} Filament resource(s)"]);

        // Trace call chains from Filament page class methods (same way controller actions are traced)
        $filamentPageEdges = [];
        if ($filamentResult['detected'] && ! empty($filamentResult['pages'])) {
            $this->emit('step:start', ['step' => 'filament_chains', 'label' => 'Tracing Filament page call chains', 'message' => '  → Tracing Filament page call chains...']);
            $filamentPageDefs = [];
            foreach ($filamentResult['pages'] as $page) {
                if ($page->file !== '' && file_exists($page->file)) {
                    $def = $this->controllerAnalyzer->analyzeFile($page->fqcn, $page->file);
                    if ($def !== null) {
                        $filamentPageDefs[$page->fqcn] = $def;
                    }
                }
            }
            if (! empty($filamentPageDefs)) {
                $filamentPageEdges = $this->methodTracer->trace($filamentPageDefs, $psr4Map, $projectRoot);
            }
            $this->emit('step:done', ['step' => 'filament_chains', 'count' => count($filamentPageEdges), 'unit' => 'call edge', 'message' => '    Discovered '.count($filamentPageEdges).' Filament page call chain edge(s)']);
        }

        $this->emit('step:start', ['step' => 'queries', 'label' => 'Tracing DB queries', 'message' => '  → Tracing DB queries...']);
        $dbQueryMap = $this->queryTracer->buildQueryMap($callChain, $controllers, $psr4Map, $projectRoot);
        $this->emit('step:done', ['step' => 'queries', 'count' => count($dbQueryMap), 'unit' => 'action', 'message' => '    Found DB query info for '.count($dbQueryMap).' action(s)']);

        $this->emit('step:start', ['step' => 'container_bindings', 'label' => 'Scanning service providers', 'message' => '  → Scanning service providers (IoC bindings)...']);
        $bindingRegistry = (new ContainerBindingAnalyzer)->analyze($projectRoot);
        $bindingCount = count($bindingRegistry->all());
        $this->emit('step:done', ['step' => 'container_bindings', 'count' => $bindingCount, 'unit' => 'binding', 'message' => "    Found {$bindingCount} container binding(s)"]);

        $this->emit('step:start', ['step' => 'facades', 'label' => 'Scanning facades', 'message' => '  → Scanning application facades...']);
        $facadeRegistry = (new FacadeAnalyzer)->analyze($projectRoot);
        $facadeRegistry->resolveWith($bindingRegistry);
        $facadeCount = count($facadeRegistry->all());
        $this->emit('step:done', ['step' => 'facades', 'count' => $facadeCount, 'unit' => 'facade', 'message' => "    Found {$facadeCount} facade(s)"]);

        // Release the ClassMethod AST cache accumulated during tracing — GraphBuilder has its own
        // parse cache and does not need MethodTracer's cached nodes. Freeing this before the
        // graph-building phase can reclaim hundreds of MB on large codebases.
        $this->methodTracer->releaseClassCache();
        gc_collect_cycles();

        $this->emit('step:start', ['step' => 'graph', 'label' => 'Building graph', 'message' => '  → Building graph...']);
        $fullGraph = $this->graphBuilder->build(
            $projectName, $routes, $middlewareRegistry, $controllers, $callChain, $models, $projectRoot, $dbQueryMap, $bindingRegistry, $facadeRegistry,
        );
        $this->graphBuilder->addConsoleCommands($commands, $schedules, $commandEdges);
        $this->graphBuilder->addChannels($channels, $channelEdges);
        if ($filamentResult['detected']) {
            $this->graphBuilder->addFilament(
                $filamentResult['panels'],
                $filamentResult['resources'],
                $filamentResult['pages'],
                $filamentResult['widgets'],
                $filamentResult['relationManagers'],
            );

            // Wire page-level call chains (services, models, jobs, events called from page methods)
            if (! empty($filamentPageEdges)) {
                $pageNodeIds = [];
                foreach ($filamentResult['pages'] as $page) {
                    $pageNodeIds[$page->fqcn] = "filament_page::{$page->fqcn}";
                }
                $this->graphBuilder->addFilamentPageCallChain($filamentPageEdges, $pageNodeIds);
            }
        }
        $this->emit('step:done', ['step' => 'graph', 'count' => $fullGraph->nodeCount(), 'unit' => 'node', 'extra' => $fullGraph->edgeCount().' edges', 'message' => "    {$fullGraph->nodeCount()} nodes, {$fullGraph->edgeCount()} edges"]);

        $this->emit('step:start', ['step' => 'split', 'label' => 'Splitting into tab subgraphs', 'message' => '  → Splitting into tab subgraphs...']);
        $split = $this->graphSplitter->split($fullGraph, $routes, $commands, $channels, $schedules, $projectName, $analyzedAt, $filamentResult['panels'], $filamentResult['resources'], $filamentResult['pages']);
        $this->emit('step:done', ['step' => 'split', 'count' => count($split['subgraphs']), 'unit' => 'tab', 'message' => '    '.count($split['subgraphs']).' tab(s) generated']);

        $manifestJson = $this->graphSplitter->buildManifestJson(
            $split['manifest'], $fullGraph, $projectName, $analyzedAt, count($routes),
        );

        $result = new AnalysisResult(
            fullGraph: $fullGraph,
            subgraphs: $split['subgraphs'],
            manifest: $split['manifest'],
            manifestJson: $manifestJson,
            projectName: $projectName,
            analyzedAt: $analyzedAt,
            totalRoutes: count($routes),
            totalCommands: count($commands),
            totalChannels: count($channels),
            totalFilamentResources: $filamentResourceCount,
        );

        $this->emit('analysis:done', [
            'nodes' => $fullGraph->nodeCount(),
            'edges' => $fullGraph->edgeCount(),
            'routes' => count($routes),
            'controllers' => count($controllers),
            'models' => count($models),
            'commands' => count($commands),
            'channels' => count($channels),
            'filamentResources' => $filamentResourceCount,
            'tabs' => count($split['subgraphs']),
        ]);

        return $result;
    }

    private function emit(string $event, array $data = []): void
    {
        ($this->onProgress)($event, $data);
    }
}
