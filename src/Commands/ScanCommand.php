<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Commands;

use Illuminate\Console\Command;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\ProjectStructure;

class ScanCommand extends Command
{
    protected $signature = 'brain:scan {--watch : Re-run scan when PHP files change} {--interval=3 : Poll interval (seconds) in watch mode}';

    protected $description = 'Analyze the Laravel project and build graph JSON files for the Laravel Brain viewer';

    private ProjectStructure $projectStructure;

    private bool $unicodeOutput = true;

    public function __construct()
    {
        parent::__construct();
        $this->projectStructure = new ProjectStructure;
    }

    public function handle(): int
    {
        $projectRoot = (string) base_path();
        $interval = max(1, (int) $this->option('interval'));

        ini_set('memory_limit', '1024M');
        set_time_limit(0);
        $this->configureOutputEncoding();

        $this->renderHeader($projectRoot);

        if ((bool) $this->option('watch')) {
            return $this->runWatchMode($projectRoot, $interval);
        }

        return $this->runScan($projectRoot);
    }

    private function runWatchMode(string $projectRoot, int $interval): int
    {
        $this->line('  <fg=gray>Watch mode: ON</>');
        $this->line("  <fg=gray>Poll interval: {$interval}s</>");
        $this->newLine();

        $code = $this->runScan($projectRoot);
        if ($code !== self::SUCCESS) {
            return $code;
        }

        $this->line('  <fg=gray>Waiting for PHP file changes... Press Ctrl+C to stop.</>');

        $lastSnapshot = $this->buildPhpSnapshot($projectRoot);

        while (true) {
            sleep($interval);
            clearstatcache();

            $snapshot = $this->buildPhpSnapshot($projectRoot);
            if ($snapshot === $lastSnapshot) {
                continue;
            }

            $this->newLine();
            $this->line('  <fg=yellow>Change detected. Re-scanning...</>');

            $code = $this->runScan($projectRoot);
            if ($code !== self::SUCCESS) {
                return $code;
            }

            $lastSnapshot = $snapshot;
            $this->line('  <fg=gray>Waiting for PHP file changes... Press Ctrl+C to stop.</>');
        }
    }

