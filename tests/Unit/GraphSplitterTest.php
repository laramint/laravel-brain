<?php

use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\ModelDefinition;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphSplitter;
use LaraMint\LaravelBrain\Graph\Node;

// RouteDefinition is declared alongside RouteAnalyzer in RouteAnalyzer.php;
// reference RouteAnalyzer so PSR-4 autoloading pulls in that file.
class_exists(RouteAnalyzer::class);
// ModelDefinition likewise lives in ModelAnalyzer.php.
class_exists(ModelAnalyzer::class);

function splitterRouteNode(string $method, string $uri, ?array $security = null): Node
{
    $data = ['method' => $method, 'uri' => $uri];
    if ($security !== null) {
        $data['security'] = $security;
    }

    return new Node("route::{$method}::{$uri}", 'route', "{$method} {$uri}", $data);
}

function splitterRoute(string $method, string $uri, string $file): RouteDefinition
{
    return new RouteDefinition(
        method: $method,
        uri: $uri,
        controller: '',
        action: '',
        middlewares: [],
        name: '',
        file: $file,
        line: 1,
        tabGroup: "{$method} {$uri}",
    );
}

it('aggregates n+1, fat method and fat class issues from the lifecycle subgraph', function () {
    $graph = new Graph;
    $graph->addNode(splitterRouteNode('GET', '/reports', null));
    // Lifecycle node seeded via action::{controller}::{action}; carries structural issues.
    $graph->addNode(new Node(
        'action::App\\Http\\Controllers\\ReportController::index',
        'action',
        'ReportController@index',
        ['hasN1' => true, 'fatMethod' => true, 'fatClass' => true],
    ));

    $route = new RouteDefinition(
        method: 'GET',
        uri: '/reports',
        controller: 'App\\Http\\Controllers\\ReportController',
        action: 'index',
        middlewares: [],
        name: '',
        file: '/app/routes/api.php',
        line: 1,
        tabGroup: 'GET /reports',
    );

    $splitter = new GraphSplitter;
    $split = $splitter->split($graph, [$route], [], [], [], 'proj', '2026-05-16T00:00:00Z');
    $entry = $split['manifest'][0];

    expect($entry->issueCount)->toBe(3)
        ->and($entry->securityCount)->toBe(0)
        ->and($entry->n1Count)->toBe(1)
        ->and($entry->fatMethodCount)->toBe(1)
        ->and($entry->fatClassCount)->toBe(1)
        ->and($entry->riskLevel)->toBe('none');

    $json = $splitter->buildManifestJson($split['manifest'], $graph, 'proj', '2026-05-16T00:00:00Z', 1);
    $tab = json_decode($json, true)['tabs'][0];
    expect($tab['issueCount'])->toBe(3)
        ->and($tab['n1Count'])->toBe(1)
        ->and($tab['fatMethodCount'])->toBe(1)
        ->and($tab['fatClassCount'])->toBe(1);
});

it('aggregates route security issues into the manifest entry', function () {
    $graph = new Graph;
    $graph->addNode(splitterRouteNode('GET', '/password/forgot', [
        'exposure' => 'public',
        'riskLevel' => 'high',
        'issues' => [
            ['type' => 'MISSING_THROTTLE', 'severity' => 'high', 'message' => 'x', 'file' => null, 'line' => null],
        ],
    ]));
    $graph->addNode(splitterRouteNode('GET', '/safe', null));

    $routes = [
        splitterRoute('GET', '/password/forgot', '/app/routes/api.php'),
        splitterRoute('GET', '/safe', '/app/routes/api.php'),
    ];

    $result = (new GraphSplitter)->split($graph, $routes, [], [], [], 'proj', '2026-05-16T00:00:00Z');

    $byLabel = [];
    foreach ($result['manifest'] as $entry) {
        $byLabel[$entry->label] = $entry;
    }

    expect($byLabel['GET /password/forgot']->issueCount)->toBe(1)
        ->and($byLabel['GET /password/forgot']->riskLevel)->toBe('high')
        ->and($byLabel['GET /safe']->issueCount)->toBe(0)
        ->and($byLabel['GET /safe']->riskLevel)->toBe('none');
});

it('emits issueCount and riskLevel in the manifest JSON only when there are issues', function () {
    $graph = new Graph;
    $graph->addNode(splitterRouteNode('POST', '/login', [
        'exposure' => 'public',
        'riskLevel' => 'medium',
        'issues' => [
            ['type' => 'PUBLIC_WRITE', 'severity' => 'medium', 'message' => 'x', 'file' => null, 'line' => null],
            ['type' => 'MISSING_THROTTLE', 'severity' => 'medium', 'message' => 'y', 'file' => null, 'line' => null],
        ],
    ]));
    $graph->addNode(splitterRouteNode('GET', '/ping', null));

    $routes = [
        splitterRoute('POST', '/login', '/app/routes/api.php'),
        splitterRoute('GET', '/ping', '/app/routes/api.php'),
    ];

    $splitter = new GraphSplitter;
    $split = $splitter->split($graph, $routes, [], [], [], 'proj', '2026-05-16T00:00:00Z');
    $json = $splitter->buildManifestJson($split['manifest'], $graph, 'proj', '2026-05-16T00:00:00Z', count($routes));

    $decoded = json_decode($json, true);
    $tabs = [];
    foreach ($decoded['tabs'] as $tab) {
        $tabs[$tab['label']] = $tab;
    }

    expect($tabs['POST /login']['issueCount'])->toBe(2)
        ->and($tabs['POST /login']['riskLevel'])->toBe('medium')
        ->and($tabs['GET /ping'])->not->toHaveKey('issueCount')
        ->and($tabs['GET /ping'])->not->toHaveKey('riskLevel');
});

