<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\EventFacts;
use LaraMint\LaravelBrain\Analysis\FilamentPageDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPanelDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentResourceDefinition;
use LaraMint\LaravelBrain\Analysis\ModelDefinition;
use LaraMint\LaravelBrain\Analysis\Reachability\ReachabilityReport;
use LaraMint\LaravelBrain\Analysis\Reachability\UnreachedClass;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;
use LaraMint\LaravelBrain\Analysis\SchemaIssueBuilder;
use LaraMint\LaravelBrain\Analysis\TableSchema;
use LaraMint\LaravelBrain\Analysis\TableStats;

class TabManifestEntry
{
    public function __construct(
        public string $id,
        public string $label,
        public int $routeCount,
        public int $nodeCount,
        public int $edgeCount,
        public string $file,
        public string $routeFile = '',
        public string $category = 'Route',
        public string $panelId = '',
        /** Total issues across the tab's lifecycle (security + n1 + fat method + fat class). */
        public int $issueCount = 0,
        /** none | low | medium | high | critical — highest security risk in the tab. */
        public string $riskLevel = 'none',
        public int $securityCount = 0,
        public int $n1Count = 0,
        public int $fatMethodCount = 0,
        public int $fatClassCount = 0,
        /**
         * When and how a scheduled task runs — null on every tab that is not one.
         *
         * Carried on the manifest rather than left in the tab's own graph file because the
         * sidebar renders the row before any graph is fetched, and a schedule row that cannot
         * say what it fires and when is the row this field exists to stop.
         *
         * @var array{type: string, target: string, cadence: string, timezone: string, modifiers: string[]}|null
         */
        public ?array $schedule = null,
    ) {}
}

class GraphSplitter
{
    /**
     * Split a full graph into per-tab subgraphs.
     *
     * @param  RouteDefinition[]  $routes
     * @param  ConsoleCommandDefinition[]  $commands
     * @param  ChannelDefinition[]  $channels
     * @param  ScheduleEntry[]  $schedules
     * @param  FilamentPanelDefinition[]  $filamentPanels
     * @param  FilamentResourceDefinition[]  $filamentResources
     * @param  FilamentPageDefinition[]  $filamentPages
     * @return array{ subgraphs: array<string, Graph>, manifest: TabManifestEntry[] }
     */
    public function split(
        Graph $fullGraph,
        array $routes,
        array $commands,
        array $channels,
        array $schedules,
        string $projectName,
        string $analyzedAt,
        array $filamentPanels = [],
        array $filamentResources = [],
        array $filamentPages = [],
    ): array {
        // Group routes by tabGroup
        $routesByTab = [];
        foreach ($routes as $route) {
            $routesByTab[$route->tabGroup][] = $route;
        }

        // Sort tabs alphabetically
        ksort($routesByTab);

        // Build TWO adjacency lists:
        // 1. Forward-only (for per-route tabs): route → action → service → model
        //    Excludes controller-to-action edges so the shared UserController node
        //    does NOT fan out to ALL sibling actions.
        // 2. Bidirectional (for the "all" tab only, kept for reference)
        $fwdAdj = $this->buildForwardAdjacency($fullGraph);
        $this->buildExtractionIndexes($fullGraph);

        $subgraphs = [];
        $manifest = [];

        foreach ($routesByTab as $tabGroup => $tabRoutes) {
            $tabId = $this->sanitizeId($tabGroup);

            // Seed with:
            // (a) the route node itself (to include it + its middleware via forward edges)
            // (b) the specific action node for each route (to start the lifecycle chain)
            $seeds = [];
            foreach ($tabRoutes as $r) {
                $seeds[] = "route::{$r->method}::{$r->uri}";

                // Also seed from the action node to traverse the lifecycle forward
                // independently of the shared Controller class node
                if ($r->controller && $r->action) {
                    $seeds[] = "action::{$r->controller}::{$r->action}";
                }
            }

            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, $seeds, $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $routeNodeIds = [];
            foreach ($tabRoutes as $r) {
                $routeNodeIds[] = "route::{$r->method}::{$r->uri}";
            }
            $agg = $this->aggregateIssues($fullGraph, $subgraph, $routeNodeIds);

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $tabGroup,
                routeCount: count($tabRoutes),
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($tabRoutes[0]->file),
                issueCount: $agg['total'],
                riskLevel: $agg['riskLevel'],
                securityCount: $agg['security'],
                n1Count: $agg['n1'],
                fatMethodCount: $agg['fatMethod'],
                fatClassCount: $agg['fatClass'],
            );

            // Help GC between large splits
            unset($tabRoutes, $seeds, $subgraph);
        }

