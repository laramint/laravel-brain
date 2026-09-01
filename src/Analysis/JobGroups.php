<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;

/**
 * Reads a chain or a batch off a single dispatch site.
 *
 * Like a transaction, a chain is a span across several nodes rather than a step between them, so
 * it is answered as a question about a region: given a call, which jobs did it dispatch together,
 * and in what order. Nothing here walks a tree — {@see self::at()} is handed one node at a time by
 * whoever is already traversing, because every form of the group is a single call expression and a
 * second traversal would only have to find the same nodes again.
 *
 * ## The five forms, and why the list stops there
 *
 *     Bus::chain([new A, new B])            the facade, and the only form the tracer read before
 *     Bus::batch([new A, new B])
 *     A::withChain([new B])->dispatch()     the head is the class the call is made on
 *     dispatch(new A)->chain([new B])       the head is the job handed to the dispatch helper
 *     A::dispatch()->chain([new B])         same, through the static dispatch verb
 *
 * These are the ways the framework offers to name a chain or a batch at the point of dispatch.
 * `$batch->add(...)` and `Bus::batch([[new A, new B]])` are deliberately not among them: the first
 * adds to a batch created somewhere else, so the site holds no set to draw, and the second is a
 * chain nested inside a batch — a region inside a region, which nothing downstream can draw yet.
 *
 * ## Entries are read literally or not at all
 *
 * `new A` and `A::class` are both written in practice, and both name a class outright. Anything
 * else — a variable, a factory call, a spread — is a job this cannot name, and a group that
 * quietly drops it would draw a boundary around a set that is missing a member. Those sites are
 * reported as {@see JobGroup::$unresolved} instead, which is the same answer the tracer already
 * gives for a dispatch it cannot resolve.
 */
final class JobGroups
{
    /** Receivers whose `chain()`/`batch()` is the framework's, rather than a domain method. */
    private const BUS_FACADES = ['Bus', 'Illuminate\\Support\\Facades\\Bus'];

    /** Verbs that dispatch the job they are given, so `->chain()` on them continues a sequence. */
    private const DISPATCH_VERBS = ['dispatch', 'dispatchSync', 'dispatchNow', 'dispatch_sync'];

    /**
     * The chain or batch this node is, or null when it is neither.
     */
    public static function at(Node $node): ?JobGroup
    {
        if ($node instanceof Node\Expr\StaticCall) {
            return self::atStaticCall($node);
        }

        if ($node instanceof Node\Expr\MethodCall) {
            return self::atMethodCall($node);
        }

        return null;
    }

    private static function atStaticCall(Node\Expr\StaticCall $node): ?JobGroup
    {
        if (! $node->class instanceof Node\Name || ! $node->name instanceof Node\Identifier) {
            return null;
        }

        $method = $node->name->toString();
        $class = $node->class->toString();

        if (in_array($class, self::BUS_FACADES, true) && in_array($method, ['chain', 'batch'], true)) {
            return self::fromArgument($node->args[0] ?? null, $method);
        }

        // `withChain` is only reachable through Laravel's Dispatchable trait, so the class it is
        // called on is a job by construction — no name check, which would only lose the jobs that
        // are not called `*Job` and do not live in a `Jobs\` namespace.
        if ($method === 'withChain') {
            $head = PhpFileParser::resolvedName($node->class) ?? $class;

            return self::fromArgument($node->args[0] ?? null, 'chain', $head, headDispatchesItself: false);
        }

        return null;
    }

    private static function atMethodCall(Node\Expr\MethodCall $node): ?JobGroup
    {
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'chain') {
            return null;
        }

        $head = self::headOfPendingDispatch($node->var);

        // `chain` is not a reserved word, and an array of `new` objects is exactly what a pipeline
        // builder or a middleware stack is given. Requiring the receiver to be a dispatch — the
        // only thing a framework chain can be appended to — is what keeps `$pipeline->chain([new
        // Step, new Step])` from being drawn as two queued jobs that run one after the other.
        // The cost is `$pending->chain([...])` through a variable, which reads as no chain at all.
        if ($head === null) {
            return null;
        }

