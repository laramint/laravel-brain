<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

class ProjectStructure
{
    /** @var string[] */
    private const EXCLUDED_DIRS = [
        '.git',
        '.idea',
        '.vscode',
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
    ];

    /**
     * Discover Laravel-like application roots inside the given project tree.
     *
     * @return string[] absolute paths
     */
    public function discoverRoots(string $projectRoot): array
    {
        $projectRoot = $this->normalizePath($projectRoot);
        $roots = [$projectRoot => true];

        if (! is_dir($projectRoot)) {
            return array_keys($roots);
        }

        $directoryIterator = new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $entry) use ($projectRoot): bool {
                if (! $entry->isDir()) {
                    return false;
                }

                $dir = $this->normalizePath($entry->getPathname());
                $relative = ltrim(substr($dir, strlen($projectRoot)), '/');

                return ! $this->shouldExclude($relative);
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            if (! $entry->isDir()) {
                continue;
            }

            $dir = $this->normalizePath($entry->getPathname());
            if ($dir !== $projectRoot && $this->isLaravelLikeRoot($dir)) {
                $roots[$dir] = true;
            }
        }

        return array_keys($roots);
    }

    /**
     * Build a merged PSR-4 map across all discovered roots.
     *
     * @return array<string, string> namespace => absolute base path
     */
    public function buildPsr4Map(string $projectRoot): array
    {
        $map = [];

        foreach ($this->discoverRoots($projectRoot) as $root) {
            $composerJson = $root.'/composer.json';
            if (! file_exists($composerJson)) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($composerJson), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach (['autoload', 'autoload-dev'] as $section) {
                $psr4 = $decoded[$section]['psr-4'] ?? [];
                if (! is_array($psr4)) {
                    continue;
                }

                foreach ($psr4 as $namespace => $path) {
                    $ns = rtrim((string) $namespace, '\\');
                    if ($ns === '') {
                        continue;
                    }

                    $paths = is_array($path) ? $path : [$path];
                    foreach ($paths as $candidatePath) {
                        $basePath = $this->normalizePath($root.'/'.(string) $candidatePath);
                        if (! isset($map[$ns])) {
                            $map[$ns] = $basePath;
                        }
                        break;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @return string[] absolute directories used for fallback class lookup
     */
    public function discoverClassSearchBases(string $projectRoot): array
    {
        $bases = [];

        foreach ($this->discoverRoots($projectRoot) as $root) {
            foreach (['app', 'src'] as $dir) {
                $path = $this->normalizePath($root.'/'.$dir);
                if (is_dir($path)) {
                    $bases[$path] = true;
                }
            }
        }

        return array_keys($bases);
    }

    /**
     * Discover all route directories (e.g. routes/, Routes/, src/Routes/) in the project tree.
     *
     * @return string[] absolute paths
     */
    public function discoverRouteDirectories(string $projectRoot): array
    {
        $projectRoot = $this->normalizePath($projectRoot);
        $routeDirs = [];

        $directoryIterator = new \RecursiveDirectoryIterator($projectRoot, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directoryIterator,
            function (\SplFileInfo $entry) use ($projectRoot): bool {
                if (! $entry->isDir()) {
                    return false;
                }

                $dir = $this->normalizePath($entry->getPathname());
                $relative = ltrim(substr($dir, strlen($projectRoot)), '/');

                return ! $this->shouldExclude($relative);
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $entry) {
            if (! $entry->isDir()) {
                continue;
            }

            if (strtolower($entry->getFilename()) !== 'routes') {
                continue;
            }

            $path = $this->normalizePath($entry->getPathname());
            if (preg_match('#/views/routes$#i', $path)) {
                continue;
            }
            if ($this->containsPhpFiles($path)) {
                $routeDirs[$path] = true;
            }
        }

        return array_keys($routeDirs);
    }

    private function isLaravelLikeRoot(string $dir): bool
    {
        $hasClassDirs = is_dir($dir.'/app') || is_dir($dir.'/src');
        $hasLaravelRuntime = file_exists($dir.'/artisan') || file_exists($dir.'/bootstrap/app.php');
        $hasComposer = file_exists($dir.'/composer.json');

        if (is_dir($dir.'/routes') && ($hasClassDirs || $hasLaravelRuntime || $hasComposer)) {
            return true;
        }

        return $hasClassDirs && ($hasComposer || $hasLaravelRuntime);
    }

    private function shouldExclude(string $relative): bool
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '') {
            return false;
        }

        foreach (self::EXCLUDED_DIRS as $excluded) {
            $excluded = str_replace('\\', '/', $excluded);
            if ($relative === $excluded || str_starts_with($relative, $excluded.'/')) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(string $path): string
    {
        $resolved = realpath($path);
        $path = $resolved !== false ? $resolved : $path;
        $path = str_replace('\\', '/', $path);

        return rtrim($path, '/');
    }

    private function containsPhpFiles(string $dir): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && strtolower($entry->getExtension()) === 'php') {
                return true;
            }
        }

        return false;
    }
}
