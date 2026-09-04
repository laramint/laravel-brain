<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPageDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPanelDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentResourceDefinition;
use LaraMint\LaravelBrain\Analysis\ModelDefinition;
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
        if (! empty($schedules)) {
            $scheduleFile = $schedules[0]->file ?? '';
            $seeds = [];
            foreach ($schedules as $entry) {
                $seeds[] = 'schedule::'.md5($entry->type.$entry->target.$entry->frequency);
            }
            $tabId = 'schedule--tasks';
            $subgraph = $this->extractSubgraphForward($fullGraph, $fwdAdj, $seeds, $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: 'Scheduled Tasks',
                routeCount: count($schedules),
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($scheduleFile),
                category: 'Schedule',
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
}