        // ── Console command tabs ───────────────────────────────────────────────
        foreach ($commands as $cmd) {
            $tabId = $this->sanitizeId('cmd '.$cmd->signature);
            $seedId = "command::{$cmd->signature}";
            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, [$seedId], $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $cmd->signature,
                routeCount: 1,
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($cmd->file),
                category: 'Command',
            );
        }

        // ── Broadcast channel tabs ────────────────────────────────────────────
        foreach ($channels as $ch) {
            $tabId = $this->sanitizeId('channel '.$ch->name);
            $seedId = 'channel::'.md5($ch->name);
            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, [$seedId], $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $ch->name,
                routeCount: 1,
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($ch->file),
                category: 'Channel',
            );
        }

        // ── Scheduled-task tabs ───────────────────────────────────────────────
        // One tab per scheduled task, the way commands and channels already work. A single
        // "Scheduled Tasks" tab put exactly one row in the sidebar's Schedules bucket however
        // many tasks the app had, so the list answered "does this app schedule anything?" and
        // nothing else — what fires at 03:00 was only readable after opening the tab.
        $scheduleTabs = [];
        foreach ($schedules as $entry) {
            $seedId = $entry->nodeId();
            if (isset($scheduleTabs[$seedId])) {
                // The same task written twice produces one node, so it gets one tab.
                continue;
            }

            $baseId = $this->sanitizeId('schedule '.$entry->type.' '.$entry->target);
            $tabId = $baseId;
            $collision = 2;
            while (isset($subgraphs[$tabId])) {
                // Two tasks can differ only in cadence — the same command at 05:00 and at
                // 17:00 — and the sanitized id drops exactly that difference.
                $tabId = $baseId.'-'.$collision++;
            }
            $scheduleTabs[$seedId] = $tabId;

            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, [$seedId], $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $entry->target,
                routeCount: 1,
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($entry->file),
                category: 'Schedule',
                schedule: [
                    'type' => $entry->type,
                    'target' => $entry->target,
                    'cadence' => $entry->cadence(),
                    'timezone' => $entry->timezone,
                    'modifiers' => $entry->modifiers,
                ],
            );
        }

        // ── Filament resource tabs (one per page route, matching normal route behaviour) ──
        foreach ($filamentResources as $resource) {
            if (! empty($resource->pageRoutes)) {
                // Preferred path: one tab per Filament page route (GET /admin/posts, etc.)
                foreach ($resource->pageRoutes as $pageKey => [$method, $path]) {
                    $tabLabel = "{$method} {$path}";
                    $tabId = $this->sanitizeId($tabLabel);
                    $routeNodeId = "route::{$method}::{$path}";

                    // Seed from the route node (gives: route → resource → model chain)
                    // AND from the specific page for this route (gives: page → methods → services)
                    // mirroring how normal routes seed both the route and its action node.
                    // filament-resource-to-page edges are excluded from fwdAdj so the resource
                    // does NOT bleed sibling pages into this tab.
                    $seeds = [$routeNodeId];
                    if (isset($resource->pages[$pageKey])) {
                        $seeds[] = "filament_page::{$resource->pages[$pageKey]}";
                    }

                    $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, $seeds, $projectName, $analyzedAt);
                    $subgraphs[$tabId] = $subgraph;

                    $manifest[] = new TabManifestEntry(
                        id: $tabId,
                        label: $tabLabel,
                        routeCount: 1,
                        nodeCount: $subgraph->nodeCount(),
                        edgeCount: $subgraph->edgeCount(),
                        file: ".graph-{$tabId}.json",
                        routeFile: $resource->route !== '' ? $resource->route : $this->relativeRouteFile($resource->file),
                        category: 'Filament',
                        panelId: $resource->panelId,
                    );
                }
            } else {
                // Fallback: panel path unknown, show one tab seeded from the resource node
                $resourceNodeId = "filament_resource::{$resource->fqcn}";
                $shortName = str_replace('Resource', '', ltrim(strrchr($resource->fqcn, '\\') ?: $resource->fqcn, '\\'));
                $tabId = $this->sanitizeId('filament-resource-'.$resource->fqcn);

                $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, [$resourceNodeId], $projectName, $analyzedAt);
                $subgraphs[$tabId] = $subgraph;

                $manifest[] = new TabManifestEntry(
                    id: $tabId,
                    label: $shortName,
                    routeCount: 1,
                    nodeCount: $subgraph->nodeCount(),
                    edgeCount: $subgraph->edgeCount(),
                    file: ".graph-{$tabId}.json",
                    routeFile: $this->relativeRouteFile($resource->file),
                    category: 'Filament',
                    panelId: $resource->panelId,
                );
            }
        }

        // ── Filament custom-page tabs (non-resource pages with a computed route) ──
        // These give panels like "App Panel" visibility in the sidebar even when
        // they have no resources (e.g. Settings, RegisterTeam, Dashboard pages).
        foreach ($filamentPages as $page) {
            if ($page->parentResourceFqcn !== '' || $page->route === '') {
                continue; // resource sub-pages are already covered via their resource
            }
            $tabLabel = "GET {$page->route}";
            $tabId = $this->sanitizeId($tabLabel);
            if (isset($subgraphs[$tabId])) {
                continue; // already created (e.g. collision with a resource route)
            }
            $routeNodeId = "route::GET::{$page->route}";
            $pageNodeId = "filament_page::{$page->fqcn}";

            $seeds = [$routeNodeId, $pageNodeId];
            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, $seeds, $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $tabLabel,
                routeCount: 1,
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $page->route,
                category: 'Filament',
                panelId: $page->panelId,
            );
        }

        return ['subgraphs' => $subgraphs, 'manifest' => $manifest];
    }

    /**
     * Build the standalone "Model ERD" tab: one node per discovered model
     * carrying its full attribute/cast/key metadata, plus one edge per
     * relationship. Independent of routes — shows every model in the project.
     *
     * @param  array<string, ModelDefinition>  $models
     * @param  array<string, TableStats>  $tableStats  Keyed by table name; empty when no database was read.
     * @param  array<string, TableSchema>  $schemas  Keyed by table name; empty when no database was read.
     * @return array{id: string, graph: Graph, manifest: TabManifestEntry}|null
     */
    /**
     * A tab for the choreography: every event, what listens to it, and what those listeners fire
     * in turn.
     *
     * Separate from the route tabs on purpose. A route tab answers "what happens when this URL is
     * hit", and an event's whole point is that its consumers are not on that path — the question
     * "what does firing this set off" has no route to hang it on, and asking it a route at a time
     * is how a chain three listeners deep stays invisible.
     *
     * @param  CallChainEdge[]  $listenerEdges  event → listener
     * @param  array<string, list<string>>  $firedBy  listener FQCN => events it dispatches
     * @return array{id: string, graph: Graph, manifest: TabManifestEntry}|null
     */
    public function buildEventsTab(
        EventFacts $facts,
        array $listenerEdges,
        array $firedBy,
        string $projectName,
        string $analyzedAt,
    ): ?array {
        $events = $facts->events();

        if ($events === [] && $listenerEdges === []) {
            return null;
        }

        $graph = new Graph;
        $graph->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        foreach ($events as $fqcn => $event) {
            $graph->addNode(new Node(
                id: "event::{$fqcn}",
                type: 'event',
                label: $event->shortName(),
                data: [
                    'fqcn' => $fqcn,
                    'file' => $event->file,
                    'event' => $facts->eventPayload($fqcn),
                ],
            ));
        }

        foreach ($listenerEdges as $edge) {
            $eventId = 'event::'.ltrim($edge->callerFqcn, '\\');
            $listenerFqcn = ltrim($edge->calleeFqcn, '\\');
            $listenerId = "listener::{$listenerFqcn}";

            if (! $graph->hasNode($eventId)) {
                continue;
            }

            if (! $graph->hasNode($listenerId)) {
                $graph->addNode(new Node(
                    id: $listenerId,
                    type: 'listener',
                    label: $this->shortName($listenerFqcn),
                    data: [
                        'fqcn' => $listenerFqcn,
                        'listener' => $facts->listenerPayload($listenerFqcn),
                    ],
                ));
            }

            $graph->addEdge(new Edge(
                id: 'e_'.hash('xxh128', $eventId."\x1f".$listenerId),
                source: $eventId,
                target: $listenerId,
                label: $facts->listenerPayload($listenerFqcn)['queued'] === true ? 'queued' : 'handles',
                type: 'event-to-listener',
            ));
        }

        // The second hop, which is what makes this a choreography rather than a list: a listener
        // that fires an event of its own continues the chain, and a chain that comes back to an
        // event it already passed through is a cycle somebody should know about.
        foreach ($firedBy as $listenerFqcn => $fired) {
            $listenerId = 'listener::'.ltrim((string) $listenerFqcn, '\\');

            if (! $graph->hasNode($listenerId)) {
                continue;
            }

            foreach ($fired as $eventFqcn) {
                $eventId = 'event::'.ltrim($eventFqcn, '\\');

                if (! $graph->hasNode($eventId)) {
                    continue;
                }

                $graph->addEdge(new Edge(
                    id: 'e_'.hash('xxh128', $listenerId."\x1f".$eventId),
                    source: $listenerId,
                    target: $eventId,
                    label: 'fires',
                    type: 'listener-to-event',
                ));
            }
        }

        $tabId = 'events--choreography';

        return [
            'id' => $tabId,
            'graph' => $graph,
            'manifest' => new TabManifestEntry(
                id: $tabId,
                label: 'Events',
                routeCount: count($events),
                nodeCount: $graph->nodeCount(),
                edgeCount: $graph->edgeCount(),
                file: ".graph-{$tabId}.json",
                category: 'Events',
            ),
        ];
    }

    public function buildErdTab(
        array $models,
        string $projectName,
        string $analyzedAt,
        array $tableStats = [],
        array $schemas = [],
    ): ?array {
        if (empty($models)) {
            return null;
        }

        $graph = new Graph;
        $graph->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        $present = [];
        $byShort = []; // short class name => FQCN (for same-namespace ::class refs)
        foreach (array_keys($models) as $fqcn) {
            $byShort[strtolower($this->shortName((string) $fqcn))] = (string) $fqcn;
        }
        foreach ($models as $fqcn => $def) {
            $nodeId = "model::{$fqcn}";
            $present[$fqcn] = $nodeId;
            $graph->addNode(new Node(
                id: $nodeId,
                type: 'model',
                label: $this->shortName($fqcn),
                data: [
                    'fqcn' => $fqcn,
                    'file' => $def->file,
                    'erd' => [
                        'table' => $def->table,
                        'primaryKey' => $def->primaryKey,
                        'keyType' => $def->keyType,
                        'incrementing' => $def->incrementing,
                        'timestamps' => $def->timestamps,
                        'softDeletes' => $def->usesSoftDeletes,
                        'fillable' => $def->fillable,
                        'guarded' => $def->guarded,
                        'casts' => $def->casts,
                        'dates' => $def->dates,
                        'appends' => $def->appends,
                        'accessors' => $def->accessors,
                        'relationships' => $def->relationships,
                        'morphAlias' => $def->morphAlias,
                        'morphAliasMissing' => $def->morphAliasMissing,
                    ],
                ],
            ));

            // Two independent readings of the same table, and a model may have either, both or
            // neither: how much it holds, and what shape it is in. Written in one update because
            // `updateNodeData` replaces rather than merges — two sequential writes would have the
            // second drop whatever the first added.
            $stats = $def->table !== '' ? ($tableStats[$def->table] ?? null) : null;
            $schema = $def->table !== '' ? ($schemas[$def->table] ?? null) : null;

            if ($stats !== null || $schema !== null) {
                $node = $graph->getNode($nodeId);
                if ($node !== null) {
                    $issues = $schema !== null ? (new SchemaIssueBuilder)->forTable($schema) : null;

                    $graph->updateNodeData($nodeId, [
                        ...$node->data,
                        ...($stats !== null ? ['tableStats' => $stats->toArray()] : []),
                        ...($schema !== null ? ['schema' => $schema->toArray()] : []),
                        ...($issues !== null ? ['security' => $issues] : []),
                    ]);
                }
            }
        }

        $seenEdge = [];
        foreach ($models as $fqcn => $def) {
            $sourceId = "model::{$fqcn}";
            foreach ($def->relationships as $rel) {
                $related = ltrim((string) ($rel['related'] ?? ''), '\\');
                if ($related === '') {
                    continue;
                }
                if (! isset($present[$related]) && ! str_contains($related, '\\')) {
                    // Same-namespace `Related::class` wasn't resolved to a FQCN
                    // by the model parser — match it back by short name.
                    $resolved = $byShort[strtolower($related)] ?? null;
                    if ($resolved !== null) {
                        $related = $resolved;
                    }
                }
                if (! isset($present[$related])) {
                    // Related model lives outside discovery (vendor / dynamic) —
                    // add a lightweight placeholder so the edge still renders.
                    $present[$related] = "model::{$related}";
                    $graph->addNode(new Node(
                        id: "model::{$related}",
                        type: 'model',
                        label: $this->shortName($related),
                        data: ['fqcn' => $related, 'external' => true],
                    ));
                }
                $targetId = "model::{$related}";
                $key = $sourceId.'|'.$targetId.'|'.$rel['type'];
                if (isset($seenEdge[$key])) {
                    continue;
                }
                $seenEdge[$key] = true;
                $graph->addEdge(new Edge(
                    id: 'rel::'.md5($key),
                    source: $sourceId,
                    target: $targetId,
                    label: (string) $rel['type'],
                    type: 'relationship',
                ));
            }
        }

        $tabId = 'erd--models';

        return [
            'id' => $tabId,
            'graph' => $graph,
            'manifest' => new TabManifestEntry(
                id: $tabId,
                label: 'Model ERD',
                routeCount: count($models),
                nodeCount: $graph->nodeCount(),
                edgeCount: $graph->edgeCount(),
                file: ".graph-{$tabId}.json",
                category: 'ERD',
            ),
        ];
    }

    /**
     * Build the standalone "AI Agents" tab: every `laravel/ai` agent, the tools it can call, and
     * the methods that prompt it.
     *
     * A tab of its own, rather than relying on the route walk, because measurement said the route
     * walk never gets there. On a real application with 5 agents and 17 tools, every call site
     * resolved to a queued job, a service or a listener helper that no route reaches statically —
     * so all 22 AI nodes sat in the full graph and in **no tab at all**, which is the same as
     * being absent for anyone looking at the UI. Wiring the caller edges (which was a real bug,
     * and is fixed) moved that number from 22 isolated to 0 isolated and still 22 invisible.
     *
     * Assembled node by node like the ERD tab rather than by walking forward from a seed: a
     * forward walk out of a caller would drag in that method's whole downstream subtree, and this
     * screen answers one question — which code talks to an LLM, using what.
     *
     * @return array{id: string, graph: Graph, manifest: TabManifestEntry}|null
     */
    public function buildAiTab(Graph $fullGraph, string $projectName, string $analyzedAt): ?array
    {
        $agentCount = 0;
        foreach ($fullGraph->nodes() as $node) {
            if ($node->type === 'ai_agent') {
                $agentCount++;
            }
        }

        if ($agentCount === 0) {
            return null;
        }

        $graph = new Graph;
        $graph->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        foreach ($fullGraph->nodes() as $node) {
            if ($node->type === 'ai_agent' || $node->type === 'ai_tool') {
                $graph->addNode($node);
            }
        }

        foreach ($fullGraph->edges() as $edge) {
            if (! str_starts_with($edge->type, 'ai-')) {
                continue;
            }

            // The caller is whatever the rest of the graph made it — a job, a service, a
            // controller action. It is pulled in as-is so the tab shows where the call comes from.
            foreach ([$edge->source, $edge->target] as $endpoint) {
                if (! $graph->hasNode($endpoint)) {
                    $node = $fullGraph->getNode($endpoint);
                    if ($node !== null) {
                        $graph->addNode($node);
                    }
                }
            }

            if ($graph->hasNode($edge->source) && $graph->hasNode($edge->target)) {
                $graph->addEdge($edge);
            }
        }

        $tabId = 'ai--agents';

        return [
            'id' => $tabId,
            'graph' => $graph,
            'manifest' => new TabManifestEntry(
                id: $tabId,
                label: 'AI Agents',
                routeCount: $agentCount,
                nodeCount: $graph->nodeCount(),
                edgeCount: $graph->edgeCount(),
                file: ".graph-{$tabId}.json",
                category: 'AI',
            ),
        ];
    }

    /**
     * Nodes of the full graph that reached none of the tabs, counted by type.
     *
     * The stricter companion to {@see Graph::isolatedNodeCountsByType()}, and the one that
     * actually predicts what a reader sees: a node can be correctly wired to its neighbours and
     * still sit in a cluster no tab seed reaches. Measured on a real application, the isolated
     * count would have reported nothing wrong while 22 nodes were invisible.
     *
     * @param  array<string, Graph>  $subgraphs
     * @return array<string, int> node type => count, highest first
     */
    public static function nodesOutsideTabs(Graph $fullGraph, array $subgraphs): array
    {
        $shown = [];

        foreach ($subgraphs as $subgraph) {
            foreach ($subgraph->nodes() as $node) {
                $shown[$node->id] = true;
            }
        }

        $counts = [];

        foreach ($fullGraph->nodes() as $node) {
            if (! isset($shown[$node->id])) {
                $counts[$node->type] = ($counts[$node->type] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    private function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    public function buildManifestJson(
        array $manifest,
        Graph $fullGraph,
        string $projectName,
        string $analyzedAt,
        int $totalRoutes,
    ): string {
        $tabs = [];
        foreach ($manifest as $entry) {
            $tab = [
                'id' => $entry->id,
                'label' => $entry->label,
                'routeCount' => $entry->routeCount,
                'nodeCount' => $entry->nodeCount,
                'edgeCount' => $entry->edgeCount,
                'file' => $entry->file,
                'routeFile' => $entry->routeFile,
                'category' => $entry->category,
            ];
            if ($entry->panelId !== '') {
                $tab['panelId'] = $entry->panelId;
            }
            if ($entry->schedule !== null) {
                $tab['schedule'] = $entry->schedule;
            }
            if ($entry->issueCount > 0) {
                $tab['issueCount'] = $entry->issueCount;
                $tab['riskLevel'] = $entry->riskLevel;
                $tab['securityCount'] = $entry->securityCount;
                $tab['n1Count'] = $entry->n1Count;
                $tab['fatMethodCount'] = $entry->fatMethodCount;
                $tab['fatClassCount'] = $entry->fatClassCount;
            }
            $tabs[] = $tab;
        }

        $json = json_encode([
            'project' => $projectName,
            'analyzedAt' => $analyzedAt,
            // Bumped to 2 when edge ids became content-addressed (stable across rebuilds) instead
            // of insertion-sequential. Consumers that persisted v1 edge ids should invalidate once.
            'graphFormatVersion' => 2,
            'totalRoutes' => $totalRoutes,
            'totalNodes' => $fullGraph->nodeCount(),
            'totalEdges' => $fullGraph->edgeCount(),
            'tabs' => $tabs,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            throw new \RuntimeException('Failed to encode manifest to JSON: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * Aggregate every issue category surfaced for a tab:
     *  - security issues live on the route node(s) (data.security.issues)
     *  - N+1 / fat-method / fat-class flags live on lifecycle nodes
     *    (actions, services, …) reachable in the tab's subgraph.
     *
     * @param  string[]  $routeNodeIds
     * @return array{total: int, riskLevel: string, security: int, n1: int, fatMethod: int, fatClass: int}
     */
    private function aggregateIssues(Graph $fullGraph, Graph $subgraph, array $routeNodeIds): array
    {
        $order = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        $security = 0;
        $risk = 'none';

        foreach ($routeNodeIds as $id) {
            $node = $fullGraph->getNode($id);
            if ($node === null) {
                continue;
            }
            $sec = $node->data['security'] ?? null;
            if (! is_array($sec)) {
                continue;
            }
            $issues = $sec['issues'] ?? [];
            if (is_array($issues)) {
                $security += count($issues);
            }
            $level = is_string($sec['riskLevel'] ?? null) ? $sec['riskLevel'] : 'none';
            if (($order[$level] ?? 0) > ($order[$risk] ?? 0)) {
                $risk = $level;
            }
        }

        $n1 = 0;
        $fatMethod = 0;
        $fatClass = 0;
        foreach ($subgraph->nodes() as $node) {
            if (($node->data['hasN1'] ?? false) === true) {
                $n1++;
            }
            if (($node->data['fatMethod'] ?? false) === true) {
                $fatMethod++;
            }
            if (($node->data['fatClass'] ?? false) === true) {
                $fatClass++;
            }
        }

        return [
            'total' => $security + $n1 + $fatMethod + $fatClass,
            'riskLevel' => $risk,
            'security' => $security,
            'n1' => $n1,
            'fatMethod' => $fatMethod,
            'fatClass' => $fatClass,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Forward-only adjacency, excluding "fan-out" edges that would pull sibling
     * nodes into every consumer's subgraph.
     *
     * Excluded edge types and the reason:
     *
     *  controller-to-action      — UserController has edges to ALL its actions.
     *                              We seed from the specific action directly instead.
     *
     *  filament-resource-to-page — A resource registers ALL its pages (index, create,
     *                              edit, view). We seed from the specific page for
     *                              each route tab directly, so other pages must not
     *                              bleed in via the shared resource node.
     */
    private function buildForwardAdjacency(Graph $fullGraph): array
    {
        $adj = [];
        foreach ($fullGraph->edges() as $edge) {
            if ($edge->type === 'controller-to-action') {
                continue;
            }
            if ($edge->type === 'filament-resource-to-page') {
                continue;
            }

            $adj[$edge->source][] = $edge->target;
        }

        return $adj;
    }

    /**
     * Position-indexed views of the full graph, built once per split(). Extracting a tab's
     * subgraph used to rescan EVERY node and edge of the full graph per tab — O(tabs × (N+E)),
     * quadratic in app size since both factors grow with it (the scale-8 sweep measured
     * exponent 1.93, with split the largest analyze phase). With these indexes each tab only
     * touches its own BFS-reachable set; original insertion order is restored from the stored
     * positions so the emitted subgraph JSON is byte-identical.
     */
    private array $nodePositions = [];

    /** @var array<string, list<array{int, Edge}>> source id → [original position, edge] */
    private array $edgesBySource = [];

    private function buildExtractionIndexes(Graph $fullGraph): void
    {
        $this->nodePositions = [];
        foreach ($fullGraph->nodes() as $i => $node) {
            $this->nodePositions[$node->id] = $i;
        }
        $this->edgesBySource = [];
        foreach ($fullGraph->edges() as $i => $edge) {
            $this->edgesBySource[$edge->source][] = [$i, $edge];
        }
    }

    private function extractSubgraphForward(
        Graph $fullGraph,
        array $fwdAdj,
        array $seeds,
        string $projectName,
        string $analyzedAt,
    ): Graph {
        $reachable = $this->bfs($fwdAdj, $seeds);

        $sub = new Graph;
        $sub->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        $nodePositions = [];
        $edgesByPosition = [];
        foreach ($reachable as $id => $_) {
            if (isset($this->nodePositions[$id])) {
                $nodePositions[$this->nodePositions[$id]] = $id;
            }
            foreach ($this->edgesBySource[$id] ?? [] as [$pos, $edge]) {
                if (isset($reachable[$edge->target])) {
                    $edgesByPosition[$pos] = $edge;
                }
            }
        }

        ksort($nodePositions);
        foreach ($nodePositions as $id) {
            $sub->addNode($fullGraph->getNode($id));
        }
        ksort($edgesByPosition);
        foreach ($edgesByPosition as $edge) {
            $sub->addEdge($edge);
        }

        return $sub;
    }

    private function bfs(array $adj, array $seeds): array
    {
        $visited = [];
        $queue = $seeds;

        while (! empty($queue)) {
            $id = array_shift($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            foreach ($adj[$id] ?? [] as $neighbor) {
                if (! isset($visited[$neighbor])) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $visited;
    }

    private function relativeRouteFile(string $fullPath): string
    {
        if ($fullPath === '') {
            return 'routes.php';
        }
        // Extract path relative to the routes/ directory, e.g. "v1/users.php"
        if (preg_match('#[/\\\\]routes[/\\\\](.+)$#', $fullPath, $m)) {
            return str_replace('\\', '/', $m[1]);
        }

        return basename($fullPath);
    }

    private function sanitizeId(string $group): string
    {
        // "POST /api/orders" → "post-api-orders"
        $id = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $group), '-'));

        // Avoid "File name too long" errors (max filename usually 255 chars).
        // We limit the ID to 100 chars, then append a hash for uniqueness if it was long.
        if (strlen($id) > 100) {
            return substr($id, 0, 100).'-'.substr(md5($group), 0, 8);
        }

        return $id;
    }

    /**
     * Build the standalone "Reachability" tab: the roots the application can be entered
     * from, and the classes no root's call chain arrives at.
     *
     * Every other tab is grown forward from one entry point, which is why a gap in the graph
     * has never been visible from inside it — measured on one application the graph knew 45
     * of 211 event classes and 27 of 113 job classes, and no screen said so. This tab is the
     * inverse view, and independent of routes for the same reason the ERD tab is.
     *
     * Three sections, in the order a reader needs them:
     *
     *  1. Entry points, by kind. The denominator — nothing is reachable except through one
     *     of these, so their inventory is what makes the other two sections mean anything.
     *  2. Classes nothing reaches, by kind, largest kind first. Each carries every reference
     *     Brain found and could not follow, because "nothing reaches this from a traced
     *     entry point" and "this is dead code" are different sentences and the second one is
     *     not Brain's to make.
     *  3. Kinds the tracer has no edge type for at all — service providers, exceptions. Kept
     *     apart rather than mixed in: their absence from the graph is the expected outcome,
     *     and a hundred non-findings on top of the real ones is how a report gets ignored.
     *
     * @return array{id: string, graph: Graph, manifest: TabManifestEntry}|null
     */
    public function buildReachabilityTab(
        ReachabilityReport $report,
        string $projectName,
        string $analyzedAt,
    ): ?array {
        if ($report->entryPoints === [] && $report->unreached === []) {
            return null;
        }

        $graph = new Graph;
        $graph->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        $this->addEntryPointSection($graph, $report);
        $this->addUnreachedSection(
            $graph,
            $report->unreachedByKind(),
            'reachability::unreached',
            'Nothing reaches these from an entry point',
            self::UNREACHED_NOTE,
        );
        $this->addUnreachedSection(
            $graph,
            $report->unreachedByKind(tracerBlind: true),
            'reachability::unfollowed',
            'Outside what the tracer follows',
            self::TRACER_BLIND_NOTE,
        );

        $tabId = 'reachability--inventory';

        return [
            'id' => $tabId,
            'graph' => $graph,
            'manifest' => new TabManifestEntry(
                id: $tabId,
                label: 'Reachability',
                routeCount: count($report->unreached),
                nodeCount: $graph->nodeCount(),
                edgeCount: $graph->edgeCount(),
                file: ".graph-{$tabId}.json",
                category: 'Reachability',
            ),
        ];
    }

    /**
     * The sentence this tab must never be read as saying something stronger than.
     */
    private const UNREACHED_NOTE = 'No traced call chain from an entry point arrives at these classes. '
        .'That is a statement about what the tracer can follow, not about whether the code runs: '
        .'anything resolved out of the container, fronted by a facade, named as a string in config, '
        .'or built by reflection is invisible to it. Every reference Brain did find is listed on the class.';

    private const TRACER_BLIND_NOTE = 'Brain has no call edge for these kinds at all — the framework '
        .'boots a service provider and an exception is thrown rather than called — so their absence '
        .'from the graph is expected and says nothing either way. They are listed for inventory only.';

    private function addEntryPointSection(Graph $graph, ReachabilityReport $report): void
    {
        $rootId = 'reachability::entry-points';
        $graph->addNode(new Node(
            id: $rootId,
            type: 'entry_point_group',
            label: 'Entry points ('.count($report->entryPoints).')',
            data: [
                'section' => 'entry-points',
                'count' => count($report->entryPoints),
                'classesDeclared' => $report->classesDeclared,
                'classesReached' => $report->classesReached,
                'note' => 'Every root the application can be entered from. Nothing in the graph is '
                    .'reachable except through one of these.',
            ],
        ));

        foreach ($report->entryPointsByKind() as $kind => $entryPoints) {
            $groupId = $rootId.'::'.$kind;
            $graph->addNode(new Node(
                id: $groupId,
                type: 'entry_point_group',
                label: $this->groupLabel($kind, count($entryPoints)),
                // Folded on arrival: the members are the inventory, and a canvas that opens
                // with every one of them drawn is unreadable at any zoom. The group says how
                // many it holds; the reader opens the one they came for.
                data: ['section' => 'entry-points', 'kind' => $kind, 'count' => count($entryPoints), 'collapsedByDefault' => true],
            ));
            $this->addGroupEdge($graph, $rootId, $groupId, 'entry-point-group');

            foreach ($entryPoints as $index => $entryPoint) {
                $nodeId = $groupId.'::'.$index;
                $graph->addNode(new Node(
                    id: $nodeId,
                    type: 'entry_point',
                    label: $entryPoint->label,
                    data: [
                        'section' => 'entry-points',
                        'kind' => $kind,
                        'fqcn' => $entryPoint->fqcn,
                        'file' => $entryPoint->file,
                        'detail' => $entryPoint->detail,
                    ],
                ));
                $this->addGroupEdge($graph, $groupId, $nodeId, 'entry-point');
            }
        }
    }

    /**
     * @param  array<string, list<UnreachedClass>>  $byKind
     */
    private function addUnreachedSection(
        Graph $graph,
        array $byKind,
        string $rootId,
        string $rootLabel,
        string $note,
    ): void {
        if ($byKind === []) {
            return;
        }

        $total = 0;
        foreach ($byKind as $classes) {
            $total += count($classes);
        }

        $graph->addNode(new Node(
            id: $rootId,
            type: 'unreached_group',
            label: "{$rootLabel} ({$total})",
            data: ['section' => 'unreached', 'count' => $total, 'note' => $note],
        ));

        foreach ($byKind as $kind => $classes) {
            $groupId = $rootId.'::'.$kind;
            $graph->addNode(new Node(
                id: $groupId,
                type: 'unreached_group',
                label: $this->groupLabel($kind, count($classes)),
                data: ['section' => 'unreached', 'kind' => $kind, 'count' => count($classes), 'note' => $note, 'collapsedByDefault' => true],
            ));
            $this->addGroupEdge($graph, $rootId, $groupId, 'unreached-group');

            foreach ($classes as $class) {
                $nodeId = 'unreached::'.strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '_', $class->fqcn));
                $graph->addNode(new Node(
                    id: $nodeId,
                    type: 'unreached_class',
                    label: $this->shortName($class->fqcn),
                    data: [
                        'section' => 'unreached',
                        'kind' => $kind,
                        'fqcn' => $class->fqcn,
                        'file' => $class->file,
                        'unfollowableReferences' => $class->unfollowableReferences,
                        'tracerBlind' => $class->tracerBlind,
                        'note' => $note,
                    ],
                ));
                $this->addGroupEdge($graph, $groupId, $nodeId, 'unreached');
            }
        }
    }

    private function addGroupEdge(Graph $graph, string $source, string $target, string $type): void
    {
        $graph->addEdge(new Edge(
            id: 'reach::'.md5($source.'|'.$target),
            source: $source,
            target: $target,
            label: '',
            type: $type,
        ));
    }

    /**
     * Plural group heading for a kind. Unknown kinds fall through to the kind name with an
     * "s" — a new node type added elsewhere in Brain then reads slightly awkwardly rather
     * than vanishing from the tab.
     */
    private function groupLabel(string $kind, int $count): string
    {
        $label = match ($kind) {
            'route' => 'Routes',
            'command' => 'Console commands',
            'schedule' => 'Scheduled entries',
            'channel' => 'Broadcast channels',
            'queued_listener' => 'Queued listeners',
            'filament' => 'Filament',
            'abstract_class' => 'Abstract classes',
            'policy' => 'Policies',
            'repository' => 'Repositories',
            'service_provider' => 'Service providers',
            'middleware' => 'Middleware',
            'mail' => 'Mailables',
            'notification' => 'Notifications',
            'resource' => 'API resources',
            'controller' => 'Controllers',
            'exception' => 'Exceptions',
            'interface' => 'Interfaces',
            'trait' => 'Traits',
            'enum' => 'Enums',
            'model' => 'Models',
            'listener' => 'Listeners',
            'observer' => 'Observers',
            'service' => 'Services',
            'event' => 'Events',
            'job' => 'Jobs',
            default => ucfirst(str_replace('_', ' ', $kind)).'s',
        };

        return "{$label} ({$count})";
    }
}
