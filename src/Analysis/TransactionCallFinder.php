<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Whether a subtree calls any of the named methods, by name alone.
 *
 * Used to answer "does this statement commit" and "does this catch block roll back", where the
 * receiver has already been established by the statement's surroundings. Matching on the name
 * alone would be too loose as a general test; here the question is only ever asked of code that
 * sits between a `beginTransaction()` and its end.
 *
 * @internal to {@see TransactionScopes}
 */
final class TransactionCallFinder extends NodeVisitorAbstract
{
    public bool $found = false;

    /** @param list<string> $methods */
    public function __construct(private readonly array $methods) {}

    public function enterNode(Node $node): ?int
    {
        $name = null;

        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
        } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
        }

        if ($name !== null && in_array($name, $this->methods, true)) {
            $this->found = true;
        }

        return null;
    }
}
