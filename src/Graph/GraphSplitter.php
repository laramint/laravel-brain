<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Graph;

use LaraMint\LaravelBrain\Analysis\ChannelDefinition;
use LaraMint\LaravelBrain\Analysis\ConsoleCommandDefinition;
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
    ): array {
        // Always keep route-level tabs so sidebar file groups (e.g. web.php)
        // can expand to their contained routes.
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
        $allNodes = $fullGraph->nodes();
        $allEdges = $fullGraph->edges();

        $subgraphs = [];
        $manifest = [];

        foreach ($routesByTab as $tabGroup => $tabRoutes) {
            $routeFileLabel = $this->relativeRouteFile($tabRoutes[0]->file);
            $label = $tabGroup;
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

            $subgraph = $this->extractSubgraphForward($allNodes, $allEdges, $fwdAdj, $seeds, $projectName, $analyzedAt);
            $subgraphs[$tabId] = $subgraph;

            $manifest[] = new TabManifestEntry(
                id: $tabId,
                label: $label,
                routeCount: count($tabRoutes),
                nodeCount: $subgraph->nodeCount(),
                edgeCount: $subgraph->edgeCount(),
                file: ".graph-{$tabId}.json",
                routeFile: $routeFileLabel,
            );

            // Help GC between large splits
            unset($tabRoutes, $seeds, $subgraph);
        }

        // ── Console command tabs ───────────────────────────────────────────────
        foreach ($commands as $cmd) {
            $tabId = $this->sanitizeId('cmd '.$cmd->signature);
            $seedId = "command::{$cmd->signature}";
            $subgraph = $this->extractSubgraphForward($allNodes, $allEdges, $fwdAdj, [$seedId], $projectName, $analyzedAt);
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
            $subgraph = $this->extractSubgraphForward($allNodes, $allEdges, $fwdAdj, [$seedId], $projectName, $analyzedAt);
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
            $subgraph = $this->extractSubgraphForward($allNodes, $allEdges, $fwdAdj, $seeds, $projectName, $analyzedAt);
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
            $tabs[] = [
                'id' => $entry->id,
                'label' => $entry->label,
                'routeCount' => $entry->routeCount,
                'nodeCount' => $entry->nodeCount,
                'edgeCount' => $entry->edgeCount,
                'file' => $entry->file,
                'routeFile' => $entry->routeFile,
                'category' => $entry->category,
            ];
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
     * Forward-only adjacency, EXCLUDING controller-to-action edges.
     *
     * Why exclude controller-to-action?
     *   UserController has edges to ALL its actions (index, store, show, update, destroy).
     *   If we follow those forward, every route that calls UserController would pull in
     *   ALL actions, not just its own. By excluding these edges, each route's BFS
     *   only reaches its own action (because we seed directly from the action node).
     */
    private function buildForwardAdjacency(Graph $fullGraph): array
    {
        $adj = [];
        foreach ($fullGraph->edges() as $edge) {
            // Skip the controller→action fan-out edge; we seed from action directly
            if ($edge->type === 'controller-to-action') {
                continue;
            }

            $adj[$edge->source][] = $edge->target;
        }

        return $adj;
    }

    private function extractSubgraphForward(
        array $allNodes,
        array $allEdges,
        array $fwdAdj,
        array $seeds,
        string $projectName,
        string $analyzedAt,
    ): Graph {
        $reachable = $this->bfs($fwdAdj, $seeds);

        $sub = new Graph;
        $sub->setMeta(['project' => $projectName, 'analyzedAt' => $analyzedAt]);

        foreach ($allNodes as $node) {
            if (isset($reachable[$node->id])) {
                $sub->addNode($node);
            }
        }
        foreach ($allEdges as $edge) {
            if (isset($reachable[$edge->source]) && isset($reachable[$edge->target])) {
                $sub->addEdge($edge);
            }
        }

        return $sub;
    }

    private function bfs(array $adj, array $seeds): array
    {
        $visited = [];
        $queue = array_values($seeds);
        $index = 0;

        while ($index < count($queue)) {
            $id = $queue[$index++];
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

        $normalized = str_replace('\\', '/', $fullPath);

        // Keep package context for package route files, e.g. "packages/Hrm/src/Routes/web.php"
        if (preg_match('#/(packages/[^/]+/src/routes/.+)$#i', $normalized, $m)) {
            return $m[1];
        }

        // Extract path relative to any routes/ directory, e.g. "v1/users.php"
        if (preg_match('#/routes/(.+)$#i', $normalized, $m)) {
            return $m[1];
        }

        return basename($normalized);
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
