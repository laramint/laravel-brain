<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class MiddlewareAnalyzer
{
    private PhpFileParser $parser;

    private ProjectStructure $projectStructure;

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    public function analyze(string $projectRoot): MiddlewareRegistry
    {
        $global = [];
        $groups = [];
        $aliases = [];

        foreach ($this->projectStructure->discoverRoots($projectRoot) as $root) {
            $kernelPath = $root.'/app/Http/Kernel.php';
            $bootstrapPath = $root.'/bootstrap/app.php';

            $registry = null;
            if (file_exists($kernelPath)) {
                $registry = $this->analyzeLaravel10($kernelPath);
            } elseif (file_exists($bootstrapPath)) {
                $registry = $this->analyzeLaravel11($bootstrapPath);
            }

            if ($registry === null) {
                continue;
            }

            $global = array_values(array_unique(array_merge($global, $registry->global)));
            foreach ($registry->groups as $name => $members) {
                $groups[$name] = array_values(array_unique(array_merge($groups[$name] ?? [], $members)));
            }
            $aliases = array_merge($aliases, $registry->aliases);
        }

        return new MiddlewareRegistry($global, $groups, $aliases);
    }

    private function analyzeLaravel10(string $kernelPath): MiddlewareRegistry
    {
        $parsed = $this->parser->parse($kernelPath);
        if ($parsed['ast'] === null) {
            return new MiddlewareRegistry([], [], []);
        }

        $traverser = new NodeTraverser;
        $visitor = new class($parsed['useMap']) extends NodeVisitorAbstract
        {
            public array $global = [];

            public array $groups = [];

            public array $aliases = [];

            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Stmt\Property) {
                    return null;
                }

                foreach ($node->props as $prop) {
                    $name = $prop->name->toString();
                    $default = $prop->default;

                    if ($name === 'middleware' && $default instanceof Node\Expr\Array_) {
                        $this->global = $this->extractStringArray($default);
                    } elseif ($name === 'middlewareGroups' && $default instanceof Node\Expr\Array_) {
                        foreach ($default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                            if ($key && $item->value instanceof Node\Expr\Array_) {
                                $this->groups[$key] = $this->extractStringArray($item->value);
                            }
                        }
                    } elseif (in_array($name, ['middlewareAliases', 'routeMiddleware'], true) && $default instanceof Node\Expr\Array_) {
                        foreach ($default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $key = $item->key instanceof Node\Scalar\String_ ? $item->key->value : null;
                            $value = $this->extractClassString($item->value);
                            if ($key && $value) {
                                $this->aliases[$key] = $value;
                            }
                        }
                    }
                }

                return null;
            }

            private function extractStringArray(Node\Expr\Array_ $array): array
            {
                $result = [];
                foreach ($array->items as $item) {
                    if (! $item) {
                        continue;
                    }
                    $value = $this->extractClassString($item->value);
                    if ($value) {
                        $result[] = $value;
                    }
                }

                return $result;
            }

            private function extractClassString(Node $node): ?string
            {
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return new MiddlewareRegistry($visitor->global, $visitor->groups, $visitor->aliases);
    }

    private function analyzeLaravel11(string $bootstrapPath): MiddlewareRegistry
    {
        $parsed = $this->parser->parse($bootstrapPath);
        if ($parsed['ast'] === null) {
            return new MiddlewareRegistry([], [], []);
        }

        $traverser = new NodeTraverser;
        $visitor = new class($parsed['useMap']) extends NodeVisitorAbstract
        {
            public array $groups = [];

            public array $aliases = [];

            private array $useMap;

            public function __construct(array $useMap)
            {
                $this->useMap = $useMap;
            }

            public function enterNode(Node $node): null
            {
                if (! $node instanceof Node\Expr\MethodCall) {
                    return null;
                }
                $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                if (in_array($methodName, ['api', 'web'], true)) {
                    $this->groups[$methodName] = $this->extractAppendList($node);
                } elseif ($methodName === 'alias') {
                    $this->extractAliases($node);
                }

                return null;
            }

            private function extractAppendList(Node\Expr\MethodCall $node): array
            {
                foreach ($node->args as $arg) {
                    if ($arg->name instanceof Node\Identifier && $arg->name->toString() === 'append') {
                        if ($arg->value instanceof Node\Expr\Array_) {
                            return $this->extractClassArray($arg->value);
                        }
                    }
                }

                return [];
            }

            private function extractAliases(Node\Expr\MethodCall $node): void
            {
                if (count($node->args) >= 2) {
                    $alias = $node->args[0]->value instanceof Node\Scalar\String_
                        ? $node->args[0]->value->value
                        : null;
                    $class = $this->extractClassString($node->args[1]->value);
                    if ($alias && $class) {
                        $this->aliases[$alias] = $class;
                    }
                }
            }

            private function extractClassArray(Node\Expr\Array_ $array): array
            {
                $result = [];
                foreach ($array->items as $item) {
                    if (! $item) {
                        continue;
                    }
                    $value = $this->extractClassString($item->value);
                    if ($value) {
                        $result[] = $value;
                    }
                }

                return $result;
            }

            private function extractClassString(Node $node): ?string
            {
                if ($node instanceof Node\Scalar\String_) {
                    return $node->value;
                }
                if ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
                    $name = $node->class->toString();

                    return $this->useMap[$name] ?? $name;
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return new MiddlewareRegistry([], $visitor->groups, $visitor->aliases);
    }
}
