<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

/**
 * Expands configured source paths into the directories that actually exist.
 *
 * An entry is taken verbatim when it names a directory and treated as a glob
 * pattern otherwise, which is what lets an application whose code lives in
 * packages rather than in `app/` point an analyzer at its own layout —
 * `app-modules/*` + `/src`, `packages/*` + `/src/Providers`, and so on.
 *
 * Paths stay relative to the project root on the way out: that is the form
 * {@see ProjectFileIndex} takes, and prefixing the root is a concatenation.
 */
final class SourceDirectories
{
    /**
     * Where application classes live in a default Laravel skeleton. `src/` is here too
     * because a package checked out as the project itself keeps its classes there.
     *
     * @var string[]
     */
    public const DEFAULT_SOURCE_PATHS = ['app', 'src'];

    /**
     * Resolved directories, keyed by project root and patterns.
     *
     * Resolution sits inside per-class lookups — {@see MethodTracer},
     * GraphBuilder, ControllerAnalyzer and QueryTracer all call it once per FQCN they cannot
     * place. With literal directories that is two is_dir() calls and free; with a glob it is a
     * directory scan, measured at 0.19 ms against a 90-package tree, which thousands of lookups
     * turn into seconds of re-globbing a tree that has not changed.
     *
     * Process-static, so it must be cleared when the filesystem may have moved under it —
     * {@see clear()}, called alongside ProjectFileIndex::clear() at the start of every analyze()
     * and before each watch poll.
     *
     * @var array<string, string[]>
     */
    private static array $resolved = [];

    public static function clear(): void
    {
        self::$resolved = [];
    }

    /**
     * `glob()` for directories, with brace expansion wherever the platform offers it.
     *
     * The guard is the whole point of this method existing. On PHP 8.4 and older `GLOB_BRACE` is
     * re-exported from the platform's own `glob.h`, and musl does not define it — so on Alpine the
     * userland constant is absent and naming it is a fatal error rather than an ignored flag
     * (issue #139). PHP 8.5 bundles its own glob and defines it everywhere.
     *
     * `GLOB_ONLYDIR` needs no guard even though musl omits that too: PHP has emulated it in
     * `main/streams/glob_wrapper.c` for years, which is why the crash names `GLOB_BRACE` and not
     * the left-hand operand.
     *
     * Where the constant is missing a brace pattern matches nothing instead of erroring. Wildcards
     * are unaffected, and no shipped default uses braces — the modular-monolith example is
     * `app-modules/*\/src`.
     *
     * Every `glob()` for directories in this package goes through here, so the flag is written
     * once. It was written four times before, which is how one missing constant became four
     * crashes.
     *
     * @return list<string> absolute paths
     */
    public static function globDirectories(string $pattern): array
    {
        return glob($pattern, GLOB_ONLYDIR | (defined('GLOB_BRACE') ? GLOB_BRACE : 0)) ?: [];
    }

    /**
     * @param  string[]  $patterns  paths or glob patterns, relative to the project root
     * @return string[] existing directories, relative to the project root
     */
    public static function resolve(string $projectRoot, array $patterns): array
    {
        $root = rtrim($projectRoot, '/');
        $memoKey = $root."\0".implode("\0", $patterns);

        if (isset(self::$resolved[$memoKey])) {
            return self::$resolved[$memoKey];
        }

        $directories = [];

        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }

            $absolute = $root.'/'.ltrim($pattern, '/');

            if (is_dir($absolute)) {
                $directories[] = ltrim($pattern, '/');

                continue;
            }

            foreach (self::globDirectories($absolute) as $match) {
                $directories[] = ltrim(substr($match, strlen($root)), '/');
            }
        }

        return self::$resolved[$memoKey] = array_values(array_unique($directories));
    }

    /**
     * Prefixes for the relative-path lookup that precedes the by-file-name search: a
     * class whose namespace mirrors a directory layout is found by joining the two.
     *
     * `app/Http/Controllers/` is kept while `app` is a source path, since that is where
     * Laravel's own controllers sit and the prefix predates this being configurable.
     *
     * @param  string[]  $sourcePaths
     * @return string[] each with a trailing slash
     */
    public static function classFilePrefixes(string $projectRoot, array $sourcePaths): array
    {
        $prefixes = [];

        foreach (self::resolve($projectRoot, $sourcePaths) as $directory) {
            if ($directory === 'app') {
                $prefixes[] = 'app/Http/Controllers/';
            }
            $prefixes[] = trim($directory, '/').'/';
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * Whether an absolute path sits inside one of the given directories.
     *
     * Anchored at the project root rather than a substring test: `str_contains($p, '/app/')`
     * calls every file "in app/" when the project itself lives under a directory of that
     * name, and would let a change outside the source tree take a scoped rebuild.
     *
     * @param  string[]  $directories  relative to the project root
     */
    public static function contains(string $projectRoot, array $directories, string $path): bool
    {
        $root = rtrim($projectRoot, '/');

        foreach ($directories as $directory) {
            if (str_starts_with($path, $root.'/'.trim($directory, '/').'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every PHP file below the given directories, each file yielded once even when
     * the directories overlap.
     *
     * Paths come back resolved ({@see \SplFileInfo::getRealPath()}), while {@see contains()}
     * compares against an unresolved project root. The two never meet today — this feeds
     * scanning, that answers containment — and routing a containment test through here would
     * silently disagree with itself under a symlinked project root.
     *
     * @param  string[]  $directories  relative to the project root
     * @return iterable<string> absolute file paths
     */
    public static function phpFiles(string $projectRoot, array $directories): iterable
    {
        $root = rtrim($projectRoot, '/');
        $seen = [];

        foreach ($directories as $directory) {
            $absolute = $root.'/'.ltrim($directory, '/');
            if (! is_dir($absolute)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }

                $path = $entry->getRealPath() ?: $entry->getPathname();
                if (isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;

                yield $path;
            }
        }
    }
}
