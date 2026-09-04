<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Ai;

use LaraMint\LaravelBrain\Storage\GraphStore;

class ContextExporter
{
    private const CHARS_PER_TOKEN = 4;

    private const TYPE_PRIORITY = [
        'route', 'controller', 'action', 'validation_request', 'action_class', 'service',
        'model', 'event', 'job', 'command', 'channel', 'schedule', 'middleware',
        'view', 'mail', 'notification', 'enum', 'interface', 'trait', 'abstract_class',
        'ai_agent', 'ai_tool',
        'filament_panel', 'filament_resource', 'filament_page', 'filament_widget', 'filament_relation_manager',
    ];

    /**
     * How much of what is left after the structural essentials the dependency tables may take.
     */
    private const PACKAGE_BUDGET_SHARE = 0.15;

    public function __construct(
        private readonly GraphStore $store,
        private readonly string $projectPath = '',
    ) {}

    public function export(
        ?string $nodeId = null,
        ?string $routeLabel = null,
        int $budget = 6000,
        string $format = 'markdown',
    ): string {
        // Reconstructed from the per-tab subgraphs (no monolithic graph).
        $graph = MergedGraph::load($this->store);

        /** @var array<string, array<string, mixed>> $nodeIndex */
        $nodeIndex = [];
        foreach ($graph['nodes'] ?? [] as $node) {
            $nodeIndex[(string) $node['id']] = $node;
        }

        /** @var array<string, list<string>> $adjacency */
        $adjacency = [];
        /** @var array<int, array<string, mixed>> $allEdges */
        $allEdges = $graph['edges'] ?? [];
        foreach ($allEdges as $edge) {
            $src = (string) $edge['source'];
            $adjacency[$src][] = (string) $edge['target'];
        }

        $focalId = $this->resolveFocalId($nodeId, $routeLabel, $nodeIndex);

        $project = (string) ($graph['meta']['project'] ?? 'unknown');
        $analyzedAt = (string) ($graph['meta']['analyzedAt'] ?? '');
        $focalLabel = $focalId !== null
            ? (string) ($nodeIndex[$focalId]['label'] ?? $focalId)
            : 'Full project summary';

        if ($focalId === null) {
            [$selectedNodes, $selectedEdges] = $this->buildSummarySelection($nodeIndex, $allEdges);
        } else {
            [$selectedNodes, $selectedEdges] = $this->bfsSelect($focalId, $nodeIndex, $adjacency, $allEdges);
        }

        $context = new AiContext(
            project: $project,
            analyzedAt: $analyzedAt,
            focalLabel: $focalLabel,
            tokenBudget: $budget,
            format: $format,
            nodes: $selectedNodes,
            edges: $selectedEdges,
        );

        return $format === 'json' ? $this->toJson($context) : $this->toMarkdown($context);
    }

    // ── Focal node resolution ─────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $nodeIndex
     */
    private function resolveFocalId(?string $nodeId, ?string $routeLabel, array $nodeIndex): ?string
    {
        if ($nodeId !== null && isset($nodeIndex[$nodeId])) {
            return $nodeId;
        }

        if ($routeLabel !== null) {
            $needle = strtolower($routeLabel);
            foreach ($nodeIndex as $id => $node) {
                if (strtolower((string) ($node['label'] ?? '')) === $needle) {
                    return $id;
                }
                $uri = strtolower((string) ($node['data']['uri'] ?? ''));
                if ($uri !== '' && $uri === $needle) {
                    return $id;
                }
            }
        }

        return null;
    }

