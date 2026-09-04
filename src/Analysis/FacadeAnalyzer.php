<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpExtendsFqcnResolver;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;

/**
 * Scans the application's source directories for application-level Laravel facades
 * and builds a FacadeRegistry.
 *
 * A facade is any concrete class whose inheritance chain leads to
 * Illuminate\Support\Facades\Facade (multi-level inheritance is supported, e.g.
 * ShortUrlV3Facade → AbstractVersionedShortUrlFacade → Facade).
 *
 * getFacadeAccessor() is searched in the class itself and then up the chain.
 * When the accessor resolves to a FQCN via ::class, concreteFqcn is set
 * immediately; plain string keys (e.g. 'cache') are left for
 * FacadeRegistry::resolveWith() to match against the ContainerBindingRegistry.
 */
final class FacadeAnalyzer
{
    private const FACADE_BASE = 'Illuminate\\Support\\Facades\\Facade';

    /**
     * Where application source lives in a default Laravel skeleton.
     *
     * @var string[]
     */
    public const DEFAULT_PATHS = ['app'];

    private PhpFileParser $parser;

    /** @var string[] source directories, relative to the project root */
    private array $paths;

    /** @var string[] the subset of that exists, relative to the project root */
    private array $sourceDirs = [];

    private string $projectRoot = '';

    /** @var array<string, array{ast: mixed, useMap: array<string,string>}|null> */
    private array $parseCache = [];

    /**
     * @param  string[]  $paths  source directories, relative to the project root;
     *                           glob patterns are expanded
     */
    public function __construct(?PhpFileParser $parser = null, array $paths = self::DEFAULT_PATHS)
    {
        $this->parser = $parser ?? new PhpFileParser;
        $this->paths = $paths;
    }

    public function analyze(string $projectRoot): FacadeRegistry
    {
        $registry = new FacadeRegistry;
        $this->parseCache = [];
        $this->facadeChainShortNames = [];
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->sourceDirs = SourceDirectories::resolve($this->projectRoot, $this->paths);

        if ($this->sourceDirs === []) {
            return $registry;
        }

        $files = iterator_to_array(
            SourceDirectories::phpFiles($this->projectRoot, $this->sourceDirs),
            false,
        );

        // One read per file. The second pass used to throw the bytes away and open every
        // remaining file again whenever a chain name turned up.
        /** @var array<string, string> path => source, files not yet parsed */
        $pending = [];
        foreach ($files as $path) {
            $code = @file_get_contents($path);
            if ($code === false) {
                continue;
            }
            if ($this->codeMightDefineFacade($code)) {
                $this->scanFile($path, $registry);
            } else {
                $pending[$path] = $code;
            }
        }

        // Then close the gap that leaves: a facade may extend an app-level base whose own file
        // names Facade while the child's does not. Every class confirmed to sit in the chain is
        // a name a further file might extend, so the unread files are re-offered whenever a new
        // such name turns up, until a round adds nothing. String work only — a file is parsed
        // just when it mentions one of them.
        $seen = [];
        while ($pending !== []) {
            $bases = array_diff(array_keys($this->facadeChainShortNames), $seen);
            if ($bases === []) {
                break;
            }
            $seen = array_merge($seen, $bases);

            $stillPending = [];
            foreach ($pending as $path => $code) {
                $mentions = false;
                foreach ($bases as $base) {
                    if (str_contains($code, $base)) {
                        $mentions = true;
                        break;
                    }
                }
                if ($mentions) {
                    $this->scanFile($path, $registry);
                } else {
                    $stillPending[$path] = $code;
                }
            }
            $pending = $stillPending;
        }

        return $registry;
    }

    /**
     * A file that never names Facade cannot extend it directly. The keyword `extends` used to
     * stand in for this and admitted most of a codebase — 81% of the files of one application
     * measured here.
     *
     * Naming the class is the real test, but `Facade` on its own is not that test: the import
     * `use Illuminate\Support\Facades\Log;` contains it, and most files in a Laravel application
     * carry a line like that. Skipping a `Facades\` match leaves the bare mention that only a
     * file defining or extending a facade has — 22% of one application admitted down to 1%.
     * The base class still matches: after skipping `Facades\` in
     * `Illuminate\Support\Facades\Facade`, the trailing `Facade` remains.
     *
     * On its own this misses a facade that reaches the base through an app-level intermediate;
     * {@see analyze()} closes that with a second pass rather than assuming it away.
     */
    private function codeMightDefineFacade(string $code): bool
    {
        // Case-sensitive, unlike the `extends` keyword this replaced: `Facade` is a class name,
        // and PHP class names are matched case-sensitively by the autoloader in practice.
        $offset = 0;
        while (($p = strpos($code, 'Facade', $offset)) !== false) {
            if (substr($code, $p, 8) === 'Facades\\') {
                $offset = $p + 8;

                continue;
            }

            return true;
        }

        return false;
    }