        return self::fromArgument($node->args[0] ?? null, 'chain', $head, headDispatchesItself: true);
    }

    /**
     * The job a `->chain()` is being appended to, when the receiver dispatched one.
     *
     * A `$pending->chain([...])` held in a variable resolves to nothing, and that is the honest
     * answer: the chain's members are still known, only its first link is not, and a head guessed
     * from a variable name would put a job in the sequence that may not be in it.
     */
    private static function headOfPendingDispatch(Node $receiver): ?string
    {
        if ($receiver instanceof Node\Expr\FuncCall
            && $receiver->name instanceof Node\Name
            && in_array($receiver->name->toString(), self::DISPATCH_VERBS, true)) {
            $arg = $receiver->args[0] ?? null;

            return $arg instanceof Node\Arg ? self::className($arg->value) : null;
        }

        if ($receiver instanceof Node\Expr\StaticCall
            && $receiver->class instanceof Node\Name
            && $receiver->name instanceof Node\Identifier
            && in_array($receiver->name->toString(), self::DISPATCH_VERBS, true)) {
            // `Bus::dispatch(new A)` and `A::dispatch()` are the same verb written from opposite
            // ends: on the facade the job is the argument, on the job itself it is the receiver.
            // Reading the class both times would put the Bus facade at the head of the chain.
            if (in_array($receiver->class->toString(), self::BUS_FACADES, true)) {
                $arg = $receiver->args[0] ?? null;

                return $arg instanceof Node\Arg ? self::className($arg->value) : null;
            }

            return PhpFileParser::resolvedName($receiver->class) ?? $receiver->class->toString();
        }

        return null;
    }

    /**
     * Read the array literal a chain or a batch was given.
     *
     * A call with no argument at all is not a dispatch site — `Bus::batch()` on its own creates
     * nothing — so it produces no group rather than an empty one. An argument that is not an
     * array literal is a group whose members cannot be read, which is a different answer and is
     * reported as such.
     *
     * @param  'chain'|'batch'  $kind
     */
    private static function fromArgument(?Node $arg, string $kind, ?string $head = null, bool $headDispatchesItself = false): ?JobGroup
    {
        $value = $arg instanceof Node\Arg ? $arg->value : $arg;

        if ($value === null) {
            return null;
        }

        ['jobs' => $entries, 'unresolved' => $unresolved] = self::jobsInArray($arg);

        return new JobGroup($kind, $entries, $head, $headDispatchesItself, $unresolved);
    }

    /**
     * The jobs named by an array literal handed to a dispatch verb, in the order they were written.
     *
     * Public because both readers of a chain need it and there must be one answer to "what counts
     * as an entry": the region detector above, and the plain `Bus::chain([...])` handling in the
     * tracer that stands in for it when chain and batch regions are switched off. Two copies would
     * drift — one of them would learn about `A::class` and the other would not — and the drift
     * would show as a job that appears on the graph only while a display setting is on.
     *
     * @return array{jobs: list<string>, unresolved: bool}
     */
    public static function jobsInArray(?Node $arg): array
    {
        $value = $arg instanceof Node\Arg ? $arg->value : $arg;

        if ($value === null) {
            return ['jobs' => [], 'unresolved' => false];
        }

        if (! $value instanceof Node\Expr\Array_) {
            return ['jobs' => [], 'unresolved' => true];
        }

        $jobs = [];
        $unresolved = false;

        foreach ($value->items as $item) {
            $class = $item !== null ? self::className($item->value) : null;

            if ($class === null) {
                $unresolved = true;

                continue;
            }

            $jobs[] = $class;
        }

        return ['jobs' => $jobs, 'unresolved' => $unresolved];
    }

    /**
     * The class named by `new A(...)` or by `A::class`, as written or as the parser resolved it.
     */
    private static function className(Node $value): ?string
    {
        if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name) {
            return PhpFileParser::resolvedName($value->class) ?? $value->class->toString();
        }

        if ($value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && $value->name->toString() === 'class') {
            return PhpFileParser::resolvedName($value->class) ?? $value->class->toString();
        }

        return null;
    }
}
