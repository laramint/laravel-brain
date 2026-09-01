<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Marks every node that sits inside a transaction, and every node on a rollback path.
 *
 * Works in two passes over the same tree, because the two forms of transaction are different
 * shapes and neither reduces to the other:
 *
 *  - The closure form is a subtree. `DB::transaction(fn () => ...)` hands its body to the
 *    framework, so the span is exactly that body and nesting takes care of itself.
 *  - The hand-rolled form is a *range within a statement list*. `beginTransaction()` and
 *    `commit()` are siblings, and everything between them is inside — which cannot be read off
 *    the tree structure at all, only off the order of the statements.
 *
 * @internal to {@see TransactionScopes}
 */
final class TransactionScopeCollector extends NodeVisitorAbstract
{
    /** @var array<int, true> */
    public array $inTransaction = [];

    /** @var array<int, true> */
    public array $inRollback = [];

    public function enterNode(Node $node): ?int
    {
        $body = TransactionScopes::opensClosureTransaction($node);

        if ($body !== null) {
            $this->inTransaction += $this->subtreeIds($body);
        }

        // A catch block that rolls back is the compensation path: everything it does happens with
        // the transaction already gone, so a write there survives and an event there announces a
        // failure. Marked separately from the span it follows.
        if ($node instanceof Node\Stmt\TryCatch) {
            foreach ($node->catches as $catch) {
                if (TransactionScopes::statementCalls($catch, ['rollBack', 'rollback'])) {
                    foreach ($catch->stmts as $statement) {
                        $this->inRollback += $this->subtreeIds($statement);
                    }
                }
            }
        }

        $this->markManualRanges($node);

        return null;
    }

    /**
     * The hand-rolled form, read off any statement list this node holds.
     *
     * Scanning is per list rather than per method because a `beginTransaction()` inside an `if`
     * closes within that branch, and treating the whole method as one sequence would run the span
     * past the end of the block it lives in.
     */
    private function markManualRanges(Node $node): void
    {
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;

            if (! is_array($value) || $value === []) {
                continue;
            }

            $open = false;

            foreach ($value as $statement) {
                if (! $statement instanceof Node) {
                    continue;
                }

                if ($open) {
                    $this->inTransaction += $this->subtreeIds($statement);

                    // The statement that ends the span is part of it — `commit()` runs inside.
                    if (TransactionScopes::statementCalls($statement, ['commit', 'rollBack', 'rollback'])) {
                        $open = false;
                    }

                    continue;
                }

                if (! TransactionScopes::statementCalls($statement, ['beginTransaction'])) {
                    continue;
                }

                $this->inTransaction += $this->subtreeIds($statement);

                // Only an *unbalanced* opener runs the span on into its siblings. A statement
                // holding both ends — `if ($flag) { begin; …; commit; }` — has already closed
                // inside itself, and treating it as an opener would swallow the whole rest of
                // the method. The nested list is marked on its own pass anyway.
                $open = ! TransactionScopes::statementCalls($statement, ['commit', 'rollBack', 'rollback']);
            }
        }
    }

    /**
     * Every node id in a subtree.
     *
     * @return array<int, true>
     */
    private function subtreeIds(Node $subject): array
    {
        $marker = new class extends NodeVisitorAbstract
        {
            /** @var array<int, true> */
            public array $ids = [];

            public function enterNode(Node $node): ?int
            {
                $this->ids[spl_object_id($node)] = true;

                return null;
            }
        };

        $traverser = new NodeTraverser;
        $traverser->addVisitor($marker);
        $traverser->traverse([$subject]);

        return $marker->ids;
    }
}
