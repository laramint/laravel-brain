<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use PhpParser\Node;
use PhpParser\NodeTraverser;

/**
 * Which parts of a method run inside a database transaction, and which run because one failed.
 *
 * A transaction is not a step in a call chain — it is a span across several. Modelling it as a
 * node would put something in the path that nothing calls and nothing returns from, and would add
 * two hops to every route that opens one, quietly changing every fan-out and every shortest path
 * the graph reports. So this answers a question about a region instead: given a node, is it inside
 * a transaction, and is it on the path that only runs after a rollback.
 *
 * ## Both forms, because both are written
 *
 * Measured on the application this was built against: 103 closure transactions, 8 hand-rolled
 * pairs, 7 rollbacks.
 *
 *   DB::transaction(fn () => ...)            the body is the span
 *   DB::beginTransaction() ... DB::commit()  the statements between are the span
 *
 * The method names are Laravel's: `transaction`, `beginTransaction`, `commit`, `rollBack`. There
 * is no `startTransaction` or `endTransaction` in the framework, and a detector keyed on those
 * would silently find nothing at all.
 *
 * ## The rollback path is worth its own answer
 *
 * `rollBack()` ends a transaction; it does not end the method. Whatever the catch block does next
 * runs with the transaction already gone — so a write there is not rolled back with the rest, and
 * an event fired there describes a failure rather than a fact. Knowing a call sits on that path
 * is a different thing from knowing it sat inside the transaction, so it is tracked separately.
 */
final class TransactionScopes
{
    /** Receivers whose `transaction()` opens a database transaction rather than something else. */
    private const TRANSACTION_RECEIVERS = ['db', 'connection', 'schema'];

    /**
     * @param  array<int, true>  $inTransaction  Keyed by `spl_object_id` of every node in a span.
     * @param  array<int, true>  $inRollback
     */
    private function __construct(
        private readonly array $inTransaction,
        private readonly array $inRollback,
    ) {}

    /**
     * Find every span in one method, closure or function body.
     */
    public static function in(Node $subject): self
    {
        $collector = new TransactionScopeCollector;

        $traverser = new NodeTraverser;
        $traverser->addVisitor($collector);
        $traverser->traverse([$subject]);

        return new self($collector->inTransaction, $collector->inRollback);
    }

    /** An instance that answers no to everything, for callers with nothing to inspect. */
    public static function none(): self
    {
        return new self([], []);
    }

    public function isInTransaction(Node $node): bool
    {
        return isset($this->inTransaction[spl_object_id($node)]);
    }

    public function isInRollback(Node $node): bool
    {
        return isset($this->inRollback[spl_object_id($node)]);
    }

    public function hasAny(): bool
    {
        return $this->inTransaction !== [] || $this->inRollback !== [];
    }

    /**
     * Whether a call opens a transaction by taking a closure — `DB::transaction(fn () => ...)`.
     *
     * The receiver is checked because `transaction()` is not a reserved word: a domain service
     * with its own `transaction()` would otherwise put half a method inside a span that does not
     * exist. `Schema` is included because its callback runs in one on most drivers.
     */
    public static function opensClosureTransaction(Node $node): ?Node
    {
        $name = null;
        $receiver = null;

        if ($node instanceof Node\Expr\StaticCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
            $receiver = $node->class instanceof Node\Name ? strtolower($node->class->getLast()) : null;
        } elseif ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            $name = $node->name->toString();
            $receiver = self::receiverHint($node->var);
        }

        if ($name !== 'transaction' || $receiver === null || ! in_array($receiver, self::TRANSACTION_RECEIVERS, true)) {
            return null;
        }

        $args = $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall
            ? $node->getArgs()
            : [];

        foreach ($args as $arg) {
            if ($arg->value instanceof Node\Expr\Closure || $arg->value instanceof Node\Expr\ArrowFunction) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * The name a method call is made on, as far as it can be told from the syntax.
     *
     * `DB::connection('x')->transaction(...)` and `$this->db->transaction(...)` both arrive here;
     * the first resolves through the static call it chains from, the second through the property
     * it reads. Anything else is unknown, and unknown means "not a transaction" — the cost of
     * guessing wrong is a span drawn around code that never had one.
     */
    private static function receiverHint(Node $var): ?string
    {
        if ($var instanceof Node\Expr\StaticCall && $var->class instanceof Node\Name) {
            return strtolower($var->class->getLast());
        }

        if ($var instanceof Node\Expr\MethodCall) {
            return self::receiverHint($var->var);
        }

        if ($var instanceof Node\Expr\PropertyFetch && $var->name instanceof Node\Identifier) {
            return strtolower($var->name->toString());
        }

        if ($var instanceof Node\Expr\Variable && is_string($var->name)) {
            return strtolower($var->name);
        }

        return null;
    }

    /**
     * Whether a statement contains a call to one of the named transaction methods.
     *
     * @param  list<string>  $methods
     */
    public static function statementCalls(Node $statement, array $methods): bool
    {
        $finder = new TransactionCallFinder($methods);

        $traverser = new NodeTraverser;
        $traverser->addVisitor($finder);
        $traverser->traverse([$statement]);

        return $finder->found;
    }
}