    private function scanFile(string $file, FacadeRegistry $registry): void
    {
        $parsed = $this->parseWithCache($file);
        if ($parsed === null) {
            return;
        }

        $ns = PhpExtendsFqcnResolver::namespaceFromAst($parsed['ast']);
        $useMap = $parsed['useMap'];
        $stmts = $this->topLevelStmts($parsed['ast']);

        foreach ($stmts as $stmt) {
            if (! ($stmt instanceof Class_) || $stmt->name === null) {
                continue;
            }

            // Abstract classes cannot be injected, so they are never facades themselves — but an
            // abstract base extending Facade is exactly the intermediate a child reaches it
            // through, so its name is still worth remembering.
            if ($stmt->isAbstract()) {
                $parent = PhpExtendsFqcnResolver::resolveExtends($stmt->extends, $ns, $useMap);
                if ($parent !== null && $this->isInFacadeChain($parent, 0)) {
                    $this->facadeChainShortNames[$stmt->name->toString()] = true;
                }

                break;
            }

            $parentFqcn = PhpExtendsFqcnResolver::resolveExtends($stmt->extends, $ns, $useMap);
            if ($parentFqcn === null) {
                break;
            }

            // Check if this class is (directly or transitively) a Facade subclass.
            if (! $this->isInFacadeChain($parentFqcn, 0)) {
                break;
            }

            $short = $stmt->name->toString();
            $facadeFqcn = $ns !== '' ? $ns.'\\'.$short : $short;

            // This class is itself a name a further file may extend to become a facade.
            $this->facadeChainShortNames[$short] = true;

            // Find getFacadeAccessor() in this class or an ancestor.
            $accessor = $this->findAccessorInChain($stmt, $ns, $useMap, 0);
            if ($accessor === null) {
                break;
            }

            $concreteFqcn = str_contains($accessor, '\\') ? $accessor : null;
            $registry->add(new FacadeRecord($facadeFqcn, $accessor, $concreteFqcn));
            break;
        }
    }

    // ── Inheritance chain helpers ─────────────────────────────────────────────

    /**
     * Return true when $fqcn is Illuminate\Support\Facades\Facade or extends it
     * (directly or through intermediate app-level classes).
     */
    /** @var array<string, true> short names of classes confirmed to sit in the facade chain */
    private array $facadeChainShortNames = [];

    private function isInFacadeChain(string $fqcn, int $depth): bool
    {
        if ($fqcn === self::FACADE_BASE) {
            return true;
        }
        if ($depth >= 5 || str_starts_with($fqcn, 'Illuminate\\') || str_starts_with($fqcn, 'Laravel\\')) {
            return false;
        }

        $file = $this->findFileInSourceDirs($fqcn);
        if ($file === null) {
            return false;
        }

        $parsed = $this->parseWithCache($file);
        if ($parsed === null) {
            return false;
        }

        $ns = PhpExtendsFqcnResolver::namespaceFromAst($parsed['ast']);
        foreach ($this->topLevelStmts($parsed['ast']) as $stmt) {
            if (! ($stmt instanceof Class_)) {
                continue;
            }
            $parentFqcn = PhpExtendsFqcnResolver::resolveExtends($stmt->extends, $ns, $parsed['useMap']);

            return $parentFqcn !== null && $this->isInFacadeChain($parentFqcn, $depth + 1);
        }

        return false;
    }

