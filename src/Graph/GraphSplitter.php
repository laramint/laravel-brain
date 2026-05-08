<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPageDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentPanelDefinition;
use LaraMint\LaravelBrain\Analysis\FilamentResourceDefinition;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\ScheduleEntry;

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
        public string $uriPrefix = '',
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

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $tabGroup,
                routeCount: count($tabRoutes),
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $this->relativeRouteFile($tabRoutes[0]->file),
                uriPrefix: $tabRoutes[0]->uriPrefix,
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
                'uriPrefix' => $entry->uriPrefix,
            ];
            if ($entry->panelId !== '') {
                $tab['panelId'] = $entry->panelId;
            }
            $tabs[] = $tab;
        }

        $json = json_encode([
            'project' => $projectName,
            'analyzedAt' => $analyzedAt,
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

        foreach ($fullGraph->nodes() as $node) {
            if (isset($reachable[$node->id])) {
                $sub->addNode($node);
            }
        }
        foreach ($fullGraph->edges() as $edge) {
            if (isset($reachable[$edge->source]) && isset($reachable[$edge->target])) {
                $sub->addEdge($edge);
            }
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
