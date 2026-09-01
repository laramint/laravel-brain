<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class ModelDefinition
{
    public function __construct(
        public string $fqcn,
        public string $file,
        public array $relationships, // ['type' => 'hasMany', 'related' => FQCN][]
        public array $firedEvents,   // FQCN[]
        public array $fillable = [],
        public array $guarded = [],
        public array $casts = [],    // name => type
        public array $dates = [],
        public string $table = '',
        public string $primaryKey = 'id',
        public string $keyType = 'int',
        public bool $incrementing = true,
        public bool $timestamps = true,
        public bool $usesSoftDeletes = false,
        public array $appends = [],
        public array $accessors = [], // virtual attribute names
        public ?string $morphAlias = null, // the value this model writes to `*_type` columns
        public bool $morphAliasMissing = false,
    ) {}
}

class ModelAnalyzer
{
    private PhpFileParser $parser;

    public const RELATIONSHIP_METHODS = [
        'hasOne', 'hasMany', 'hasOneThrough', 'hasManyThrough',
        'belongsTo', 'belongsToMany',
        'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    /** Base classes whose descendants are considered Eloquent models. */
    private const MODEL_BASE_CLASSES = [
        'Illuminate\\Database\\Eloquent\\Model',
        'Illuminate\\Foundation\\Auth\\User',
        'Illuminate\\Database\\Eloquent\\Pivot',
        'Illuminate\\Database\\Eloquent\\Relations\\Pivot',
    ];

    /** @var string[] directories (relative to project root) to scan for models */
    private array $modelPaths;

    private MorphMap $morphMap;

    /**
     * @param  string[]  $modelPaths  Configured model directories. When empty,
     *                                discovery falls back to every PSR-4 root.
     * @param  MorphMap|null  $morphMap  The aliases the application has registered. Supplied by
     *                                   the caller rather than read here, so that the one runtime
     *                                   fact in an otherwise AST-only analyzer is probed once, at
     *                                   the composition root, and tests can state a map outright.
     */
    public function __construct(array $modelPaths = [], ?MorphMap $morphMap = null)
    {
        $this->parser = new PhpFileParser;
        $this->modelPaths = $modelPaths;
        $this->morphMap = $morphMap ?? new MorphMap;
    }

    /**
     * @param  string[]  $fqcns
     * @return array<string, ModelDefinition> FQCN => ModelDefinition
     */
    public function analyze(string $projectRoot, array $fqcns): array
    {
        $psr4Map = $this->buildPsr4Map($projectRoot);
        $definitions = [];

        foreach (array_unique($fqcns) as $fqcn) {
            $file = $this->resolveFile($fqcn, $projectRoot, $psr4Map);
            if ($file === null || ! file_exists($file)) {
                continue;
            }

            $def = $this->analyzeFile($fqcn, $file);
            if ($def !== null) {
                $definitions[$fqcn] = $def;
            }
        }

        return $definitions;
    }

    /**
     * Discover every Eloquent model class declared under the project's PSR-4
     * source roots — independent of whether a route ever references it. Uses
     * AST only (no autoloading) and resolves transitive `extends` chains so
     * subclasses of a project base model are still detected.
     *
     * @return string[] model FQCNs
     */
    public function discoverModels(string $projectRoot): array
    {
        // class FQCN => parent FQCN (resolved via that file's use map)
        $parents = [];

        foreach ($this->resolveScanDirs($projectRoot) as $basePath) {
            if (! is_dir($basePath)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $fileInfo) {
                if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }
                $this->collectClassParents($fileInfo->getPathname(), $parents);
            }
        }

        $models = [];
        foreach (array_keys($parents) as $fqcn) {
            if ($this->reachesModelBase($fqcn, $parents)) {
                $models[] = $fqcn;
            }
        }

        return $models;
    }

