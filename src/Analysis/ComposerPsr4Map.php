<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * The namespace => source-root map every FQCN is resolved through.
 *
 * Reading only the root composer.json is enough for an application that keeps its
 * code in `app/`. It is not enough for the two layouts that put source in packages:
 * a modular monolith wiring local packages through a `path` repository, and
 * nwidart/laravel-modules. In both, the root composer.json declares `App\ => app/`
 * and nothing else, so every class in a module is unresolvable — analyzers still
 * discover the classes, then drop them because no file can be found for them.
 *
 * Only LOCAL packages are added. Regular vendor dependencies stay out on purpose:
 * making their classes resolvable would let the call-chain tracer walk into
 * framework and library internals, which is not what the graph is about.
 */
class ComposerPsr4Map
{
    /**
     * @return array<string, string[]> namespace prefix (no trailing separator) => base paths
     */
    public static function build(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/');
        $map = [];

        self::mergeComposerFile($map, $root.'/composer.json', $root);
        self::mergeInstalledPathPackages($map, $root);
        self::mergePathRepositories($map, $root);
        self::mergeNwidartModules($map, $root);

        foreach ($map as $namespace => $paths) {
            $map[$namespace] = array_values(array_unique($paths));
        }

        return $map;
    }

    /**
     * Packages Composer installed from a `path` repository. This is the authoritative
     * source: it lists what is actually wired up, including packages whose directory
     * name does not match their namespace.
     *
     * @param  array<string, string[]>  $map
     */
    private static function mergeInstalledPathPackages(array &$map, string $root): void
    {
        $installed = $root.'/vendor/composer/installed.json';
        if (! file_exists($installed)) {
            return;
        }

        $data = json_decode((string) file_get_contents($installed), true);
        if (! is_array($data)) {
            return;
        }

        /** @var array<int, array<string, mixed>> $packages */
        $packages = $data['packages'] ?? $data;

        foreach ($packages as $package) {
            if (! is_array($package) || ($package['dist']['type'] ?? null) !== 'path') {
                continue;
            }

            $packageRoot = self::localPackageRoot($package, $root);
            if ($packageRoot === null) {
                continue;
            }

            self::mergeAutoloadSections($map, $package, $packageRoot);
        }
    }

    /**
     * A `path` package is symlinked into vendor/, so its install-path leads back into
     * vendor/. The dist url is the real location; resolving the symlink covers the
     * copy install (`--no-symlink`), where the url may be a glob that no longer applies.
     *
     * @param  array<string, mixed>  $package
     */
    private static function localPackageRoot(array $package, string $root): ?string
    {
        $url = $package['dist']['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $candidate = str_starts_with($url, '/') ? $url : $root.'/'.$url;
            if (is_dir($candidate)) {
                return rtrim($candidate, '/');
            }
        }

        $installPath = $package['install-path'] ?? null;
        if (is_string($installPath) && $installPath !== '') {
            $resolved = realpath($root.'/vendor/composer/'.$installPath);
            if ($resolved !== false) {
                return rtrim($resolved, '/');
            }
        }

        return null;
    }

    /**
     * `path` repositories declared in the root composer.json, read straight from each
     * package's own composer.json. Covers a checkout where `composer install` has not
     * run yet, and a package that is declared but not required.
     *
     * @param  array<string, string[]>  $map
     */
    private static function mergePathRepositories(array &$map, string $root): void
    {
        $composerJson = $root.'/composer.json';
        if (! file_exists($composerJson)) {
            return;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (! is_array($data)) {
            return;
        }

        foreach ($data['repositories'] ?? [] as $repository) {
            if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                continue;
            }

            $url = $repository['url'] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            $absolute = str_starts_with($url, '/') ? $url : $root.'/'.$url;
            $directories = is_dir($absolute) ? [$absolute] : SourceDirectories::globDirectories($absolute);

            foreach ($directories as $directory) {
                self::mergeComposerFile($map, rtrim($directory, '/').'/composer.json', rtrim($directory, '/'));
            }
        }
    }

    /**
     * nwidart/laravel-modules: each Modules/{Name}/composer.json may declare its own
     * PSR-4 map, and older module structures rely on the conventional mapping.
     *
     * @param  array<string, string[]>  $map
     */
    private static function mergeNwidartModules(array &$map, string $root): void
    {
        $modulesDir = $root.'/Modules';
        if (! is_dir($modulesDir)) {
            return;
        }

        foreach (scandir($modulesDir) ?: [] as $moduleName) {
            if ($moduleName === '.' || $moduleName === '..') {
                continue;
            }

            $modulePath = $modulesDir.'/'.$moduleName;
            if (! is_dir($modulePath)) {
                continue;
            }

            self::mergeComposerFile($map, $modulePath.'/composer.json', $modulePath);

            // Conventional fallback: Modules\{Name} => Modules/{Name}/, covering both the
            // old structure (Http/ directly) and the new one (app/).
            $namespace = 'Modules\\'.$moduleName;
            if (! isset($map[$namespace])) {
                $map[$namespace] = [is_dir($modulePath.'/app') ? $modulePath.'/app' : $modulePath];
            }
        }
    }

    /**
     * @param  array<string, string[]>  $map
     */
    private static function mergeComposerFile(array &$map, string $composerJson, string $packageRoot): void
    {
        if (! file_exists($composerJson)) {
            return;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (! is_array($data)) {
            return;
        }

        self::mergeAutoloadSections($map, $data, $packageRoot);
    }

    /**
     * @param  array<string, string[]>  $map
     * @param  array<string, mixed>  $data
     */
    private static function mergeAutoloadSections(array &$map, array $data, string $packageRoot): void
    {
        foreach (['autoload', 'autoload-dev'] as $section) {
            $psr4 = $data[$section]['psr-4'] ?? [];
            if (! is_array($psr4)) {
                continue;
            }

            foreach ($psr4 as $namespace => $paths) {
                $key = rtrim((string) $namespace, '\\');
                foreach ((array) $paths as $path) {
                    $map[$key][] = rtrim($packageRoot.'/'.ltrim((string) $path, '/'), '/');
                }
            }
        }
    }
}
