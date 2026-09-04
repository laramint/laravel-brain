<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Commands;

use Illuminate\Console\Command;
use LaraMint\LaravelBrain\Analysis\Incremental\IncrementalAnalyzer;
use LaraMint\LaravelBrain\Analysis\Incremental\ScopedRebuildNotApplicable;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Storage\GraphStoreFactory;

class ScanCommand extends Command
{
    protected $signature = 'brain:scan
                            {--watch : Watch for PHP file changes and auto-rescan}
                            {--interval=3 : Poll interval in seconds (watch mode only)}
                            {--memory-limit= : Memory limit for scanning, e.g. 1024M, 2G or -1. Defaults to config laravel-brain.memory_limit}
                            {--auto-discover : Force auto-discover routes mode for this scan (overrides config)}';

    protected $description = 'Analyze this Laravel project and open the interactive graph viewer';

    /** The limit used when neither the option nor the config names one. */
    public const DEFAULT_MEMORY_LIMIT = '1024M';

    /** @var array<string, float> step start times */
    private array $stepTimers = [];

    public function handle(): int
    {
        $memoryLimit = $this->normalizeMemoryLimit(
            $this->option('memory-limit') ?? config('laravel-brain.memory_limit', self::DEFAULT_MEMORY_LIMIT),
        );

        if ($memoryLimit === self::FAILURE) {
            return $memoryLimit;
        }

        ini_set('memory_limit', $memoryLimit);
        $this->reportMemoryExhaustionOnShutdown((string) $memoryLimit);

        $projectPath = base_path();

        if ($this->option('watch')) {
            return $this->watch($projectPath);
        }

        return $this->runScan($projectPath, verbose: true);
    }

    /**
     * Say so when the scan dies of memory exhaustion.
     *
     * PHP exits 255 on that fatal and, with `display_errors` off, prints nothing — the
     * progress line simply stops. Registering the check at shutdown is the only place the
     * fatal is still readable, and the reserve is released first so there is heap left to
     * write the line with.
     */
    private function reportMemoryExhaustionOnShutdown(string $limitInEffect): void
    {
        $reserve = str_repeat(' ', MemoryExhaustionNotice::RESERVE_BYTES);

        register_shutdown_function(function () use (&$reserve, $limitInEffect): void {
            // Released first: with the heap full there is otherwise nothing to build a
            // message with, and PHP's own report cannot render either.
            $reserve = null;

            $notice = MemoryExhaustionNotice::forLastError(error_get_last(), $limitInEffect);
            if ($notice !== null) {
                $this->newLine();
                $this->error($notice);
            }
        });
    }

    // ── Watch mode ────────────────────────────────────────────────────────────

    private function watch(string $projectPath): int
    {
        $interval = max(1, (int) $this->option('interval'));

        $this->newLine();
        $this->renderHeader();
        $this->line("  <fg=gray>Watch mode — polling every {$interval}s  ·  Ctrl+C to stop</>");
        $this->newLine();

        $this->runScan($projectPath, verbose: true);
        $mtimes = $this->collectMtimes($projectPath);

        while (true) { // @phpstan-ignore while.alwaysTrue
            sleep($interval);

            $current = $this->collectMtimes($projectPath);
            $changed = $this->detectChanges($mtimes, $current);

            if (! empty($changed)) {
                $this->newLine();
                $this->line('  <fg=yellow>⚡ Changed:</> '.$this->summariseChanged($changed));

                $scope = $this->scopeForRescan($projectPath, $mtimes, $current);
                $started = microtime(true);
                try {
                    $this->runScan($projectPath, verbose: false, scopeToFiles: $scope);
                    $mode = $scope === null ? 'full' : 'scoped';
                } catch (ScopedRebuildNotApplicable) {
                    // The edit moved a call, so the previous graph's edges no longer hold.
                    $this->runScan($projectPath, verbose: false);
                    $mode = 'full (the change moved a call)';
                }
                $this->line(sprintf('  <fg=gray>%s rescan in %dms</>', $mode, (int) ((microtime(true) - $started) * 1000)));

                $mtimes = $current;
            }
        }

        return self::SUCCESS; // @phpstan-ignore-line
    }

    /**
     * Directories polled for changes. Everything that can change the graph belongs here —
     * application code, route files, configuration.
     *
     * @return string[]
     */
    private function watchPaths(): array
    {
        $paths = config('laravel-brain.watch_paths', IncrementalAnalyzer::DEFAULT_ROOTS);

        return is_array($paths) && $paths !== [] ? $paths : IncrementalAnalyzer::DEFAULT_ROOTS;
    }

