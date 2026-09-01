<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\ActionClasses;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\ModelAnalyzer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\SourceDirectories;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\Node;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

/**
 * @param  string[]|null  $actionPaths  null leaves the builder's default in place
 */
function actionGraph(?array $actionPaths = null): Graph
{
    $project = fixture('actions-project');
    $psr4 = ['App\\' => [$project.'/app']];

    $routes = (new RouteAnalyzer)->analyze($project);
    $controllers = (new ControllerAnalyzer)->analyze($project, $routes);
    $traces = (new MethodTracer)->trace($controllers, $psr4, $project);
    $modelFqcns = array_map(
        fn ($t) => $t->calleeFqcn,
        array_filter($traces, fn ($t) => $t->type === 'model')
    );
    $models = (new ModelAnalyzer)->analyze($project, $modelFqcns);

    $builder = new GraphBuilder;
    if ($actionPaths !== null) {
        $builder->setActionPaths($actionPaths);
    }

    return $builder->build(
        'actions',
        $routes,
        new MiddlewareRegistry([], [], []),
        $controllers,
        $traces,
        $models,
        $project,
    );
}

/** @return array<string, int> node type => count */
function typeCounts(Graph $graph): array
{
    $counts = [];
    foreach ($graph->nodes() as $node) {
        $counts[$node->type] = ($counts[$node->type] ?? 0) + 1;
    }

    return $counts;
}

function actionClassNode(Graph $graph, string $shortName): ?Node
{
    foreach ($graph->nodes() as $node) {
        if ($node->type === 'action_class' && str_starts_with($node->label, $shortName.'@')) {
            return $node;
        }
    }

    return null;
}

beforeEach(function () {
    SourceDirectories::clear();
});

it('classifies a class under Actions/ as its own kind rather than a service', function () {
    $counts = typeCounts(actionGraph());

    expect($counts['action_class'] ?? 0)->toBe(4);
});

it('leaves the controller-action kind alone', function () {
    // 'action' is the graph's existing name for a controller action, and this feature must not
    // touch it. Both arms are asserted so a regression that renamed or absorbed controller
    // actions cannot hide behind the new type appearing.
    $withActions = typeCounts(actionGraph());
    $withoutActions = typeCounts(actionGraph([]));

    expect($withActions['action'] ?? 0)->toBe(4)
        ->and($withoutActions['action'] ?? 0)->toBe(4);
});

it('only ever takes nodes that would otherwise be plain services', function () {
    $on = typeCounts(actionGraph());
    $off = typeCounts(actionGraph([]));

    $gained = $on['action_class'] ?? 0;
    $servicesLost = ($off['service'] ?? 0) - ($on['service'] ?? 0);

    // 'service' loses exactly what 'action_class' gains, and every other type is identical:
    // no other kind is reclassified, and no node is invented.
    unset($on['action_class'], $on['service'], $off['service']);

    expect($servicesLost)->toBe($gained)
        ->and($gained)->toBeGreaterThan(0)
        ->and($on)->toBe($off);
});

it('finds action classes in nested subdirectories', function () {
    // App\Actions\Orders\RefundOrder — the roots are scanned recursively, not one level deep.
    expect(actionClassNode(actionGraph(), 'RefundOrder'))->not->toBeNull();
});

it('leaves a class outside the action roots as a service', function () {
    $graph = actionGraph();

    $pricing = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => str_starts_with($n->label, 'OrderPricing@')
    ));

    expect($pricing)->not->toBeEmpty()
        ->and($pricing[0]->type)->toBe('service');
});

it('keeps a job that happens to live under Actions/', function () {
    $graph = actionGraph();

    $job = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => str_starts_with($n->label, 'SendInvoiceJob@')
    ));

    expect($job)->not->toBeEmpty()
        ->and($job[0]->type)->toBe('job');
});

it('keeps a form request that happens to live under Actions/', function () {
    $graph = actionGraph();

    $request = array_values(array_filter(
        $graph->nodes(),
        fn ($n) => str_starts_with($n->label, 'StoreOrderRequest@')
    ));

    expect($request)->not->toBeEmpty()
        ->and($request[0]->type)->toBe('validation_request');
});

it('turns the kind off entirely when the configured paths are empty', function () {
    // Unlike source/view paths, an empty array is honoured rather than replaced by the
    // default — an application without the pattern must be able to say so.
    expect(typeCounts(actionGraph([]))['action_class'] ?? 0)->toBe(0);
});

