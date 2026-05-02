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
    ) {}
}

class ModelAnalyzer
{
    private PhpFileParser $parser;

    private ProjectStructure $projectStructure;

    /** @var string[] */
    private array $classSearchBases = [];

    public const RELATIONSHIP_METHODS = [
        'hasOne', 'hasMany', 'hasOneThrough', 'hasManyThrough',
        'belongsTo', 'belongsToMany',
        'morphTo', 'morphOne', 'morphMany', 'morphToMany', 'morphedByMany',
    ];

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    /**
     * @param  string[]  $fqcns
     * @return array<string, ModelDefinition> FQCN => ModelDefinition
     */
    public function analyze(string $projectRoot, array $fqcns): array
    {
        $psr4Map = $this->projectStructure->buildPsr4Map($projectRoot);
        $this->classSearchBases = $this->projectStructure->discoverClassSearchBases($projectRoot);
        $definitions = [];

        foreach (array_unique($fqcns) as $fqcn) {
            $file = $this->resolveFile($fqcn, $psr4Map);
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

            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): null
            {
                // $dispatchesEvents property
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() === 'dispatchesEvents' && $prop->default instanceof Node\Expr\Array_) {
                            foreach ($prop->default->items as $item) {
                                if ($item && $item->value instanceof Node\Expr\ClassConstFetch && $item->value->class instanceof Node\Name) {
                                    $name = $item->value->class->toString();
                                    $this->firedEvents[] = $this->useMap[$name] ?? $name;
                                }
                            }
                        }
                    }
                }

                // static::creating(fn...) in boot/booted methods
                if ($node instanceof Node\Expr\StaticCall
                    && $node->class instanceof Node\Name
                    && in_array($node->class->toString(), ['static', 'self'], true)
                ) {
                    $hookMethod = $node->name instanceof Node\Identifier ? $node->name->toString() : null;
                    if (in_array($hookMethod, ['creating', 'created', 'updating', 'updated', 'deleting', 'deleted', 'saving', 'saved', 'restoring', 'restored'], true)) {
                        // Look for event(new EventClass) inside the closure
                        foreach ($node->args as $arg) {
                            // $this->extractEventFromClosure($arg->value);
                        }
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

            private function extractClassRef(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    return $this->useMap[$name] ?? $name;
                }
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return new ModelDefinition(
            fqcn: $fqcn,
            file: $file,
            relationships: $visitor->relationships,
            firedEvents: $visitor->firedEvents,
        );
    }

    private function resolveFile(string $fqcn, array $psr4Map): ?string
    {
        foreach ($psr4Map as $namespace => $basePath) {
            if (str_starts_with($fqcn, $namespace.'\\')) {
                $relative = substr($fqcn, strlen($namespace) + 1);
                $path = $basePath.'/'.str_replace('\\', '/', $relative).'.php';
                if (file_exists($path)) {
                    return $path;
                }
            }
        }

        $relative = str_replace('\\', '/', $fqcn).'.php';
        foreach ($this->classSearchBases as $base) {
            $path = $base.'/'.$relative;
            if (file_exists($path)) {
                return $path;
            }
        }

        return $this->searchByClassName($fqcn);
    }

    private function searchByClassName(string $fqcn): ?string
    {
        $shortName = str_contains($fqcn, '\\')
            ? substr($fqcn, strrpos($fqcn, '\\') + 1)
            : $fqcn;
        $filename = $shortName.'.php';

        foreach ($this->classSearchBases as $base) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getFilename() === $filename) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }
}