    /**
     * Look for getFacadeAccessor() in $class, then walk up parent classes in app/.
     *
     * @param  array<string, string>  $useMap
     */
    private function findAccessorInChain(Class_ $class, string $ns, array $useMap, int $depth): ?string
    {
        $accessor = $this->extractAccessor($class, $ns, $useMap);
        if ($accessor !== null) {
            return $accessor;
        }

        if ($depth >= 5) {
            return null;
        }

        $parentFqcn = PhpExtendsFqcnResolver::resolveExtends($class->extends, $ns, $useMap);
        if (
            $parentFqcn === null
            || $parentFqcn === self::FACADE_BASE
            || str_starts_with($parentFqcn, 'Illuminate\\')
            || str_starts_with($parentFqcn, 'Laravel\\')
        ) {
            return null;
        }

        $file = $this->findFileInSourceDirs($parentFqcn);
        if ($file === null) {
            return null;
        }

        $parsed = $this->parseWithCache($file);
        if ($parsed === null) {
            return null;
        }

        $parentNs = PhpExtendsFqcnResolver::namespaceFromAst($parsed['ast']);
        foreach ($this->topLevelStmts($parsed['ast']) as $stmt) {
            if (! ($stmt instanceof Class_)) {
                continue;
            }

            return $this->findAccessorInChain($stmt, $parentNs, $parsed['useMap'], $depth + 1);
        }

        return null;
    }

    // ── Low-level parsing helpers ─────────────────────────────────────────────

    /**
     * Find getFacadeAccessor() in $class and return its string return value.
     *
     * @param  array<string, string>  $useMap
     */
    private function extractAccessor(Class_ $class, string $namespace, array $useMap): ?string
    {
        foreach ($class->stmts as $stmt) {
            if (! ($stmt instanceof Node\Stmt\ClassMethod)) {
                continue;
            }
            if ($stmt->name->toString() !== 'getFacadeAccessor') {
                continue;
            }
            if ($stmt->stmts === null) {
                continue;
            }

            foreach ($stmt->stmts as $bodyStmt) {
                if (! ($bodyStmt instanceof Node\Stmt\Return_)) {
                    continue;
                }
                $expr = $bodyStmt->expr;
                if ($expr === null) {
                    continue;
                }

                // return SomeClass::class
                if (
                    $expr instanceof Expr\ClassConstFetch
                    && $expr->name instanceof Identifier
                    && $expr->name->toString() === 'class'
                    && $expr->class instanceof Node\Name
                ) {
                    $resolved = $this->resolveNameToFqcn($expr->class, $namespace, $useMap);
                    if ($resolved !== '') {
                        return $resolved;
                    }
                }

                // return 'App\Services\Foo' or 'some-container-key'
                if ($expr instanceof Scalar\String_ && $expr->value !== '') {
                    return ltrim($expr->value, '\\');
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function resolveNameToFqcn(Node\Name $name, string $namespace, array $useMap): string
    {
        if ($name instanceof Node\Name\FullyQualified) {
            return ltrim($name->toString(), '\\');
        }

        $short = $name->toString();
        if (isset($useMap[$short])) {
            return $useMap[$short];
        }

        if (str_contains($short, '\\')) {
            return ($namespace !== '' ? $namespace.'\\' : '').$short;
        }

        return $namespace !== '' ? $namespace.'\\'.$short : $short;
    }

    /**
     * Find the PHP file for a FQCN by searching the configured source directories
     * for a file whose name matches the short class name.
     */
    private function findFileInSourceDirs(string $fqcn): ?string
    {
        if ($this->projectRoot === '') {
            return null;
        }

        $shortName = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;

        return ProjectFileIndex::findFile($this->projectRoot, $this->sourceDirs, $shortName.'.php');
    }

    /**
     * @return array{ast: mixed, useMap: array<string,string>}|null
     */
    private function parseWithCache(string $file): ?array
    {
        if (array_key_exists($file, $this->parseCache)) {
            return $this->parseCache[$file];
        }

        $parsed = $this->parser->parse($file);
        $result = $parsed['ast'] !== null ? $parsed : null;

        return $this->parseCache[$file] = $result;
    }

    /**
     * Return the top-level statements, unwrapping a Namespace_ wrapper if present.
     *
     * @return Node\Stmt[]
     */
    private function topLevelStmts(mixed $ast): array
    {
        if (! is_array($ast)) {
            return [];
        }

        // Find the namespace wherever it sits: a leading `declare(strict_types=1);` shifts it off
        // index 0, which used to make every scan through here silently skip the whole file.
        foreach ($ast as $stmt) {
            if ($stmt instanceof Namespace_) {
                return $stmt->stmts;
            }
        }

        return $ast;
    }
}
