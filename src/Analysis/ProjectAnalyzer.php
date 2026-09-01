<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Analysis\Incremental\IncrementalMerge;
use LaraMint\LaravelBrain\Analysis\Incremental\ScopedRebuildNotApplicable;
use LaraMint\LaravelBrain\Analysis\Incremental\ScopeExpansion;
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
        /** @var string[] "FQCN::method" of methods that dispatch a job Brain couldn't resolve statically */
        public array $unresolvedDispatchers = [],
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

    private ListenerAnalyzer $listenerAnalyzer;

    private ObserverAnalyzer $observerAnalyzer;

    private PolicyAnalyzer $policyAnalyzer;

    private BladeViewAnalyzer $bladeViewAnalyzer;

    private FilamentAnalyzer $filamentAnalyzer;

    private QueryTracer $queryTracer;

    private SecurityAnalyzer $securityAnalyzer;

    private GraphBuilder $graphBuilder;

    private GraphSplitter $graphSplitter;

    /** @var string[] */
    private array $bindingProviderPaths = ContainerBindingAnalyzer::DEFAULT_PROVIDER_PATHS;

    /** @var string[] */
    private array $facadePaths = FacadeAnalyzer::DEFAULT_PATHS;

    private bool $tableStatsEnabled = true;

    private ?string $tableStatsConnection = null;

    private bool $schemaEnabled = true;

    private ?string $schemaConnection = null;

    /** @var callable(string, array): void */
    private $onProgress;

    public function __construct()
    {
        $routePaths = config('laravel-brain.route_paths', ['routes/*/*.php']);
        $autoDiscover = (bool) config('laravel-brain.auto_discover_routes', false);
        $excludeVendor = (bool) config('laravel-brain.auto_discover_exclude_vendor', true);
        $this->routeAnalyzer = new RouteAnalyzer($routePaths, $autoDiscover, $excludeVendor);

        $channelPaths = config('laravel-brain.channel_paths', ['routes/*/*.php']);
        $channelRegistrars = config('laravel-brain.channel_registrars', []);
        $this->channelAnalyzer = new ChannelAnalyzer(
            is_array($channelPaths) ? $channelPaths : [],
            $this->stringList($channelRegistrars),
        );

        $sourcePaths = config('laravel-brain.source_paths', SourceDirectories::DEFAULT_SOURCE_PATHS);
        $sourcePaths = is_array($sourcePaths) && $sourcePaths !== []
            ? $sourcePaths
            : SourceDirectories::DEFAULT_SOURCE_PATHS;

        $listenerPaths = config('laravel-brain.listeners.paths', ['app/Listeners']);
        $providerPaths = config('laravel-brain.listeners.provider_paths', ['app/Providers']);
        $this->listenerAnalyzer = new ListenerAnalyzer(
            is_array($listenerPaths) ? $listenerPaths : [],
            is_array($providerPaths) ? $providerPaths : [],
            $sourcePaths,
        );

        $observerModelPaths = config('laravel-brain.observers.model_paths', ['app/Models']);
        $observerProviderPaths = config('laravel-brain.observers.provider_paths', ['app/Providers']);
        $this->observerAnalyzer = new ObserverAnalyzer(
            is_array($observerModelPaths) ? $observerModelPaths : [],
            is_array($observerProviderPaths) ? $observerProviderPaths : [],
        );

        $policyProviderPaths = config('laravel-brain.policies.provider_paths', ['app/Providers']);
        $this->policyAnalyzer = new PolicyAnalyzer(
            is_array($policyProviderPaths) ? $policyProviderPaths : [],
            $sourcePaths,
        );

        $viewPaths = config('laravel-brain.views.paths', BladeViewAnalyzer::DEFAULT_PATHS);
        $viewPaths = is_array($viewPaths) && $viewPaths !== [] ? $viewPaths : BladeViewAnalyzer::DEFAULT_PATHS;
        $this->bladeViewAnalyzer = new BladeViewAnalyzer($viewPaths);

        $cmdConfig = config('laravel-brain.commands', []);
        $this->consoleAnalyzer = new ConsoleAnalyzer(
            consoleRoutePaths: $cmdConfig['console_route_paths'] ?? ['routes/*/*.php'],
            classPaths: $cmdConfig['class_paths'] ?? ['app/Console/Commands/*/*.php'],
            kernelPaths: $cmdConfig['kernel_paths'] ?? ['app/Console/Kernel.php'],
        );

        $this->middlewareAnalyzer = new MiddlewareAnalyzer;
        $this->controllerAnalyzer = new ControllerAnalyzer($sourcePaths);
        $dispatchHelpers = config('laravel-brain.dispatch.helpers', []);
        $this->methodTracer = new MethodTracer(
            is_array($dispatchHelpers) ? $dispatchHelpers : [],
            $sourcePaths,
        );
        $modelPaths = config('laravel-brain.models.paths', ['app/Models']);
        $this->modelAnalyzer = new ModelAnalyzer(is_array($modelPaths) ? $modelPaths : []);
        $filamentPanelPaths = config('laravel-brain.filament.panel_paths', FilamentAnalyzer::DEFAULT_PANEL_PATHS);
        $filamentPaths = config('laravel-brain.filament.paths', FilamentAnalyzer::DEFAULT_PATHS);
        $this->filamentAnalyzer = new FilamentAnalyzer(
            is_array($filamentPanelPaths) ? $filamentPanelPaths : FilamentAnalyzer::DEFAULT_PANEL_PATHS,
            is_array($filamentPaths) ? $filamentPaths : FilamentAnalyzer::DEFAULT_PATHS,
        );
        $this->queryTracer = new QueryTracer($sourcePaths);
        $this->securityAnalyzer = new SecurityAnalyzer(
            extraAuthPatterns: $this->stringList(config('laravel-brain.security.auth_middleware', [])),
            extraThrottlePatterns: $this->stringList(config('laravel-brain.security.throttle_middleware', [])),
            trustedRouteNames: $this->stringList(config('laravel-brain.security.trusted_route_names', [])),
            trustedRouteUris: $this->stringList(config('laravel-brain.security.trusted_route_uris', [])),
            sourcePaths: $sourcePaths,
        );
        $this->graphBuilder = new GraphBuilder;
        $bindingProviderPaths = config(
            'laravel-brain.container_bindings.provider_paths',
            ContainerBindingAnalyzer::DEFAULT_PROVIDER_PATHS,
        );
        $this->bindingProviderPaths = is_array($bindingProviderPaths)
            ? $bindingProviderPaths
            : ContainerBindingAnalyzer::DEFAULT_PROVIDER_PATHS;

        $facadePaths = config('laravel-brain.facades.paths', FacadeAnalyzer::DEFAULT_PATHS);
        $this->facadePaths = is_array($facadePaths) ? $facadePaths : FacadeAnalyzer::DEFAULT_PATHS;

        $this->tableStatsEnabled = (bool) config('laravel-brain.table_stats.enabled', true);
        $statsConnection = config('laravel-brain.table_stats.connection');
        $this->tableStatsConnection = is_string($statsConnection) && $statsConnection !== ''
            ? $statsConnection
            : null;

        $this->schemaEnabled = (bool) config('laravel-brain.schema.enabled', true);
        $schemaConnection = config('laravel-brain.schema.connection');
        $this->schemaConnection = is_string($schemaConnection) && $schemaConnection !== ''
            ? $schemaConnection
            : null;

        $this->graphBuilder->setSourcePaths($sourcePaths);
        $this->graphBuilder->setViewPaths($viewPaths);
        $livewirePaths = config('laravel-brain.livewire.component_paths', []);
        if (is_array($livewirePaths) && $livewirePaths !== []) {
            $this->graphBuilder->setLivewireComponentPaths($livewirePaths);
        }
        $this->graphSplitter = new GraphSplitter;

        $this->onProgress = static function (string $event, array $data): void {
            echo ($data['message'] ?? $event).PHP_EOL;
        };
    }

    /** @var string[]|null files to scope tracing to; null means trace everything */
    private ?array $scopeToFiles = null;

    /** Graph from the previous full run, which a scoped run merges its result into. */
    private ?Graph $mergeInto = null;

    /**
     * Trace only the controllers declared in these files, and merge the result into the graph
     * a previous full run produced.
     *
     * Everything else on the pass still runs in full — routes, commands, channels, the split —
     * because those are a couple of percent of a scan between them. What it skips is tracing
     * every controller in the project, which is nearly all of the rest.
     *
     * The caller owns the decision to use this: it is only sound when no file was added or
     * deleted and nothing outside `app/` moved. Whether the changed files' own call graph
     * survived the edit is not knowable up front, so that part is checked here and raises
     * {@see ScopedRebuildNotApplicable} when it does not hold.
     *
     * @param  string[]  $changedFiles
     */
    public function scopedTo(array $changedFiles, Graph $previous): static
    {
        $this->scopeToFiles = $changedFiles;
        $this->mergeInto = $previous;

        return $this;
    }

    /**
     * Refuse a scope that cannot name anything the previous graph owns, before any work is done.
     *
     * The soundness check below compares what the scope owns in the previous graph against what
     * it owns in the fresh one. A scope naming nothing owns nothing in both, and two empty sets
     * compare equal — so the check approves it, the merge substitutes nothing, and the previous
     * graph is returned as though it were current. The less such a scope matches, the more
     * confidently it passes.
     *
     * Path *form* is no longer a way into that: {@see GraphProvenance} resolves a caller's paths
     * against the ones a build recorded. What is left is a path that names no file in this
     * project at all — deleted since the previous run, from another checkout, or simply a typo —
     * and there is no reading of that a previous graph can answer, so it is refused.
     *
     * A file that exists here but owns nothing is a different case and stays on the fast path.
     * It is most of `app/` — 45% to 86% of it across the applications measured — because the
     * graph only holds what an entry point reaches, and an edit to a file nothing reaches cannot
     * change it without an edit to its caller, which would be in the scope and does own
     * provenance.
     *
     * @param  string[]|null  $scopeToFiles
     *
     * @throws ScopedRebuildNotApplicable
     */
    private function assertScopeIsUsable(?array $scopeToFiles, string $projectRoot): void
    {
        if ($scopeToFiles === null) {
            return;
        }

        $root = realpath($projectRoot);
        if ($root === false || $scopeToFiles === []) {
            throw new ScopedRebuildNotApplicable;
        }

        foreach ($scopeToFiles as $file) {
            $real = realpath($file);
            if ($real === false || ! is_file($real) || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                throw new ScopedRebuildNotApplicable;
            }
        }
    }

    /**
     * Runs the analysis with PHP's cycle collector switched off.
     *
     * That collector exists to reclaim objects which reference each other in a loop, and a build
     * makes none. Measured across three applications it ran 15 to 37 times per build and
     * collected **zero** objects every time, including at the deliberate `gc_collect_cycles()`
     * further down: ASTs are trees, nothing here links a child back to its parent, and neither
     * Node nor Edge holds a reference to the Graph — so every one of those passes walks the root
     * buffer and finds nothing to free. Switching it off for the build is worth 3-10%, and peak
     * memory does not move: 268 MB and 276 MB on two applications either way, and still unchanged
     * after five builds in one process, which is what watch mode does.
     *
     * The caller's setting is restored afterwards, including when the analysis throws: a scoped
     * run raises {@see ScopedRebuildNotApplicable} from the middle of a build, and the process
     * belongs to whoever called this.
     */
    public function analyze(string $projectRoot, ?callable $onProgress = null): AnalysisResult
    {
        $collectorWasEnabled = gc_enabled();
        gc_disable();

        try {
            return $this->runAnalysis($projectRoot, $onProgress);
        } finally {
            if ($collectorWasEnabled) {
                gc_enable();
            }
        }
    }

    private function runAnalysis(string $projectRoot, ?callable $onProgress = null): AnalysisResult
    {
        if ($onProgress !== null) {
            $this->onProgress = $onProgress;
        }

        $projectRoot = rtrim($projectRoot, '/');

        // Rebuilt per analysis, so a rescan sees files added since the previous one.
        ProjectFileIndex::clear();
        SourceDirectories::clear();

        $appName = function_exists('config') ? config('app.name') : null;
        $projectName = (is_string($appName) && $appName !== '') ? $appName : 'Laravel Brain';
        $analyzedAt = date('c');

        $this->emit('project:start', ['name' => $projectName, 'message' => "Analyzing project: {$projectName}"]);

        // Consumed for this run only: leaving it set would silently scope the next analyze() on
        // a reused instance, and merge it into a graph that has since gone stale.
        $scopeToFiles = $this->scopeToFiles;
        $mergeInto = $this->mergeInto;
        $this->scopeToFiles = null;
        $this->assertScopeIsUsable($scopeToFiles, $projectRoot);
        $this->mergeInto = null;

        $this->emit('step:start', ['step' => 'routes', 'label' => 'Scanning routes', 'message' => '  → Scanning routes...']);
        $routes = $this->routeAnalyzer->analyze($projectRoot);
        $this->emit('step:done', ['step' => 'routes', 'count' => count($routes), 'unit' => 'route', 'message' => '    Found '.count($routes).' route(s)']);

        $this->emit('step:start', ['step' => 'middleware', 'label' => 'Scanning middleware', 'message' => '  → Scanning middleware...']);
        $middlewareRegistry = $this->middlewareAnalyzer->analyze($projectRoot);
        $this->emit('step:done', ['step' => 'middleware', 'count' => null, 'unit' => null, 'message' => '    Done']);

        $this->emit('step:start', ['step' => 'controllers', 'label' => 'Analyzing controllers', 'message' => '  → Analyzing controllers...']);
        $controllers = $this->controllerAnalyzer->analyze($projectRoot, $routes);

        // A scoped run traces the controllers declared in the changed files, plus the ones whose
        // chain reached those files in the previous build — see {@see ScopeExpansion}. Without the
        // second half a changed file that declares no controller is never rebuilt, and the check
        // below has nothing to compare, so an edit that added a call passes as one that changed
        // nothing.
        if ($scopeToFiles !== null) {
            $wanted = array_flip(array_map(static fn (string $f): string => realpath($f) ?: $f, $scopeToFiles));
            if ($mergeInto !== null) {
                foreach (array_keys(ScopeExpansion::controllerFilesReaching($mergeInto, $scopeToFiles)) as $file) {
                    $wanted[realpath($file) ?: $file] = true;
                }
            }
            $controllers = array_filter(
                $controllers,
                static fn (ControllerDefinition $c): bool => isset($wanted[realpath($c->file) ?: $c->file]),
            );
        }
        $this->emit('step:done', ['step' => 'controllers', 'count' => count($controllers), 'unit' => 'controller', 'message' => '    Found '.count($controllers).' controller(s)']);

        $this->emit('step:start', ['step' => 'lifecycle', 'label' => 'Tracing full lifecycle', 'message' => '  → Tracing full lifecycle (deep)...']);
        $psr4Map = $this->controllerAnalyzer->getPsr4Map();
        $callChain = $this->methodTracer->trace($controllers, $psr4Map, $projectRoot);

        // Trace closure routes (Route::get('/uri', function() { ... })) with the same scanner
        foreach ($routes as $route) {
            if ($route->closureNode === null) {
                continue;
            }
            $callerFqcn = "route::{$route->method}::{$route->uri}";
            $closureEdges = $this->methodTracer->traceClosure(
                $route->closureNode,
                $route->closureUseMap ?? [],
                $callerFqcn,
                $psr4Map,
                $projectRoot,
            );
            foreach ($closureEdges as $edge) {
                $callChain[] = $edge;
            }
        }

        // Link dispatched events to the listeners that handle them.
        foreach ($this->listenerAnalyzer->analyze($projectRoot, $psr4Map) as $edge) {
            $callChain[] = $edge;
        }

        $this->emit('step:done', ['step' => 'lifecycle', 'count' => count($callChain), 'unit' => 'call edge', 'message' => '    Discovered '.count($callChain).' call chain edge(s)']);

        $this->emit('step:start', ['step' => 'models', 'label' => 'Analyzing models', 'message' => '  → Analyzing models...']);
        $modelFqcns = [];
        foreach ($callChain as $edge) {
            if ($edge->type === 'model') {
                $modelFqcns[] = $edge->calleeFqcn;
            }
        }
        $modelFqcns = array_unique(array_merge($modelFqcns, $this->modelAnalyzer->discoverModels($projectRoot)));

        $models = $this->modelAnalyzer->analyze($projectRoot, $modelFqcns);
        $this->emit('step:done', ['step' => 'models', 'count' => count($models), 'unit' => 'model', 'message' => '    Found '.count($models).' model(s)']);

        // Two reads of the live database, and the only steps that open a connection at all.
        // Both fail quietly for the same reason: no connection means missing numbers, not a
        // failed scan.
        //
        // The table resolver runs once for both. Parsing sees a `$table` written in the model's
        // own file and nothing else — not a connection prefix, not one inherited from a base
        // class, not a `getTable()` override — so both steps need it and neither should pay for
        // it twice.
        if ($this->tableStatsEnabled || $this->schemaEnabled) {
            $models = (new ModelTableResolver)->resolve($models);
        }

        $this->emit('step:start', ['step' => 'table_stats', 'label' => 'Reading table sizes', 'message' => '  → Reading table sizes...']);
        $tableStats = [];
        if ($this->tableStatsEnabled) {
            $tableStats = TableStatsCollector::forConnection($this->tableStatsConnection)?->collect() ?? [];
        }
        $this->graphBuilder->setTableStats($tableStats);
        $statsCount = count($tableStats);
        $this->emit('step:done', ['step' => 'table_stats', 'count' => $statsCount, 'unit' => 'table', 'message' => '    Measured '.$statsCount.' table(s)']);

        // What those models actually read: columns, indexes and foreign keys, from the catalogue
        // rather than from migrations — migrations say what was intended, and a project of any
        // age has a schema that no longer matches the sum of them.
        $this->emit('step:start', ['step' => 'schema', 'label' => 'Reading database schema', 'message' => '  → Reading database schema...']);
        $schemas = [];
        if ($this->schemaEnabled) {
            $tables = [];
            foreach ($models as $definition) {
                if ($definition->table !== '') {
                    $tables[] = $definition->table;
                }
            }

            $schemas = SchemaInspector::forConnection($this->schemaConnection)?->inspect($tables) ?? [];
        }
        $tableByFqcn = [];
        foreach ($models as $fqcn => $definition) {
            if ($definition->table !== '') {
                $tableByFqcn[ltrim((string) $fqcn, '\\')] = $definition->table;
            }
        }
        $this->graphBuilder->setTableSchemas($schemas, $tableByFqcn);
        $this->emit('step:done', ['step' => 'schema', 'count' => count($schemas), 'unit' => 'table', 'message' => '    Read '.count($schemas).' table schema(s)']);

        $this->emit('step:start', ['step' => 'observers', 'label' => 'Scanning model observers', 'message' => '  → Scanning model observers...']);
        $observerMap = $this->observerAnalyzer->analyze($projectRoot);
        $observerCount = array_sum(array_map('count', $observerMap));
        $this->emit('step:done', ['step' => 'observers', 'count' => $observerCount, 'unit' => 'observer', 'message' => '    Found '.$observerCount.' model-observer link(s)']);

        $this->emit('step:start', ['step' => 'policies', 'label' => 'Resolving authorization policies', 'message' => '  → Resolving authorization policies...']);
        $policyMap = $this->policyAnalyzer->analyze($projectRoot, $modelFqcns, $psr4Map);
        $this->emit('step:done', ['step' => 'policies', 'count' => count($policyMap), 'unit' => 'policy', 'message' => '    Found '.count($policyMap).' model-policy link(s)']);

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

        $this->emit('step:start', ['step' => 'security', 'label' => 'Security surface map', 'message' => '  → Building security surface map...']);
        $externalByFile = (new ExternalSecurityScanner)->scan($projectRoot);
        $securityMap = $this->securityAnalyzer->analyze($routes, $middlewareRegistry, $controllers, $projectRoot, $externalByFile);
        $issueCount = array_sum(array_map(fn ($r) => count($r['issues']), $securityMap));
        $this->emit('step:done', ['step' => 'security', 'count' => $issueCount, 'unit' => 'issue', 'message' => "    Found {$issueCount} security issue(s) across ".count($securityMap).' route(s)']);

        $this->emit('step:start', ['step' => 'container_bindings', 'label' => 'Scanning service providers', 'message' => '  → Scanning service providers (IoC bindings)...']);
        $bindingRegistry = (new ContainerBindingAnalyzer(null, $this->bindingProviderPaths))->analyze($projectRoot);
        $bindingCount = count($bindingRegistry->all());
        $this->emit('step:done', ['step' => 'container_bindings', 'count' => $bindingCount, 'unit' => 'binding', 'message' => "    Found {$bindingCount} container binding(s)"]);

        $this->emit('step:start', ['step' => 'facades', 'label' => 'Scanning facades', 'message' => '  → Scanning application facades...']);
        $facadeRegistry = (new FacadeAnalyzer(null, $this->facadePaths))->analyze($projectRoot);
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
            $projectName, $routes, $middlewareRegistry, $controllers, $callChain, $models, $projectRoot, $dbQueryMap, $bindingRegistry, $facadeRegistry, $securityMap,
        );
        $this->graphBuilder->addConsoleCommands($commands, $schedules, $commandEdges);
        $this->graphBuilder->addChannels($channels, $channelEdges);
        $this->graphBuilder->addObservers($observerMap);
        $this->graphBuilder->addPolicies($policyMap);
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

        // Descend into view composition last, so every view node reached by a
        // route, console, channel, or Filament entry point seeds the walk.
        $this->emit('step:start', ['step' => 'views', 'label' => 'Mapping view composition', 'message' => '  → Mapping view composition...']);
        $viewComposition = $this->bladeViewAnalyzer->analyze($projectRoot);
        $this->graphBuilder->addViewComposition($viewComposition);
        $this->emit('step:done', ['step' => 'views', 'count' => count($viewComposition), 'unit' => 'composed view', 'message' => '    Mapped '.count($viewComposition).' composing view(s)']);

        // A scoped run has built only the changed files' share of the graph; the rest of it comes
        // from the previous full run, with those files' nodes substituted in place.
        //
        // That reuses every edge from the previous graph, which is only sound while the changed
        // files' own call graph is intact. Comparing the owned edges before and after is what
        // establishes it, and a mismatch means the edit moved a call — nothing here can stand in
        // for a full run then.
        if ($mergeInto !== null && $scopeToFiles !== null) {
            if (IncrementalMerge::ownedEdgeKeySet($mergeInto, $scopeToFiles)
                != IncrementalMerge::ownedEdgeKeySet($fullGraph, $scopeToFiles)) {
                throw new ScopedRebuildNotApplicable;
            }

            $fullGraph = IncrementalMerge::applyPartial($mergeInto, $fullGraph, $scopeToFiles);
        }

        $this->emit('step:done', ['step' => 'graph', 'count' => $fullGraph->nodeCount(), 'unit' => 'node', 'extra' => $fullGraph->edgeCount().' edges', 'message' => "    {$fullGraph->nodeCount()} nodes, {$fullGraph->edgeCount()} edges"]);

        $this->emit('step:start', ['step' => 'split', 'label' => 'Splitting into tab subgraphs', 'message' => '  → Splitting into tab subgraphs...']);
        $split = $this->graphSplitter->split($fullGraph, $routes, $commands, $channels, $schedules, $projectName, $analyzedAt, $filamentResult['panels'], $filamentResult['resources'], $filamentResult['pages']);

        // Ordered by name, because $models arrives led by whatever the call chain reached first
        // and the ERD is laid out in that order. Left as traced, the diagram reshuffles whenever
        // an unrelated controller changes which model it happens to touch first — and a scoped
        // rescan, tracing fewer controllers, would reorder it on every tick.
        $erdModels = $models;
        ksort($erdModels);

        $erd = $this->graphSplitter->buildErdTab($erdModels, $projectName, $analyzedAt, $tableStats, $schemas);
        if ($erd !== null) {
            $split['subgraphs'][$erd['id']] = $erd['graph'];
            $split['manifest'][] = $erd['manifest'];
        }

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
            unresolvedDispatchers: $this->methodTracer->unresolvedDispatchers(),
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

    /**
     * Coerce the result of a config() lookup into a list of non-empty
     * strings. Anything else (a non-array value, a key with mixed contents,
     * an empty string) is dropped silently so a typo in the user's config
     * can't crash the scan.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