    /**
     * @param  array<string,string>  $parents  accumulator (class => parent)
     *
     * @param-out array<string, string> $parents
     */
    private function collectClassParents(string $file, array &$parents): void
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return;
        }
        $useMap = $parsed['useMap'];

        $traverser = new NodeTraverser;
        $visitor = new class($useMap) extends NodeVisitorAbstract
        {
            /** @var array<string, string> fqcn => parentFqcn|'' */
            public array $found = [];

            private array $useMap;

            private string $namespace = '';

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_ && $node->name !== null) {
                    $this->namespace = $node->name->toString();
                }
                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $fqcn = $this->namespace !== ''
                        ? $this->namespace.'\\'.$node->name->toString()
                        : $node->name->toString();
                    $parent = '';
                    if ($node->extends instanceof Node\Name) {
                        $name = $node->extends->toString();
                        if (isset($this->useMap[$name])) {
                            $parent = $this->useMap[$name];
                        } elseif ($node->extends->isFullyQualified() || str_contains($name, '\\')) {
                            $parent = $name;
                        } else {
                            $parent = ($this->namespace !== '' ? $this->namespace.'\\' : '').$name;
                        }
                    }
                    $this->found[$fqcn] = $parent;
                }

                return null;
            }
        };
        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        foreach ($visitor->found as $fqcn => $parent) {
            $parents[$fqcn] = $parent;
        }
    }

    /**
     * @param  array<string,string>  $parents
     */
    private function reachesModelBase(string $fqcn, array $parents, int $depth = 0): bool
    {
        if ($depth > 20) {
            return false;
        }
        $parent = $parents[$fqcn] ?? '';
        if ($parent === '') {
            return false;
        }
        if (in_array($parent, self::MODEL_BASE_CLASSES, true)) {
            return true;
        }

        return $this->reachesModelBase($parent, $parents, $depth + 1);
    }

    private function analyzeFile(string $fqcn, string $file): ?ModelDefinition
    {
        $parsed = $this->parser->parse($file);
        if ($parsed['ast'] === null) {
            return null;
        }

        $traverser = new NodeTraverser;
        $visitor = new class($parsed['useMap']) extends NodeVisitorAbstract
        {
            public array $relationships = [];

            public array $firedEvents = [];

            public array $fillable = [];

            public array $guarded = [];

            public array $casts = [];

            public array $dates = [];

            public string $table = '';

            public ?string $primaryKey = null;

            public ?string $keyType = null;

            public ?bool $incrementing = null;

            public ?bool $timestamps = null;

            public bool $usesSoftDeletes = false;

            public array $appends = [];

            public array $accessors = [];

            public bool $isAbstract = false;

            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                    $this->isAbstract = $node->isAbstract();
                }

                // SoftDeletes trait
                if ($node instanceof Node\Stmt\TraitUse) {
                    foreach ($node->traits as $t) {
                        $name = $t->toString();
                        $resolved = $this->useMap[$name] ?? $name;
                        if (str_ends_with($resolved, 'SoftDeletes')) {
                            $this->usesSoftDeletes = true;
                        }
                    }
                }

                // Typed model config + array properties
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $pname = $prop->name->toString();
                        $default = $prop->default;

                        if ($pname === 'dispatchesEvents' && $default instanceof Node\Expr\Array_) {
                            foreach ($default->items as $item) {
                                if ($item && $item->value instanceof Node\Expr\ClassConstFetch && $item->value->class instanceof Node\Name) {
                                    $name = $item->value->class->toString();
                                    $this->firedEvents[] = $this->useMap[$name] ?? $name;
                                }
                            }
                        }

                        if (in_array($pname, ['fillable', 'guarded', 'dates', 'appends'], true) && $default instanceof Node\Expr\Array_) {
                            $values = $this->stringList($default);
                            if ($pname === 'fillable') {
                                $this->fillable = $values;
                            } elseif ($pname === 'guarded') {
                                $this->guarded = $values;
                            } elseif ($pname === 'dates') {
                                $this->dates = $values;
                            } else {
                                $this->appends = $values;
                            }
                        }

                        if ($pname === 'casts' && $default instanceof Node\Expr\Array_) {
                            $this->casts = array_merge($this->casts, $this->stringMap($default));
                        }

                        if ($pname === 'table' && $default instanceof Node\Scalar\String_) {
                            $this->table = $default->value;
                        }
                        if ($pname === 'primaryKey' && $default instanceof Node\Scalar\String_) {
                            $this->primaryKey = $default->value;
                        }
                        if ($pname === 'keyType' && $default instanceof Node\Scalar\String_) {
                            $this->keyType = $default->value;
                        }
                        if ($pname === 'incrementing') {
                            $this->incrementing = $this->boolVal($default);
                        }
                        if ($pname === 'timestamps') {
                            $this->timestamps = $this->boolVal($default);
                        }
                    }
                }

                if ($node instanceof Node\Stmt\ClassMethod) {
                    $mname = $node->name->toString();

                    // casts() method (Laravel 11) returning an array literal
                    if ($mname === 'casts') {
                        foreach ($node->stmts ?? [] as $stmt) {
                            if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node\Expr\Array_) {
                                $this->casts = array_merge($this->casts, $this->stringMap($stmt->expr));
                            }
                        }
                    }

                    // Legacy accessor: getFooBarAttribute()
                    if (preg_match('/^get(.+)Attribute$/', $mname, $m)) {
                        $this->accessors[] = $this->snake($m[1]);
                    }

                    // Laravel 9+ attribute accessor: protected function fooBar(): Attribute
                    $returnsAttribute = $node->returnType instanceof Node\Name
                        && str_ends_with(($this->useMap[$node->returnType->toString()] ?? $node->returnType->toString()), 'Attribute');
                    if ($returnsAttribute && ! str_starts_with($mname, 'get')) {
                        $this->accessors[] = $this->snake($mname);
                    }
                }

                // Relationship methods: $this->hasMany(Related::class)
                if ($node instanceof Node\Expr\MethodCall
                    && $node->var instanceof Node\Expr\Variable
                    && $node->var->name === 'this'
                    && $node->name instanceof Node\Identifier
                    && in_array($node->name->toString(), ModelAnalyzer::RELATIONSHIP_METHODS, true)
                ) {
                    $type = $node->name->toString();
                    $related = $this->extractClassRef($node->args[0]->value ?? null);
                    if ($related) {
                        $this->relationships[] = ['type' => $type, 'related' => $related];
                    }
                }

                return null;
            }

            private function stringList(Node\Expr\Array_ $arr): array
            {
                $out = [];
                foreach ($arr->items as $item) {
                    if ($item && $item->value instanceof Node\Scalar\String_) {
                        $out[] = $item->value->value;
                    }
                }

                return $out;
            }

            private function stringMap(Node\Expr\Array_ $arr): array
            {
                $out = [];
                foreach ($arr->items as $item) {
                    if ($item && $item->key instanceof Node\Scalar\String_) {
                        $val = '';
                        if ($item->value instanceof Node\Scalar\String_) {
                            $val = $item->value->value;
                        } elseif ($item->value instanceof Node\Expr\ClassConstFetch && $item->value->class instanceof Node\Name) {
                            $cn = $item->value->class->toString();
                            $val = $this->useMap[$cn] ?? $cn;
                        }
                        $out[$item->key->value] = $val;
                    }
                }

                return $out;
            }

            private function boolVal(?Node $node): ?bool
            {
                if ($node instanceof Node\Expr\ConstFetch) {
                    $n = strtolower($node->name->toString());
                    if ($n === 'true') {
                        return true;
                    }
                    if ($n === 'false') {
                        return false;
                    }
                }

                return null;
            }

            private function snake(string $value): string
            {
                $value = lcfirst($value);

                return strtolower((string) preg_replace('/[A-Z]/', '_$0', $value));
            }

            private function extractClassRef(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    // A related model usually sits in the same namespace and so needs no import.
                    // Left short it becomes a second node for a model the graph already has, and
                    // the relationship points at that one instead of the real one.
                    return PhpFileParser::resolvedName($node->class) ?? $this->useMap[$name] ?? $name;
                }
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        $short = ($pos = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $table = $visitor->table !== ''
            ? $visitor->table
            : $this->guessTable($short);

        $morphAlias = $this->morphMap->aliasFor($fqcn);

        return new ModelDefinition(
            fqcn: $fqcn,
            file: $file,
            relationships: $visitor->relationships,
            firedEvents: $visitor->firedEvents,
            fillable: $visitor->fillable,
            guarded: $visitor->guarded,
            casts: $visitor->casts,
            dates: $visitor->dates,
            table: $table,
            primaryKey: $visitor->primaryKey ?? 'id',
            keyType: $visitor->keyType ?? 'int',
            incrementing: $visitor->incrementing ?? true,
            timestamps: $visitor->timestamps ?? true,
            usesSoftDeletes: $visitor->usesSoftDeletes,
            appends: $visitor->appends,
            accessors: array_values(array_unique($visitor->accessors)),
            morphAlias: $morphAlias,
            // Under an enforced map there is no fallback to the class name: the first
            // `getMorphClass()` call on an unmapped model throws. Carrying the verdict rather
            // than the global "is enforcement on" flag keeps the rule in one place, and stops
            // every model node from repeating a fact about the application.
            //
            // An abstract class is excluded: it cannot be instantiated, so no `getMorphClass()`
            // call can ever reach it, and a morph map that omits it is correct rather than
            // incomplete. Measured on a 60-module application, 2 of the 48 models this flagged
            // were abstract base classes — reported as a defect they can never cause.
            morphAliasMissing: $morphAlias === null
                && $this->morphMap->isEnforced()
                && ! $visitor->isAbstract,
        );
    }

    /** Eloquent's default: snake_case + pluralised class name. */
    private function guessTable(string $short): string
    {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
        if (preg_match('/(s|x|z|ch|sh)$/', $snake)) {
            return $snake.'es';
        }
        if (preg_match('/[^aeiou]y$/', $snake)) {
            return substr($snake, 0, -1).'ies';
        }

        return $snake.'s';
    }

    /**
     * Directories to walk for model discovery. Uses the configured
     * `laravel-brain.models.paths` (relative dirs or glob patterns) when set;
     * otherwise falls back to every PSR-4 source root from composer.json.
     *
     * @return string[] absolute directory paths
     */
    private function resolveScanDirs(string $projectRoot): array
    {
        if ($this->modelPaths !== []) {
            $dirs = [];
            foreach ($this->modelPaths as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '') {
                    continue;
                }
                $abs = $projectRoot.'/'.ltrim($entry, '/');
                if (is_dir($abs)) {
                    $dirs[] = $abs;

                    continue;
                }
                // Treat as a glob pattern (e.g. app/Domain/*/Models)
                foreach (glob($abs, GLOB_ONLYDIR | GLOB_BRACE) ?: [] as $match) {
                    $dirs[] = $match;
                }
            }
            if ($dirs !== []) {
                return array_values(array_unique($dirs));
            }
        }

        $dirs = [];
        foreach ($this->buildPsr4Map($projectRoot) as $basePaths) {
            foreach ((array) $basePaths as $basePath) {
                $dirs[] = $basePath;
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * @return array<string, string[]>
     */
    private function buildPsr4Map(string $projectRoot): array
    {
        return ComposerPsr4Map::build($projectRoot);
    }

    private function resolveFile(string $fqcn, string $projectRoot, array $psr4Map): ?string
    {
        foreach ($psr4Map as $namespace => $basePaths) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace) + 1)).'.php';
                foreach ((array) $basePaths as $basePath) {
                    $filePath = $basePath.'/'.$relative;
                    if (file_exists($filePath)) {
                        return $filePath;
                    }
                }
            }
        }

        return null;
    }
}
