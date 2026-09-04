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

it('marks a node with the span it was reached from', function () {
    $node = stampedNode([edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false)], 'App\\Services\\Ledger');

    expect($node->data['transactionId'])->toBe('App\\A::handle#0')
        ->and($node->data['inTransaction'] ?? false)->toBeTrue();
});

it('does not dress a node in a rollback flag belonging to another span', function () {
    // The defect this pins: the span identity was recorded first-one-wins while the two flags
    // were set by any edge at all. A service called inside one transaction and again from a
    // catch block that rolls back a different one then carried the first span's id and the
    // second span's rollback marking — drawn inside a region it never rolled back.
    $node = stampedNode([
        edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false),
        edgeInSpan('App\\B', 'App\\B::handle#0', rollback: true),
    ], 'App\\Services\\Ledger');

    expect($node->data['transactionId'])->toBe('App\\A::handle#0')
        ->and($node->data['inRollback'] ?? false)->toBeFalse();
});

it('keeps the flags of the span it did bind to, whichever came first', function () {
    // The mirror case, so the rule cannot be satisfied by never reporting a rollback at all.
    $node = stampedNode([
        edgeInSpan('App\\B', 'App\\B::handle#0', rollback: true),
        edgeInSpan('App\\A', 'App\\A::handle#0', rollback: false),
    ], 'App\\Services\\Ledger');

    expect($node->data['transactionId'])->toBe('App\\B::handle#0')
        ->and($node->data['inRollback'] ?? false)->toBeTrue();
});