it('emits a subgraph in the full graph\'s node and edge order', function () {
    // A tab's subgraph is a filtered view of the full graph, and the order it emits nodes and
    // edges in is part of the file it produces. Reachability is discovered breadth-first, which
    // is not the order the nodes were added in, so extraction has to restore the original one.
    $graph = new Graph;
    $graph->addNode(splitterRouteNode('GET', '/orders'));
    foreach (['Zeta', 'Alpha', 'Mu'] as $name) {
        $graph->addNode(new Node("action::App\\Http\\Controllers\\{$name}Controller::index", 'action', $name));
    }
    // An unreachable node in the middle, to prove filtering still happens.
    $graph->addNode(new Node('action::App\\Http\\Controllers\\OtherController::index', 'action', 'Other'));

    $edges = [
        ['route::GET::/orders', 'action::App\\Http\\Controllers\\ZetaController::index'],
        ['action::App\\Http\\Controllers\\ZetaController::index', 'action::App\\Http\\Controllers\\AlphaController::index'],
        ['action::App\\Http\\Controllers\\AlphaController::index', 'action::App\\Http\\Controllers\\MuController::index'],
    ];
    foreach ($edges as $i => [$from, $to]) {
        $graph->addEdge(new Edge("e{$i}", $from, $to, 'calls', 'flow'));
    }

    $split = (new GraphSplitter)->split(
        $graph,
        [splitterRoute('GET', '/orders', '/app/routes/web.php')],
        [], [], [],
        'proj',
        '2026-05-16T00:00:00Z',
    );

    $sub = $split['subgraphs'][array_key_first($split['subgraphs'])];

    $fullOrder = array_map(fn ($n) => $n->id, $graph->nodes());
    $subOrder = array_map(fn ($n) => $n->id, $sub->nodes());

    expect($subOrder)->toBe(array_values(array_filter($fullOrder, fn ($id) => in_array($id, $subOrder, true))))
        ->and($subOrder)->not->toContain('action::App\Http\Controllers\OtherController::index')
        ->and(array_map(fn ($e) => $e->id, $sub->edges()))->toBe(['e0', 'e1', 'e2']);
});

it('lays the ERD out in the order the models are given', function () {
    // Pins why ProjectAnalyzer sorts before calling this: the tab is built in iteration order,
    // so an unsorted list makes the diagram reshuffle whenever tracing reaches models in a
    // different order — which a scoped rescan, tracing fewer controllers, does every time.
    $model = fn (string $fqcn) => new ModelDefinition($fqcn, '/tmp/'.$fqcn.'.php', [], []);

    $ordered = ['App\Models\Alpha' => $model('App\Models\Alpha'), 'App\Models\Beta' => $model('App\Models\Beta')];
    $reversed = array_reverse($ordered, preserve_keys: true);

    $ids = function (array $models): array {
        $tab = (new GraphSplitter)->buildErdTab($models, 'proj', '2026-01-01T00:00:00Z');

        return array_map(fn ($n) => $n->id, $tab['graph']->nodes());
    };

    expect($ids($ordered))->not->toBe($ids($reversed));
});

it('puts the morph alias on the ERD node beside the other model facts', function () {
    // The alias is what a `*_type` column holds, so it is the string somebody arrives with when
    // they are trying to find out which model a row points at.
    $def = new ModelDefinition('App\Models\Parcel', '/tmp/Parcel.php', [], [], morphAlias: 'parcel');
    $tab = (new GraphSplitter)->buildErdTab(['App\Models\Parcel' => $def], 'proj', '2026-01-01T00:00:00Z');

    $erd = $tab['graph']->nodes()[0]->data['erd'];

    expect($erd['morphAlias'])->toBe('parcel')
        ->and($erd['morphAliasMissing'])->toBeFalse();
});

it('carries the missing-alias verdict onto the ERD node', function () {
    $def = new ModelDefinition('App\Models\Parcel', '/tmp/Parcel.php', [], [], morphAliasMissing: true);
    $tab = (new GraphSplitter)->buildErdTab(['App\Models\Parcel' => $def], 'proj', '2026-01-01T00:00:00Z');

    $erd = $tab['graph']->nodes()[0]->data['erd'];

    expect($erd['morphAlias'])->toBeNull()
        ->and($erd['morphAliasMissing'])->toBeTrue();
});