    /**
     * The subset of the watched tree a scoped rescan may be limited to: application code.
     * A change in routes or configuration can rewrite the graph from the top.
     *
     * @return string[]
     */
    private function sourcePaths(): array
    {
        $paths = config('laravel-brain.source_paths', SourceDirectories::DEFAULT_SOURCE_PATHS);

        return is_array($paths) && $paths !== [] ? $paths : SourceDirectories::DEFAULT_SOURCE_PATHS;
    }

    private function collectMtimes(string $projectPath): array
    {
        // A poll is exactly when a directory may have appeared, so the resolved-directory
        // memo cannot be carried across one.
        SourceDirectories::clear();

        $mtimes = [];

        foreach (SourceDirectories::resolve($projectPath, $this->watchPaths()) as $dir) {
            $base = $projectPath.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $mtimes[$file->getPathname()] = $file->getMTime();
            }
        }

        return $mtimes;
    }

    /**
     * The files a scoped rescan may be limited to, or null when only a full one will do.
     *
     * A scoped rescan reuses the previous graph's edges wholesale, so it holds only while the
     * shape of the project is unchanged: a file appearing or disappearing can move the route
     * table or reachability, and anything outside the source paths — routes, config — can
     * rewrite the graph from the top. Those cases are not worth reasoning about incrementally.
     *
     * @param  array<string, int>  $old
     * @param  array<string, int>  $new
     * @return string[]|null
     */
    private function scopeForRescan(string $projectPath, array $old, array $new): ?array
    {
        if (count($old) !== count($new) || array_diff_key($old, $new) !== [] || array_diff_key($new, $old) !== []) {
            return null;
        }

        $modified = [];
        foreach ($new as $path => $mtime) {
            if ($old[$path] === $mtime) {
                continue;
            }
            // Anchored at the project root: a substring test calls every file "in app/" when
            // the project itself lives under a directory of that name, which would let a routes
            // change take the scoped path and leave watch showing the previous graph.
            if (! SourceDirectories::contains($projectPath, SourceDirectories::resolve($projectPath, $this->sourcePaths()), $path)) {
                return null;
            }
            $modified[] = $path;
        }

        return $modified === [] ? null : $modified;
    }

    private function detectChanges(array $old, array $new): array
    {
        $changed = [];

        foreach ($new as $path => $mtime) {
            if (! isset($old[$path]) || $old[$path] !== $mtime) {
                $changed[] = $path;
            }
        }
        foreach (array_keys($old) as $path) {
            if (! isset($new[$path])) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    private function summariseChanged(array $changed): string
    {
        $names = array_map('basename', array_slice($changed, 0, 3));
        $label = implode(', ', $names);
        if (count($changed) > 3) {
            $label .= ' +'.(count($changed) - 3).' more';
        }

        return $label;
    }

    // ── Memory Option ─────────────────────────────────────────────────────────

    private function normalizeMemoryLimit($option): string|int
    {
        $memory = strtoupper(trim((string) $option));

        if ($memory === '-1') {
            return -1;
        }

        if (! preg_match('/^(\d+)([KMGT]?)$/', $memory, $matches)) {
            $this->error('Invalid memory limit format. Example: 1024M, 1G, 2G or -1.');

            return self::FAILURE;
        }

        $value = (int) $matches[1];
        $unit = $matches[2] ?: 'M';

        if ($value <= 0) {
            $this->error('Invalid memory limit. The value must be a positive number or -1.');

            return self::FAILURE;
        }

        if ($this->convertToBytes($value, $unit) < (1024 ** 3)) {
            $this->error("The memory limit must be at least 1024M (Current: {$value}{$unit}).");

            return self::FAILURE;
        }

        return "{$value}{$unit}";
    }

    private function convertToBytes(int $value, string $unit): int
    {
        return match ($unit) {
            'K' => $value * (1024 ** 1),
            'M' => $value * (1024 ** 2),
            'G' => $value * (1024 ** 3),
            'T' => $value * (1024 ** 4),
            default => $value,
        };
    }

    // ── Shared scan logic ─────────────────────────────────────────────────────

    /** Graph from the last completed scan, reused by a scoped rescan in watch mode. */
    private ?Graph $lastGraph = null;

    /**
     * @param  string[]|null  $scopeToFiles  trace only these files' controllers and merge into
     *                                       the previous graph; null runs a full analysis
     */
    private function runScan(string $projectPath, bool $verbose, ?array $scopeToFiles = null): int
    {
        $totalStart = microtime(true);

        if ($verbose) {
            $this->newLine();
            $this->renderHeader();
            $this->line('  <fg=gray>Path: '.$projectPath.'</>');
            $this->newLine();
        }

        if ($this->option('auto-discover')) {
            config(['laravel-brain.auto_discover_routes' => true]);
        }

        $analyzer = new ProjectAnalyzer;
        if ($scopeToFiles !== null && $this->lastGraph !== null) {
            $analyzer->scopedTo($scopeToFiles, $this->lastGraph);
        }

        $result = $analyzer->analyze($projectPath, function (string $event, array $data) use ($verbose): void {
            $this->handleProgress($event, $data, $verbose);
        });

        $this->lastGraph = $result->fullGraph;

        $store = GraphStoreFactory::make();
        $store->ensureSchema();
        $store->putManifest($result->manifestJson);

        foreach ($result->subgraphs as $tabId => $subgraph) {
            $store->putSubgraph((string) $tabId, $subgraph->toJson());
        }

        // Both a full and a scoped rescan split the whole graph, so what was just written
        // is the complete tab set; anything else in the store outlived its route.
        // Ordering matters: the manifest above is what the viewer indexes tabs from, and it
        // is written unconditionally, so pruning to the same set that produced it can only
        // remove blobs nothing can address. Moving either out of this block breaks that.
        $store->pruneSubgraphsExcept(array_map('strval', array_keys($result->subgraphs)));

        // The one-time support prompt flag lives on disk regardless of driver.
        $storageDir = storage_path('app/laravel-brain');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        if ($verbose) {
            $elapsed = microtime(true) - $totalStart;
            $this->newLine();
            $this->renderSummary($result->fullGraph->nodeCount(), $result->fullGraph->edgeCount(), $result->totalRoutes, $result->totalCommands, $result->totalChannels, $result->totalFilamentResources, count($result->unresolvedDispatchers), $elapsed, $result->isolatedNodes, $result->nodesOutsideTabs);
            $url = rtrim(config('app.url', 'http://localhost'), '/').'/_laravel-brain';
            $this->newLine();
            $this->line("  Open the viewer: <fg=cyan;options=bold>{$url}</>");
            $this->newLine();
            $this->promptSupport($storageDir);
        } else {
            $elapsed = microtime(true) - $totalStart;
            $this->line(
                '  <fg=green>✓</> Graph refreshed at <fg=cyan>'.date('H:i:s').'</>  '.
                '<fg=gray>'.$result->fullGraph->nodeCount().' nodes · '.$result->fullGraph->edgeCount().' edges · '.
                number_format($elapsed, 1).'s</>'
            );
        }

        return self::SUCCESS;
    }

    // ── Progress handler ──────────────────────────────────────────────────────

    private function handleProgress(string $event, array $data, bool $verbose): void
    {
        if (! $verbose) {
            return;
        }

        match ($event) {
            'step:start' => $this->renderStepStart($data),
            'step:done' => $this->renderStepDone($data),
            default => null,
        };
    }

    private function renderStepStart(array $data): void
    {
        $step = $data['step'];
        $label = $data['label'] ?? $step;

        $this->stepTimers[$step] = microtime(true);

        $this->getOutput()->write(
            sprintf('  <fg=gray>○</> %-38s', $label.'...')
        );
    }

    private function renderStepDone(array $data): void
    {
        $step = $data['step'];
        $count = $data['count'] ?? null;
        $unit = $data['unit'] ?? null;
        $extra = $data['extra'] ?? null;

        $elapsed = isset($this->stepTimers[$step])
            ? microtime(true) - $this->stepTimers[$step]
            : 0.0;

        $countStr = '';
        if ($count !== null && $unit !== null) {
            $suffix = $count === 1 ? $unit : $unit.'s';
            $countStr = "<fg=yellow>{$count} {$suffix}</>";
        }
        if ($extra !== null) {
            $countStr .= ($countStr ? ', ' : '')."<fg=gray>{$extra}</>";
        }

        $timeStr = '<fg=gray>('.number_format($elapsed, 2).'s)</>';

        $this->getOutput()->write(
            "\r  <fg=green>✓</> ".sprintf('%-38s', ($data['label'] ?? $step).'...')
            ."  {$countStr}  {$timeStr}\n"
        );
    }

    // ── UI helpers ────────────────────────────────────────────────────────────

    private function renderHeader(): void
    {
        $this->line('  <fg=magenta;options=bold>┌─────────────────────────────────────────┐</>');
        $this->line('  <fg=magenta;options=bold>│</>  <fg=white;options=bold>Laravel Brain</>  <fg=gray>— project analysis</>       <fg=magenta;options=bold>│</>');
        $this->line('  <fg=magenta;options=bold>└─────────────────────────────────────────┘</>');
    }

    // ── Support prompt (shown once) ───────────────────────────────────────────

    private function promptSupport(string $storageDir): void
    {
        $flagFile = $storageDir.'/.support-asked';

        if (file_exists($flagFile)) {
            return;
        }

        $this->line('  <fg=gray>─────────────────────────────────────────</>');
        $this->newLine();
        $this->line('  <fg=yellow;options=bold>💛 Enjoying Laravel Brain?</>');
        $this->newLine();
        $this->line('  Laravel Brain is free and open-source.');
        $this->line('  If it saves you time, consider supporting the project:');
        $this->newLine();

        if ($this->confirm('  Would you like to open the GitHub page to star / sponsor the project?', false)) {
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>https://github.com/laramint/laravel-brain</>');
            $this->newLine();
            $this->line('  <fg=gray>Thank you — it means a lot! 🙏</>');
        } else {
            $this->line('  <fg=gray>No problem! You can always find us at</>');
            $this->line('  <fg=gray>https://github.com/laramint/laravel-brain</>');
        }

        $this->newLine();
        $this->line('  <fg=gray>─────────────────────────────────────────</>');
        $this->newLine();

        // Write the flag so we never ask again
        file_put_contents($flagFile, date('Y-m-d H:i:s'));
    }

    /**
     * @param  array<string, int>  $isolatedNodes  node type => count, no edge at all
     * @param  array<string, int>  $nodesOutsideTabs  node type => count, in the graph but in no tab
     */
    private function renderSummary(int $nodes, int $edges, int $routes, int $commands, int $channels, int $filamentResources, int $unresolvedDispatchers, float $elapsed, array $isolatedNodes = [], array $nodesOutsideTabs = []): void
    {
        $this->line('  <fg=gray>─────────────────────────────────────────</>');
        $this->line('  <options=bold>Summary</>');
        $this->newLine();

        $rows = [
            ['Nodes',      "<fg=cyan>{$nodes}</>"],
            ['Edges',      "<fg=cyan>{$edges}</>"],
            ['Routes',     "<fg=cyan>{$routes}</>"],
            ['Commands',   "<fg=cyan>{$commands}</>"],
            ['Channels',   "<fg=cyan>{$channels}</>"],
        ];

        if ($filamentResources > 0) {
            $rows[] = ['Filament Res.', "<fg=cyan>{$filamentResources}</>"];
        }

        if ($unresolvedDispatchers > 0) {
            $rows[] = ['Unresolved disp.', "<fg=yellow>{$unresolvedDispatchers}</>"];
        }

        // Two different ways for work to end up unseen, reported apart because they have
        // different causes. "Isolated" is a node no edge touches, which is almost always a pass
        // that built nodes and forgot to wire them. "Outside tabs" is the stricter question — a
        // node can be perfectly wired and still sit in a cluster no tab seed reaches, and that is
        // just as invisible to whoever opens the viewer.
        if ($isolatedNodes !== []) {
            $rows[] = ['Isolated nodes', '<fg=yellow>'.array_sum($isolatedNodes).'</> <fg=gray>'.$this->topTypes($isolatedNodes).'</>'];
        }

        if ($nodesOutsideTabs !== []) {
            $rows[] = ['Outside tabs', '<fg=yellow>'.array_sum($nodesOutsideTabs).'</> <fg=gray>'.$this->topTypes($nodesOutsideTabs).'</>'];
        }

        $rows[] = ['Total time', '<fg=yellow>'.number_format($elapsed, 2).'s</>'];

        foreach ($rows as [$label, $value]) {
            $this->line(sprintf('    <fg=gray>%-14s</> %s', $label, $value));
        }

        $this->line('  <fg=gray>─────────────────────────────────────────</>');
    }

    /**
     * The three biggest contributors to a per-type count, so the number points somewhere.
     *
     * @param  array<string, int>  $counts  already ordered highest first
     */
    private function topTypes(array $counts): string
    {
        $parts = [];

        foreach (array_slice($counts, 0, 3, true) as $type => $count) {
            $parts[] = "{$type} {$count}";
        }

        return $parts === [] ? '' : '('.implode(', ', $parts).')';
    }
}
