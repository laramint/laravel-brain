<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class ChannelDefinition
{
    public function __construct(
        public string $name,        // e.g. "App.Models.User.{id}"
        public string $class,       // FQCN if class-based, '' if closure
        public string $file,
    ) {}
}

class ChannelAnalyzer
{
    private PhpFileParser $parser;

    private ProjectStructure $projectStructure;

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    /**
     * @return ChannelDefinition[]
     */
    public function analyze(string $projectRoot): array
    {
        $channels = [];

        foreach ($this->projectStructure->discoverRouteDirectories($projectRoot) as $channelsDir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($channelsDir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $entry) {
                if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                    continue;
                }
                if (! str_contains(strtolower($entry->getBasename()), 'channel')) {
                    continue;
                }

                $parsed = $this->parser->parse($entry->getPathname());
                if (! $parsed || ! $parsed['ast']) {
                    continue;
                }

                $channels = array_merge(
                    $channels,
                    $this->extractChannels($parsed['ast'], $parsed['useMap'], $entry->getPathname())
                );
            }
        }

        return $channels;
    }

    private function extractChannels(array $ast, array $useMap, string $file): array
    {
        $traverser = new NodeTraverser;
        $visitor = new class($file, $useMap) extends NodeVisitorAbstract
        {
            public array $channels = [];

            public function __construct(
                private string $file,
                private array $useMap,
            ) {}

            public function enterNode(Node $node): null
            {
                // Broadcast::channel('name', SomeClass::class)
                // Broadcast::channel('name', function ($user) { ... })
                if (! $node instanceof Node\Expr\StaticCall) {
                    return null;
                }
                if (! $node->class instanceof Node\Name) {
                    return null;
                }
                if ($node->class->getLast() !== 'Broadcast') {
                    return null;
                }

                $method = $node->name instanceof Node\Identifier
                    ? $node->name->toString()
                    : null;

                if ($method !== 'channel') {
                    return null;
                }

                $name = $this->strArg($node->args[0] ?? null);
                $class = $this->classArg($node->args[1] ?? null);

                if ($name !== null) {
                    $this->channels[] = new ChannelDefinition(
                        name: $name,
                        class: $class,
                        file: $this->file,
                    );
                }

                return null;
            }

            private function strArg(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $val = $node instanceof Node\Arg ? $node->value : $node;

                return $val instanceof Node\Scalar\String_ ? $val->value : null;
            }

            private function classArg(?Node $node): string
            {
                if ($node === null) {
                    return '';
                }
                $val = $node instanceof Node\Arg ? $node->value : $node;

                // SomeClass::class
                if ($val instanceof Node\Expr\ClassConstFetch
                    && $val->class instanceof Node\Name) {
                    $name = $val->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                // new SomeClass
                if ($val instanceof Node\Expr\New_ && $val->class instanceof Node\Name) {
                    $name = $val->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                return '';
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->channels;
    }
}
