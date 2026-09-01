<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\AiAnalyzer;
use LaraMint\LaravelBrain\Analysis\ControllerAnalyzer;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphBuilder;
use LaraMint\LaravelBrain\Graph\GraphSplitter;

/**
 * The graph the AI pass produces on top of a normally-built one, so the caller edges have real
 * controller and job nodes to attach to.
 */
function aiGraph(): Graph
{
    $root = fixture('laravel-ai-project');

    $routes = (new RouteAnalyzer)->analyze($root);
    $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
    $traces = (new MethodTracer)->trace($controllers, [], $root);

    $builder = new GraphBuilder;
    $builder->setSourcePaths(['app']);
    $graph = $builder->build('ai-test', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, [], $root);

    $ai = (new AiAnalyzer)->analyze($root);
    $builder->addAi($ai['agents'], $ai['tools'], $ai['callSites']);

    return $graph;
}

/**
 * @return list<string> "source -> target" for every edge of the given type
 */
function aiEdges(Graph $graph, string $type): array
{
    $out = [];

    foreach ($graph->edges() as $edge) {
        if ($edge->type === $type) {
            $out[] = $edge->source.' -> '.$edge->target;
        }
    }

    return $out;
}

it('adds a node per agent and per tool', function () {
    $graph = aiGraph();

    expect($graph->hasNode('ai_agent::App\\Ai\\Agents\\SupportAgent'))->toBeTrue();
    expect($graph->hasNode('ai_tool::App\\Ai\\Tools\\SearchOrdersTool'))->toBeTrue();
    expect($graph->hasNode('ai_tool::App\\Mcp\\Tools\\InventoryTool'))->toBeTrue();

    $agent = $graph->getNode('ai_agent::App\\Ai\\Agents\\SupportAgent');
    expect($agent?->type)->toBe('ai_agent');
    expect($agent?->label)->toBe('SupportAgent');
});

it('carries the model decision onto the agent node', function () {
    $graph = aiGraph();

    expect($graph->getNode('ai_agent::App\\Ai\\Agents\\TranslationAgent')?->data)
        ->toHaveKey('model', 'gpt-4o-mini')
        ->toHaveKey('modelSource', 'attribute')
        ->toHaveKey('provider', 'openai');

    // A tier is not a model id, so no `model` key is written at all.
    expect($graph->getNode('ai_agent::App\\Ai\\Agents\\SupportAgent')?->data)
        ->toHaveKey('modelTier', 'smartest')
        ->toHaveKey('maxSteps', 12)
        ->toHaveKey('strict', true)
        ->not->toHaveKey('model');
});

it('wires each agent to the tools it declares', function () {
    $edges = aiEdges(aiGraph(), 'ai-agent-to-tool');

    expect($edges)
        ->toContain('ai_agent::App\\Ai\\Agents\\SupportAgent -> ai_tool::App\\Ai\\Tools\\SearchOrdersTool')
        ->toContain('ai_agent::App\\Ai\\Agents\\SupportAgent -> ai_tool::App\\Ai\\Tools\\RefundTool')
        ->toContain('ai_agent::App\\Ai\\Agents\\SupportAgent -> ai_tool::App\\Mcp\\Tools\\InventoryTool');
});

it('wires an agent used as a tool as a delegation', function () {
    $edges = aiEdges(aiGraph(), 'ai-agent-to-agent');

    expect($edges)->toBe([
        'ai_agent::App\\Ai\\Agents\\RouterAgent -> ai_agent::App\\Ai\\Agents\\TranslationAgent',
    ]);
});

it('does not wire tools the model will never be offered', function () {
    $graph = aiGraph();

    // DraftAgent declares tools() but not HasTools, so resolveTools() returns early.
    expect(aiEdges($graph, 'ai-agent-to-tool'))
        ->not->toContain('ai_agent::App\\Ai\\Agents\\DraftAgent -> ai_tool::App\\Ai\\Tools\\RefundTool');

    expect($graph->getNode('ai_agent::App\\Ai\\Agents\\DraftAgent')?->data)
        ->toHaveKey('unwiredTools', ['App\\Ai\\Tools\\RefundTool']);
});

it('wires the controller action that prompts an agent', function () {
    $edges = aiEdges(aiGraph(), 'ai-caller-to-agent');

    expect($edges)->toContain(
        'action::App\\Http\\Controllers\\SupportController::ask -> ai_agent::App\\Ai\\Agents\\SupportAgent'
    );
});

it('leaves the graph untouched when the feature is switched off', function () {
    $root = fixture('laravel-ai-project');

    $routes = (new RouteAnalyzer)->analyze($root);
    $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
    $traces = (new MethodTracer)->trace($controllers, [], $root);

    $builder = new GraphBuilder;
    $builder->setSourcePaths(['app']);
    $graph = $builder->build('ai-off', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, [], $root);
    $before = $graph->nodeCount();

    $ai = (new AiAnalyzer(['app'], enabled: false))->analyze($root);
    $builder->addAi($ai['agents'], $ai['tools'], $ai['callSites']);

    expect($graph->nodeCount())->toBe($before);
    expect(array_filter($graph->nodes(), fn ($n): bool => str_starts_with($n->type, 'ai_')))->toBe([]);
});

