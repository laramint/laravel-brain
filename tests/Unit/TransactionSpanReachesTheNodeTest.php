<?php

use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\GraphBuilder;

/**
 * @filamerce-covers \LaraMint\LaravelBrain\Analysis\MethodTracer
 */

/**
 * The whole seam, end to end: a controller method opens a transaction, the tracer turns its hops
 * into edges, and the builder stamps the result onto nodes.
 *
 * Written because the unit tests either side of it both passed while the middle was broken. The
 * scope detector knew which statements were in the span, and the stamping test fed edges it
 * built by hand — so `trace()` could drop the span identity between them and nothing went red.
 *
 * @return array{edges: list<object>, nodes: array<string, array<string, mixed>>}
 */
function tracedTransactionProject(): array
{
    $project = fixture('transaction-controller');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = new ControllerAnalyzer;
    $definitions = $controllers->analyze($project, $routes);

    $tracer = new MethodTracer;
    $psr4 = $controllers->getPsr4Map();

    $edges = $tracer->trace($definitions, $psr4, $project);

    // Closure routes are traced through a second entry point, wired here exactly as
    // ProjectAnalyzer wires it — the fixture has one so that path is covered too, since it
    // builds its edges at a third call site with its own copy of the argument list.
    foreach ($routes as $route) {
        if ($route->closureNode === null) {
            continue;
        }

        foreach ($tracer->traceClosure(
            $route->closureNode,
            $route->closureUseMap ?? [],
            "route::{$route->method}::{$route->uri}",
            $psr4,
            $project,
        ) as $edge) {
            $edges[] = $edge;
        }
    }

    $graph = (new GraphBuilder)->build(
        'test',
        $routes,
        new MiddlewareRegistry([], [], []),
        $definitions,
        $edges,
        [],
        $project,
    );

    $nodes = [];

    foreach ($graph->nodes() as $node) {
        $nodes[$node->id] = $node->data;
    }

    return ['edges' => $edges, 'nodes' => $nodes];
}

/** The traced edge into the named callee method. */
function edgeInto(array $edges, string $method): object
{
    foreach ($edges as $edge) {
        if ($edge->calleeMethod === $method && $edge->calleeFqcn === 'App\\Services\\Ledger') {
            return $edge;
        }
    }

    throw new RuntimeException("no traced edge into Ledger::{$method}()");
}

it('carries the span identity from a controller hop onto its edge', function () {
    // `trace()` is the controller path, and it is the one that was dropping the argument. The
    // flag without the id is the failure this pins: a node marked as running inside a transaction
    // but with no idea which one, so a region can never be drawn around it.
    ['edges' => $edges] = tracedTransactionProject();

    $inside = edgeInto($edges, 'record');

    expect($inside->inTransaction)->toBeTrue()
        ->and($inside->transactionId)->not->toBeNull();
});

it('leaves a hop outside the transaction with neither the flag nor an id', function () {
    // The negative control, so the rule cannot be satisfied by stamping every hop.
    ['edges' => $edges] = tracedTransactionProject();

    $outside = edgeInto($edges, 'audit');

    expect($outside->inTransaction)->toBeFalse()
        ->and($outside->transactionId)->toBeNull();
});

it('stamps that identity onto the node the builder makes', function () {
    // The far end of the seam. A span identity that reaches the edge and stops there would still
    // leave the canvas unable to group anything.
    ['nodes' => $nodes] = tracedTransactionProject();

    $marked = array_filter(
        $nodes,
        fn (array $data): bool => ! empty($data['inTransaction']) || ! empty($data['inRollback']),
    );

    expect($marked)->not->toBeEmpty();

    foreach ($marked as $id => $data) {
        expect($data['transactionId'] ?? null)
            ->not->toBeNull("node {$id} is marked as running in a transaction but names no span");
    }
});

it('reads no spans at all when the detector is switched off', function () {
    // Off means the traversal never happens, not that its result is dropped: an application with
    // no transactions was paying a walk of every method body to be told it has none.
    $project = fixture('transaction-controller');
    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = new ControllerAnalyzer;
    $definitions = $controllers->analyze($project, $routes);

    $edges = (new MethodTracer([], SourceDirectories::DEFAULT_SOURCE_PATHS, false))
        ->trace($definitions, $controllers->getPsr4Map(), $project);

    $marked = array_filter($edges, static fn (object $edge): bool => $edge->inTransaction || $edge->inRollback);

    // The chain is still traced — turning the boundary off must not take the work it drew around
    // off the graph with it.
    expect($edges)->not->toBeEmpty()
        ->and($marked)->toBeEmpty();
});