    private function runScan(string $projectRoot): int
    {
        $startedAt = microtime(true);
        $stepStartedAt = [];

        $analyzer = new ProjectAnalyzer;

        try {
            $result = $analyzer->analyze(
                $projectRoot,
                function (string $event, array $data) use (&$stepStartedAt): void {
                    if ($event === 'step:start') {
                        $step = (string) ($data['step'] ?? 'step');
                        $stepStartedAt[$step] = microtime(true);

                        return;
                    }

                    if ($event === 'step:done') {
                        $this->renderStepDone($data, $stepStartedAt);
                    }
                }
            );
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('  Scan failed.');
            $this->line('  '.get_class($e).': '.$e->getMessage());
            if ($e->getFile() !== '') {
                $this->line('  at '.$e->getFile().':'.$e->getLine());
            }

            return self::FAILURE;
        }

        $storageDir = storage_path('app/laravel-brain');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        // Remove stale graph files from previous scans.
        foreach (glob($storageDir.'/.graph-*.json') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        file_put_contents($storageDir.'/.graph-manifest.json', $result->manifestJson);
        file_put_contents($storageDir.'/.graph-all.json', $result->fullGraph->toJson());
        foreach ($result->subgraphs as $tabId => $subgraph) {
            file_put_contents($storageDir."/.graph-{$tabId}.json", $subgraph->toJson());
        }

        $totalTime = number_format(microtime(true) - $startedAt, 2);

        $this->newLine();
        $this->line('  <fg=gray>'.$this->hLine(41).'</>');
        $this->line('  <options=bold>Summary</>');
        $this->newLine();
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=cyan>%s</>', 'Nodes', $result->fullGraph->nodeCount()));
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=cyan>%s</>', 'Edges', $result->fullGraph->edgeCount()));
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=cyan>%s</>', 'Routes', $result->totalRoutes));
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=cyan>%s</>', 'Commands', $result->totalCommands));
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=cyan>%s</>', 'Channels', $result->totalChannels));
        $this->line(sprintf('    <fg=gray>%-14s</> <fg=yellow>%ss</>', 'Total time', $totalTime));
        $this->line('  <fg=gray>'.$this->hLine(41).'</>');
        $this->newLine();

        $appUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/');
        $this->line('  Open the viewer: <fg=cyan;options=bold>'.$appUrl.'/_laravel-brain</>');

        return self::SUCCESS;
    }

    private function renderHeader(string $projectRoot): void
    {
        $this->line('');
        $this->line('  <fg=magenta;options=bold>'.$this->frameLine('top').'</>');
        $this->line(
            '  <fg=magenta;options=bold>'.$this->frameLine('left').'</>'.
            '  <fg=white;options=bold>Laravel Brain</>  <fg=gray>— project analysis</>       '.
            '<fg=magenta;options=bold>'.$this->frameLine('right').'</>'
        );
        $this->line('  <fg=magenta;options=bold>'.$this->frameLine('bottom').'</>');
        $this->line('  Path: <fg=gray>'.$projectRoot.'</>');
        $this->line('');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, float> $stepStartedAt
     */
    private function renderStepDone(array $data, array $stepStartedAt): void
    {
        $step = (string) ($data['step'] ?? 'step');
        $count = $data['count'] ?? null;
        $unit = (string) ($data['unit'] ?? '');
        $extra = (string) ($data['extra'] ?? '');

        $elapsed = isset($stepStartedAt[$step]) ? (microtime(true) - $stepStartedAt[$step]) : 0.0;

        $left = sprintf('<fg=green>%s</> %s...', $this->checkMark(), $step);

        $details = null;
        if (is_int($count)) {
            $details = '<fg=yellow>'.(string) $count;
            if ($unit !== '') {
                $details .= ' '.$unit.($count === 1 ? '' : 's');
            }
            $details .= '</>';
        }
        if ($extra !== '') {
            $details = trim(($details ? $details.', ' : '').'<fg=gray>'.$extra.'</>');
        }

        $time = '<fg=gray>('.number_format($elapsed, 2).'s)</>';

        if ($details !== null && $details !== '') {
            $line = sprintf('  %-34s %s  %s', $left, $details, $time);
        } else {
            $line = sprintf('  %-34s %s', $left, $time);
        }

        $this->line($line);
    }

    /**
     * Build a lightweight snapshot of PHP file mtimes under discovered project roots.
     *
     * @return array<string, int>
     */
    private function buildPhpSnapshot(string $projectRoot): array
    {
        $snapshot = [];
        $roots = $this->projectStructure->discoverRoots($projectRoot);

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveCallbackFilterIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    function (\SplFileInfo $entry): bool {
                        $name = $entry->getFilename();

                        if ($entry->isDir()) {
                            return ! in_array($name, ['vendor', 'node_modules', '.git', '.idea', '.vscode', 'storage'], true);
                        }

                        return strtolower($entry->getExtension()) === 'php';
                    }
                )
            );

            foreach ($iterator as $file) {
                if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                    continue;
                }

                $path = $file->getPathname();
                $mtime = $file->getMTime();
                $snapshot[$path] = $mtime;
            }
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function configureOutputEncoding(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        if (function_exists('sapi_windows_cp_set')) {
            @sapi_windows_cp_set(65001);
        } else {
            $this->unicodeOutput = false;
        }
    }

    private function checkMark(): string
    {
        return $this->unicodeOutput ? '✓' : '[OK]';
    }

    private function hLine(int $length): string
    {
        $char = $this->unicodeOutput ? '─' : '-';

        return str_repeat($char, $length);
    }

    private function frameLine(string $part): string
    {
        if (! $this->unicodeOutput) {
            return match ($part) {
                'top', 'bottom' => '+-----------------------------------------+',
                'left', 'right' => '|',
                default => '|',
            };
        }

        return match ($part) {
            'top' => '┌─────────────────────────────────────────┐',
            'bottom' => '└─────────────────────────────────────────┘',
            'left' => '│',
            'right' => '│',
            default => '│',
        };
    }
}