    // ── BFS subgraph selection ─────────────────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $nodeIndex
     * @param  array<string, list<string>>  $adjacency
     * @param  array<int, array<string, mixed>>  $allEdges
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function bfsSelect(
        string $focalId,
        array $nodeIndex,
        array $adjacency,
        array $allEdges,
    ): array {
        $visited = [$focalId => 0];
        $queue = [$focalId];
        $orderedIds = [];

        while (! empty($queue)) {
            $current = array_shift($queue);
            $orderedIds[] = $current;
            $depth = $visited[$current];

            if ($depth >= 3) {
                continue;
            }

            $neighbors = $adjacency[$current] ?? [];
            usort($neighbors, function (string $a, string $b) use ($nodeIndex): int {
                $typeA = (string) ($nodeIndex[$a]['type'] ?? '');
                $typeB = (string) ($nodeIndex[$b]['type'] ?? '');
                $pa = array_search($typeA, self::TYPE_PRIORITY, true);
                $pb = array_search($typeB, self::TYPE_PRIORITY, true);
                $pa = $pa === false ? 99 : $pa;
                $pb = $pb === false ? 99 : $pb;

                return $pa <=> $pb;
            });

            foreach ($neighbors as $neighbor) {
                if (! isset($visited[$neighbor])) {
                    $visited[$neighbor] = $depth + 1;
                    $queue[] = $neighbor;
                }
            }
        }

        $selectedNodes = [];
        foreach ($orderedIds as $id) {
            if (isset($nodeIndex[$id])) {
                $selectedNodes[] = $nodeIndex[$id];
            }
        }

        $selectedIds = array_flip($orderedIds);
        $selectedEdges = array_values(array_filter(
            $allEdges,
            fn ($e) => isset($selectedIds[(string) $e['source']]) && isset($selectedIds[(string) $e['target']])
        ));

        return [$selectedNodes, $selectedEdges];
    }

    // ── Full project summary (no focal) ───────────────────────────────────────

