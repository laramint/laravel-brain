<?php

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\Node;

/**
 * @param  CallChainEdge[]  $edges
 */
function stampedNode(array $edges, string $fqcn): Node
{
    $graph = (new GraphBuilder)->build('test', [], new MiddlewareRegistry([], [], []), [], $edges, []);

    foreach ($graph->nodes() as $node) {
        if (($node->data['fqcn'] ?? null) === $fqcn) {
            return $node;
        }
    }

    throw new RuntimeException("no node for {$fqcn}");
}

function edgeInSpan(string $caller, string $span, bool $rollback): CallChainEdge
{
    return new CallChainEdge(
        callerFqcn: $caller,
        callerMethod: 'handle',
        calleeFqcn: 'App\\Services\\Ledger',
        calleeMethod: 'record',
        type: 'service',
        inTransaction: ! $rollback,
        inRollback: $rollback,
        transactionId: $span,
    );
}

/**
 * The kind this node carries for a given span, or null when it is not in that span at all.
 */
function regionKind(Node $node, string $spanId): ?string
{
    foreach ($node->data['regions'] ?? [] as $region) {
        if (($region['id'] ?? null) === $spanId) {
            return $region['kind'] ?? null;
        }
    }

    return null;
}

it('marks a node with the span it was reached from', function () {
    $node = stampedNode([edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false)], 'App\\Services\\Ledger');

    expect(regionKind($node, 'App\\A::handle#0'))->toBe('transaction')
        ->and($node->data['inTransaction'] ?? false)->toBeTrue();
});

it('does not dress a node in a rollback flag belonging to another span', function () {
    // The defect this pins: the span identity was recorded first-one-wins while the two flags
    // were set by any edge at all. A service called inside one transaction and again from a
    // catch block that rolls back a different one then carried the first span's id and the
    // second span's rollback marking — drawn inside a region it never rolled back.
    //
    // A node reached from two spans is now in BOTH, each wearing its own kind, which is what
    // makes the mispairing structurally impossible rather than merely fixed: there is no single
    // field left for one span's identity to share with another span's marking.
    $node = stampedNode([
        edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false),
        edgeInSpan('App\\B', 'App\\B::handle#0', rollback: true),
    ], 'App\\Services\\Ledger');

    expect(regionKind($node, 'App\\A::handle#0'))->toBe('transaction')
        ->and(regionKind($node, 'App\\B::handle#0'))->toBe('rollback');
});

it('reads the same whichever span reached the node first', function () {
    // The mirror case, so the rule cannot be satisfied by never reporting a rollback at all —
    // and, because the queue this models is not ordered, by asserting the result converges
    // rather than that one particular arrival wins.
    $node = stampedNode([
        edgeInSpan('App\\B', 'App\\B::handle#0', rollback: true),
        edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false),
    ], 'App\\Services\\Ledger');

    expect(regionKind($node, 'App\\A::handle#0'))->toBe('transaction')
        ->and(regionKind($node, 'App\\B::handle#0'))->toBe('rollback')
        ->and($node->data['inRollback'] ?? false)->toBeTrue();
});
