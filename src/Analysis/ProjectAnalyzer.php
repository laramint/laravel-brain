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

    private QueryTracer $queryTracer;

    private GraphBuilder $graphBuilder;

    private GraphSplitter $graphSplitter;

    /** @var callable(string, array): void */
    private $onProgress;

    public function __construct()
    {
        $this->routeAnalyzer = new RouteAnalyzer;
        $this->middlewareAnalyzer = new MiddlewareAnalyzer;
        $this->controllerAnalyzer = new ControllerAnalyzer;
        $this->methodTracer = new MethodTracer;
        $this->modelAnalyzer = new ModelAnalyzer;
        $this->consoleAnalyzer = new ConsoleAnalyzer;
        $this->channelAnalyzer = new ChannelAnalyzer;
        $this->queryTracer = new QueryTracer;
        $this->graphBuilder = new GraphBuilder;
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
        $projectName = basename($projectRoot);
        $analyzedAt = date('c');

        $this->emit('project:start', ['name' => $projectName, 'message' => "Analyzing project: {$projectName}"]);

        $this->emit('step:start', ['step' => 'routes', 'label' => 'Scanning routes', 'message' => '  → Scanning routes...']);
        $extraGlobs = function_exists('config') ? (array) config('laravel-brain.route_paths', []) : [];
        $routes = $this->routeAnalyzer->analyze($projectRoot, $extraGlobs);
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

        $this->emit('step:start', ['step' => 'queries', 'label' => 'Tracing DB queries', 'message' => '  → Tracing DB queries...']);
        $dbQueryMap = $this->queryTracer->buildQueryMap($callChain, $controllers, $psr4Map, $projectRoot);
        $this->emit('step:done', ['step' => 'queries', 'count' => count($dbQueryMap), 'unit' => 'action', 'message' => '    Found DB query info for '.count($dbQueryMap).' action(s)']);

        $this->emit('step:start', ['step' => 'graph', 'label' => 'Building graph', 'message' => '  → Building graph...']);
        $fullGraph = $this->graphBuilder->build(
            $projectName, $routes, $middlewareRegistry, $controllers, $callChain, $models, $projectRoot, $dbQueryMap,
        );
        $this->graphBuilder->addConsoleCommands($commands, $schedules, $commandEdges);
        $this->graphBuilder->addChannels($channels, $channelEdges);
        $this->emit('step:done', ['step' => 'graph', 'count' => $fullGraph->nodeCount(), 'unit' => 'node', 'extra' => $fullGraph->edgeCount().' edges', 'message' => "    {$fullGraph->nodeCount()} nodes, {$fullGraph->edgeCount()} edges"]);

        $this->emit('step:start', ['step' => 'split', 'label' => 'Splitting into tab subgraphs', 'message' => '  → Splitting into tab subgraphs...']);
        $split = $this->graphSplitter->split($fullGraph, $routes, $commands, $channels, $schedules, $projectName, $analyzedAt);
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
        );

        $this->emit('analysis:done', [
            'nodes' => $fullGraph->nodeCount(),
            'edges' => $fullGraph->edgeCount(),
            'routes' => count($routes),
            'controllers' => count($controllers),
            'models' => count($models),
            'commands' => count($commands),
            'channels' => count($channels),
            'tabs' => count($split['subgraphs']),
        ]);

        return $result;
    }

    private function emit(string $event, array $data = []): void
    {
        ($this->onProgress)($event, $data);
    }
}