    /**
     * @param  array<string, array<string, mixed>>  $nodeIndex
     * @param  array<int, array<string, mixed>>  $allEdges
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function buildSummarySelection(array $nodeIndex, array $allEdges): array
    {
        $nodes = array_values($nodeIndex);

        usort($nodes, function (array $a, array $b): int {
            $ccA = (int) ($a['data']['metrics']['cyclomaticComplexity'] ?? 0);
            $ccB = (int) ($b['data']['metrics']['cyclomaticComplexity'] ?? 0);

            if ($ccB !== $ccA) {
                return $ccB <=> $ccA;
            }

            return strcmp((string) $a['id'], (string) $b['id']);
        });

        return [$nodes, $allEdges];
    }

    // ── Markdown serializer ───────────────────────────────────────────────────

    private function toMarkdown(AiContext $ctx): string
    {
        $charBudget = $ctx->tokenBudget * self::CHARS_PER_TOKEN;
        $parts = [];

        $parts[] = '# Laravel Brain AI Context';
        $parts[] = "> Project: {$ctx->project} | Analyzed: {$ctx->analyzedAt} | Focal: {$ctx->focalLabel} | Budget: {$ctx->tokenBudget} tokens";
        $parts[] = '';

        // Build node index for quick lookup
        $nodeIndex = [];
        foreach ($ctx->nodes as $node) {
            $nodeIndex[(string) $node['id']] = $node;
        }

        // Find focal node (first in BFS order)
        $focalNode = ! empty($ctx->nodes) ? $ctx->nodes[0] : null;

        // Route section
        if ($focalNode && ($focalNode['type'] ?? '') === 'route') {
            $parts[] = '## Route';
            $parts[] = '- Method: '.($focalNode['data']['method'] ?? '?');
            $parts[] = '- URI: '.($focalNode['data']['uri'] ?? '?');

            // Middleware from outgoing edges to middleware-type nodes
            $middlewareLabels = [];
            foreach ($ctx->edges as $edge) {
                if ((string) $edge['source'] === (string) $focalNode['id']) {
                    $target = $nodeIndex[(string) $edge['target']] ?? null;
                    if ($target && ($target['type'] ?? '') === 'middleware') {
                        $middlewareLabels[] = $target['label'];
                    }
                }
            }

            if (! empty($middlewareLabels)) {
                $parts[] = '- Middleware: '.implode(', ', $middlewareLabels);
            }

            $parts[] = '';
        }

        // Call chain section
        $chain = $this->buildCallChain($ctx->nodes, $ctx->edges);
        if (! empty($chain)) {
            $parts[] = '## Call Chain (depth ≤ 3)';
            $parts[] = implode(' → ', $chain);
            $parts[] = '';
        }

        // Complexity hotspots
        $hotspots = array_filter($ctx->nodes, fn ($n) => isset($n['data']['metrics']));
        if (! empty($hotspots)) {
            usort($hotspots, fn ($a, $b) => ($b['data']['metrics']['cyclomaticComplexity'] ?? 0) <=>
                ($a['data']['metrics']['cyclomaticComplexity'] ?? 0)
            );

            $parts[] = '## Complexity Hotspots';
            $parts[] = '| Label | Cyclomatic | Lines |';
            $parts[] = '|-------|-----------|-------|';
            foreach ($hotspots as $n) {
                $m = $n['data']['metrics'];
                $cc = $m['cyclomaticComplexity'] ?? '?';
                $lines = $m['lineCount'] ?? '?';
                $parts[] = "| {$n['label']} | {$cc} | {$lines} |";
            }
            $parts[] = '';
        }

        // DB Operations
        $allQueries = [];
        foreach ($ctx->nodes as $node) {
            foreach (($node['data']['dbQueries'] ?? []) as $q) {
                $table = $q['table'] ?? (isset($q['model']) ? basename(str_replace('\\', '/', $q['model'])) : '?');
                $allQueries[] = "- {$q['type']} {$q['operation']} {$table} (via {$node['label']})";
            }
        }
        if (! empty($allQueries)) {
            $parts[] = '## Database Operations';
            $parts[] = implode("\n", $allQueries);
            $parts[] = '';
        }

        // Cache Operations
        //
        // Worth the characters for the same reason the DB section is: an agent asked to change
        // what a method returns cannot see from the source excerpt alone that the value is
        // served from cache, or which other method clears the key it writes.
        $allCacheOps = [];
        foreach ($ctx->nodes as $node) {
            foreach (($node['data']['cacheOps'] ?? []) as $operation) {
                $allCacheOps[] = '- '.$this->describeCacheOperation($operation)." (in {$node['label']})";
            }
        }
        if (! empty($allCacheOps)) {
            $parts[] = '## Cache Operations';
            $parts[] = implode("\n", $allCacheOps);
            $parts[] = '';
        }

        // Outgoing HTTP. Sits beside the database section because it answers the same kind of
        // question — what does this code path touch that it does not own — and because an agent
        // reading this export to change a method needs to know the method leaves the process.
        $allHttp = [];
        foreach ($ctx->nodes as $node) {
            foreach (($node['data']['httpCalls'] ?? []) as $call) {
                $allHttp[] = '- '.$this->describeHttpCall($call)." (via {$node['label']})";
            }
        }
        if (! empty($allHttp)) {
            $parts[] = '## Outgoing HTTP';
            $parts[] = implode("\n", $allHttp);
            $parts[] = '';
        }

        // Pre-budget structural content (always included)
        $structural = implode("\n", $parts);
        $charBudget -= strlen($structural);

        // The dependency lists say what the project has installed, not what the focal node
        // does, and on a large application they are long enough to be the whole export: a
        // route asked for at 500 tokens came back at 1,264, of which 87% was these two
        // tables and none was code. They get a share of what is left and no more.
        $packageBudget = (int) ($charBudget * self::PACKAGE_BUDGET_SHARE);

        // Split, rather than first-come. Handing the whole share to the backend table let it
        // spend all of it — on an application with 120 dependencies of each kind the frontend
        // table was absent at every budget under 20,000, and an absent table is indistinguishable
        // from a project that has no `package.json` at all. Whatever the first table leaves
        // unspent rolls forward, so a project with only one of the two still gets the full share.
        $backendPackages = $this->readComposerPackages();
        $frontendPackages = $this->readFrontendPackages();
        $firstShare = $frontendPackages === [] ? $packageBudget : intdiv($packageBudget, 2);

        $packages = $this->packageSection(
            '## Backend Packages (composer.json)',
            $backendPackages,
            $firstShare,
        );
        $packages .= $this->packageSection(
            '## Frontend Packages (package.json)',
            $frontendPackages,
            $packageBudget - strlen($packages),
        );
        $structural .= $packages;
        $charBudget -= strlen($packages);

        // Source snippets (budget-gated, focal node first).
        //
        // Read from the file each node names. This used to read `data['source']`, which no
        // analyzer ever fills with code — the only writer put a command's provenance there —
        // so the whole budget-gated path emitted nothing on 8,454 of one project's 10,142
        // nodes and a ```php block containing the word "class" on the remaining 113.
        $sourceParts = [];
        $nodesForSource = $ctx->nodes;

        // Nodes name files far more often than files exist: a class and its methods, a resource
        // and its pages, all carry the same path. Emitting per node would put one file's whole
        // contents in the export once for every node that names it — and every copy is spent
        // out of the budget the rest of this method exists to respect.
        $emitted = [];

        foreach ($nodesForSource as $i => $node) {
            $file = (string) ($node['data']['file'] ?? '');
            if ($file !== '' && isset($emitted[$file])) {
                continue;
            }

            $source = $this->readSource($file);
            if ($source === '') {
                continue;
            }

            $emitted[$file] = true;

            $isFocal = $i === 0;
            $label = (string) $node['label'];
            $suffix = $isFocal ? ' (focal)' : '';

            $header = "## Source: {$label}{$suffix}\n```php\n";
            $footer = "\n```\n";
            $overhead = strlen($header) + strlen($footer);

            if ($charBudget <= $overhead + 20) {
                break;
            }

            // The marker is part of the snippet, so it has to be part of the arithmetic that
            // sizes it. Appending it afterwards put every truncated export exactly its own length
            // over the budget — 32 bytes, the em dash costing three of them — at every budget.
            $marker = "\n// [truncated — token budget]";
            $available = $charBudget - $overhead;
            $truncated = false;
            if (strlen($source) > $available) {
                $source = substr($source, 0, max(0, $available - strlen($marker)));
                $truncated = true;
            }

            $snippet = $header.$source;
            if ($truncated) {
                $snippet .= $marker;
            }
            $snippet .= $footer;

            $sourceParts[] = $snippet;
            $charBudget -= strlen($snippet);
        }

        return $structural.implode("\n", $sourceParts);
    }

    /**
     * One dependency table, as much of it as $charBudget allows.
     *
     * Truncated rather than dropped: knowing the project runs Filament at all is worth a few
     * lines, and the count says plainly what was left out instead of implying the list is whole.
     *
     * A table that does not fit at all still leaves its heading and its count behind. An empty
     * string would say the same thing as "this project has no `package.json`", and those are not
     * the same thing — telling them apart is the whole reason the truncation row exists.
     *
     * @param  list<array{name: string, version: string, dev: bool}>  $packages
     */
    private function packageSection(string $heading, array $packages, int $charBudget): string
    {
        if ($packages === []) {
            return '';
        }

        $rows = [$heading, '| Package | Version | Dev |', '|---------|---------|-----|'];
        $used = strlen(implode("\n", $rows));
        $shown = 0;

        foreach ($packages as ['name' => $name, 'version' => $version, 'dev' => $dev]) {
            $row = '| '.$name.' | '.$version.' | '.($dev ? 'yes' : '').' |';
            if ($used + strlen($row) + 1 > $charBudget) {
                break;
            }
            $rows[] = $row;
            $used += strlen($row) + 1;
            $shown++;
        }

        if ($shown === 0) {
            return "\n".$heading."\n| …all ".count($packages)." omitted — token budget | | |\n";
        }

        $omitted = count($packages) - $shown;
        if ($omitted > 0) {
            $rows[] = "| …and {$omitted} more | | |";
        }
        $rows[] = '';

        return "\n".implode("\n", $rows);
    }