it('surfaces the single entry method the class is invoked through', function (string $shortName, string $entryMethod) {
    $node = actionClassNode(actionGraph(), $shortName);

    expect($node)->not->toBeNull()
        ->and($node->data['entryMethod'] ?? null)->toBe($entryMethod);
})->with([
    ['CreateOrder', 'handle'],
    ['ShipOrder', 'execute'],
    ['RefundOrder', '__invoke'],
]);

it('claims no entry method when the class declares more than one', function () {
    $node = actionClassNode(actionGraph(), 'ArchiveOrder');

    expect($node)->not->toBeNull()
        ->and($node->data)->not->toHaveKey('entryMethod');
});

it('labels the edge that invokes an action class', function () {
    // "What it is invoked by" is the other half of what makes an action class legible, and
    // the graph already carries it as an incoming edge — this only makes the verb say so.
    $graph = actionGraph();
    $target = actionClassNode($graph, 'CreateOrder');

    $incoming = array_values(array_filter(
        $graph->edges(),
        fn ($e) => $e->target === $target->id
    ));

    expect($incoming)->not->toBeEmpty()
        ->and($incoming[0]->label)->toBe('runs')
        ->and($incoming[0]->source)->toContain('OrderController');
});

describe('ActionClasses', function () {
    it('expands a glob pattern so packages can hold action classes', function () {
        $project = fixture('actions-project');
        $actions = new ActionClasses($project, ['packages/*/src/Actions']);

        expect($actions->isActionClass($project.'/packages/billing/src/Actions/ChargeCard.php'))->toBeTrue()
            ->and($actions->entryMethod($project.'/packages/billing/src/Actions/ChargeCard.php'))->toBe('__invoke')
            ->and($actions->isActionClass($project.'/app/Actions/CreateOrder.php'))->toBeFalse();
    });

    it('anchors containment at the project root instead of matching a substring', function () {
        $project = fixture('actions-project');
        $actions = new ActionClasses($project, ['app/Actions']);

        // Same trailing path, different project. A substring test on "/app/Actions/" would
        // claim it, and every file of any checkout living under such a directory with it.
        expect($actions->isActionClass('/somewhere/else/app/Actions/CreateOrder.php'))->toBeFalse();
    });

    it('does not count a non-public method as a way in', function () {
        $project = fixture('actions-project');
        $actions = new ActionClasses($project, ['app/Actions']);

        // RefundOrder declares a protected handle() alongside its public __invoke().
        expect($actions->entryMethod($project.'/app/Actions/Orders/RefundOrder.php'))->toBe('__invoke');
    });

    it('answers nothing for a file outside the action roots', function () {
        $project = fixture('actions-project');
        $actions = new ActionClasses($project, ['app/Actions']);

        expect($actions->isActionClass($project.'/app/Services/OrderPricing.php'))->toBeFalse()
            ->and($actions->entryMethod($project.'/app/Services/OrderPricing.php'))->toBeNull();
    });
});

describe('config wiring', function () {
    /**
     * The graph a full scan produces with the given laravel-brain config, so the config KEY
     * itself is under test. Everything above drives GraphBuilder directly and would keep
     * passing if `laravel-brain.actions.paths` were misspelled in ProjectAnalyzer.
     *
     * @param  array<string, mixed>  $brainConfig
     * @return array<string, int> node type => count
     */
    function scannedTypeCounts(array $brainConfig): array
    {
        PhpFileParser::clearSharedCache();
        SourceDirectories::clear();

        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'app' => ['name' => 'ActionClassesTest'],
            'laravel-brain' => $brainConfig,
        ]));

        $graph = (new ProjectAnalyzer)->analyze(fixture('actions-project'), function (): void {})->fullGraph;

        Container::setInstance(null);

        return typeCounts($graph);
    }

    it('reads the action roots from laravel-brain.actions.paths', function () {
        $counts = scannedTypeCounts(['actions' => ['paths' => ['app/Actions']]]);

        expect($counts['action_class'] ?? 0)->toBeGreaterThan(0);
    });

    it('produces no action classes when the configured roots hold nothing eligible', function () {
        // Pointed at app/Models: the config IS read (Actions/ no longer counts), and the models
        // now inside the roots keep being models rather than becoming action classes.
        $counts = scannedTypeCounts(['actions' => ['paths' => ['app/Models']]]);

        expect($counts['action_class'] ?? 0)->toBe(0)
            ->and($counts['model'] ?? 0)->toBeGreaterThan(0)
            ->and($counts['action'] ?? 0)->toBeGreaterThan(0);
    });
});