it('leaves the graph untouched for a project that does not use the SDK', function () {
    $root = fixture('laravel-project');

    $routes = (new RouteAnalyzer)->analyze($root);
    $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
    $traces = (new MethodTracer)->trace($controllers, [], $root);

    $builder = new GraphBuilder;
    $graph = $builder->build('no-ai', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, [], $root);
    $before = $graph->nodeCount();

    $ai = (new AiAnalyzer)->analyze($root);
    expect($ai['detected'])->toBeFalse();

    $builder->addAi($ai['agents'], $ai['tools'], $ai['callSites']);

    expect($graph->nodeCount())->toBe($before);
    expect(array_filter($graph->nodes(), fn ($n): bool => str_starts_with($n->type, 'ai_')))->toBe([]);
});

it('creates the caller node when no other pass reached that class', function () {
    $graph = aiGraph();

    // AnswerTicketJob is dispatched from nowhere the tracer walks, so before the AI pass runs
    // there is no node for it — and without one the caller edge was silently dropped.
    $callerId = 'app_jobs_answerticketjob::handle';

    expect($graph->hasNode($callerId))->toBeTrue();
    expect($graph->getNode($callerId)?->type)->toBe('job');
    expect(aiEdges($graph, 'ai-caller-to-agent'))
        ->toContain($callerId.' -> ai_agent::App\\Ai\\Agents\\InjectedToolsAgent');
});

it('wires tools supplied at construction, labelled apart from declared ones', function () {
    $graph = aiGraph();

    $supplied = [];
    foreach ($graph->edges() as $edge) {
        if ($edge->type === 'ai-agent-to-tool' && $edge->label === 'may call (supplied)') {
            $supplied[] = $edge->source.' -> '.$edge->target;
        }
    }

    expect($supplied)
        ->toContain('ai_agent::App\\Ai\\Agents\\InjectedToolsAgent -> ai_tool::App\\Ai\\Tools\\SearchOrdersTool')
        ->toContain('ai_agent::App\\Ai\\Agents\\InjectedToolsAgent -> ai_tool::App\\Ai\\Tools\\RefundTool');
});

it('says on the node that tools are decided at runtime', function () {
    $graph = aiGraph();

    expect($graph->getNode('ai_agent::App\\Ai\\Agents\\InjectedToolsAgent')?->data)
        ->toHaveKey('toolsDecidedAtRuntime', true)
        ->toHaveKey('injectedTools');

    expect($graph->getNode('ai_agent::App\\Ai\\Agents\\SupportAgent')?->data)
        ->not->toHaveKey('toolsDecidedAtRuntime');
});

it('leaves no tool and no prompted agent without an edge', function () {
    $graph = aiGraph();

    $isolated = [];
    foreach ($graph->nodes() as $node) {
        $isolated[$node->id] = str_starts_with($node->type, 'ai_');
    }
    foreach ($graph->edges() as $edge) {
        $isolated[$edge->source] = false;
        $isolated[$edge->target] = false;
    }
    $stranded = array_keys(array_filter($isolated));

    expect($graph->isolatedNodeCountsByType())->not->toHaveKey('ai_tool');

    // ClassifierAgent and DraftAgent are prompted from nowhere and wire no tools, so they really
    // are on their own — an agent no code path reaches is a fact about the application, not a
    // wiring bug, and the AI tab is what keeps them visible anyway.
    expect($stranded)->toBe([
        'ai_agent::App\\Ai\\Agents\\DraftAgent',
        'ai_agent::App\\Ai\\Agents\\ClassifierAgent',
    ]);
});

it('puts every AI node in the AI Agents tab', function () {
    $graph = aiGraph();

    $tab = (new GraphSplitter)->buildAiTab($graph, 'ai-test', '2026-01-01T00:00:00Z');

    expect($tab)->not->toBeNull();
    expect($tab['manifest']->category)->toBe('AI');
    expect($tab['manifest']->label)->toBe('AI Agents');

    $aiNodeIds = [];
    foreach ($graph->nodes() as $node) {
        if (str_starts_with($node->type, 'ai_')) {
            $aiNodeIds[] = $node->id;
        }
    }

    expect($aiNodeIds)->not->toBeEmpty();
    foreach ($aiNodeIds as $id) {
        expect($tab['graph']->hasNode($id))->toBeTrue();
    }

    // And the callers come with them, so the tab says where the calls come from.
    expect($tab['graph']->hasNode('app_jobs_answerticketjob::handle'))->toBeTrue();
});

it('builds no AI tab for a project without agents', function () {
    $root = fixture('laravel-project');

    $routes = (new RouteAnalyzer)->analyze($root);
    $controllers = (new ControllerAnalyzer)->analyze($root, $routes);
    $traces = (new MethodTracer)->trace($controllers, [], $root);

    $graph = (new GraphBuilder)->build('no-ai', $routes, new MiddlewareRegistry([], [], []), $controllers, $traces, [], $root);

    expect((new GraphSplitter)->buildAiTab($graph, 'no-ai', '2026-01-01T00:00:00Z'))->toBeNull();
});