    /**
     * The contents of a file a node names, or '' when there is nothing safe to read.
     *
     * Confined to the project being analysed: node paths come from the scan, but an export is
     * something a person hands to a model, and reading outside the tree is not a mistake worth
     * being able to make.
     */
    private function readSource(string $file): string
    {
        if ($file === '' || ! is_file($file) || ! is_readable($file)) {
            return '';
        }

        if ($this->projectPath !== '') {
            $root = realpath(rtrim($this->projectPath, '/'));
            $real = realpath($file);
            if ($root === false || $real === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                return '';
            }
        }

        return (string) file_get_contents($file);
    }

    // ── JSON serializer ───────────────────────────────────────────────────────

    private function toJson(AiContext $ctx): string
    {
        return (string) json_encode([
            'meta' => [
                'project' => $ctx->project,
                'analyzedAt' => $ctx->analyzedAt,
                'focal' => $ctx->focalLabel,
                'tokenBudget' => $ctx->tokenBudget,
            ],
            'packages' => [
                'backend' => $this->readComposerPackages(),
                'frontend' => $this->readFrontendPackages(),
            ],
            'nodes' => $ctx->nodes,
            'edges' => $ctx->edges,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // ── Package readers ───────────────────────────────────────────────────────

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function readComposerPackages(): array
    {
        if ($this->projectPath === '') {
            return [];
        }

        $file = rtrim($this->projectPath, '/').'/composer.json';
        if (! file_exists($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return [];
        }

        $packages = [];
        foreach ((array) ($data['require'] ?? []) as $name => $version) {
            if ($name === 'php') {
                continue;
            }
            $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => false];
        }
        foreach ((array) ($data['require-dev'] ?? []) as $name => $version) {
            $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => true];
        }

        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function readFrontendPackages(): array
    {
        if ($this->projectPath === '') {
            return [];
        }

        $root = rtrim($this->projectPath, '/');
        $candidates = [
            $root.'/package.json',
            $root.'/frontend/package.json',
        ];

        foreach ($candidates as $file) {
            if (file_exists($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                if (! is_array($data)) {
                    continue;
                }

                $packages = [];
                foreach ((array) ($data['dependencies'] ?? []) as $name => $version) {
                    $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => false];
                }
                foreach ((array) ($data['devDependencies'] ?? []) as $name => $version) {
                    $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => true];
                }

                if (! empty($packages)) {
                    usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

                    return $packages;
                }
            }
        }

        return [];
    }

    /**
     * One cache operation as a line of prose: `read remember "users:{$id}" ttl=600 store=redis`.
     *
     * A computed key is spelled out as such rather than omitted. "This method reads a key we
     * could not determine" is a different and more useful fact than silence, which reads as
     * "this method does not use the cache".
     *
     * @param  array<string, mixed>  $operation
     */
    private function describeCacheOperation(array $operation): string
    {
        $kind = (string) ($operation['kind'] ?? '?');
        $method = (string) ($operation['method'] ?? '?');
        $keyKind = (string) ($operation['keyKind'] ?? 'computed');
        $key = (string) ($operation['key'] ?? '');

        $line = $kind.' '.$method;
        $line .= match ($keyKind) {
            'none' => '',
            'computed' => ' (computed key)',
            default => ' "'.$key.'"',
        };

        if (! empty($operation['tags']) && is_array($operation['tags'])) {
            $line .= ' tags='.implode(',', array_map('strval', $operation['tags']));
        }
        if (! empty($operation['store'])) {
            $line .= ' store='.$operation['store'];
        }
        if (isset($operation['ttl'])) {
            $line .= ' ttl='.$operation['ttl'].'s';
        }

        return $line;
    }

    /**
     * One outgoing request as a line of prose: method, where it goes, and what it does when the
     * far end is slow. An unreadable address is written as such rather than omitted — "we cannot
     * see where this goes" is information, and a blank would read as "it goes nowhere".
     *
     * @param  array<string, mixed>  $call
     */
    private function describeHttpCall(array $call): string
    {
        $method = is_string($call['method'] ?? null) && $call['method'] !== '' ? $call['method'] : 'REQUEST';
        $url = is_string($call['url'] ?? null) ? $call['url'] : '';
        $configKey = is_string($call['configKey'] ?? null) ? $call['configKey'] : '';
        $source = is_string($call['urlSource'] ?? null) ? $call['urlSource'] : 'dynamic';

        $target = match (true) {
            $configKey !== '' => "config('{$configKey}'){$url}",
            $url !== '' => $url,
            default => 'URL computed at runtime',
        };

        $notes = [];
        if ($source === 'constructed') {
            $notes[] = 'URL partly computed';
        }
        $notes[] = isset($call['timeout']) && is_numeric($call['timeout'])
            ? 'timeout '.$call['timeout'].'s'
            : 'no timeout declared';
        if (isset($call['retryTimes']) && is_numeric($call['retryTimes'])) {
            $notes[] = 'retry '.$call['retryTimes'].'×';
        }
        if (($call['async'] ?? false) === true) {
            $notes[] = 'async';
        }

        $client = is_string($call['client'] ?? null) ? $call['client'] : 'http';

        return "{$method} {$target} [{$client}: ".implode(', ', $notes).']';
    }

    // ── Call chain builder ────────────────────────────────────────────────────

    /**
     * Builds a linear A → B → C call chain from BFS-ordered nodes and edges.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<string>
     */
    private function buildCallChain(array $nodes, array $edges): array
    {
        if (empty($nodes)) {
            return [];
        }

        // Build parent map: nodeId => first parent id in BFS order
        $parentOf = [];
        $nodeIds = array_column($nodes, 'id');
        $nodeIdSet = array_flip($nodeIds);

        foreach ($edges as $edge) {
            $src = (string) $edge['source'];
            $tgt = (string) $edge['target'];
            if (isset($nodeIdSet[$src], $nodeIdSet[$tgt]) && ! isset($parentOf[$tgt])) {
                $parentOf[$tgt] = $src;
            }
        }

        // Find the longest path starting from root (node with no parent in selected set)
        $roots = array_filter($nodeIds, fn ($id) => ! isset($parentOf[$id]));
        if (empty($roots)) {
            $roots = [$nodeIds[0]];
        }

        $root = reset($roots);

        // Build children map
        $childOf = [];
        foreach ($parentOf as $child => $parent) {
            $childOf[$parent][] = $child;
        }

        // DFS to find longest path
        $longestPath = $this->longestPath((string) $root, $childOf, []);

        $nodeIndex = [];
        foreach ($nodes as $node) {
            $nodeIndex[(string) $node['id']] = $node;
        }

        return array_map(
            fn ($id) => (string) ($nodeIndex[$id]['label'] ?? $id),
            $longestPath
        );
    }

    /**
     * @param  array<string, list<string>>  $childOf
     * @param  list<string>  $current
     * @return list<string>
     */
    private function longestPath(string $nodeId, array $childOf, array $current): array
    {
        $current[] = $nodeId;
        $children = $childOf[$nodeId] ?? [];

        if (empty($children)) {
            return $current;
        }

        $best = $current;
        foreach ($children as $child) {
            $path = $this->longestPath($child, $childOf, $current);
            if (count($path) > count($best)) {
                $best = $path;
            }
        }

        return $best;
    }
}
