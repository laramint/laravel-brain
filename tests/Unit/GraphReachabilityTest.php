<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Graph\Edge;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\GraphSplitter;
use LaraMint\LaravelBrain\Graph\Node;

function reachabilityGraph(): Graph
{
    $graph = new Graph;
    $graph->addNode(new Node('route::GET::/x', 'route', 'GET /x'));
    $graph->addNode(new Node('action::A::index', 'action', 'index'));
    $graph->addNode(new Node('ai_agent::A', 'ai_agent', 'A'));
    $graph->addNode(new Node('ai_tool::T', 'ai_tool', 'T'));
    $graph->addNode(new Node('ai_tool::Lonely', 'ai_tool', 'Lonely'));
    $graph->addNode(new Node('service::S::run', 'service', 'S@run'));

    $graph->addEdge(new Edge('e1', 'route::GET::/x', 'action::A::index', 'handles', 'route-to-action'));
    // A wired cluster that no route reaches: edges exist, reachability does not.
    $graph->addEdge(new Edge('e2', 'service::S::run', 'ai_agent::A', 'prompts', 'ai-caller-to-agent'));
    $graph->addEdge(new Edge('e3', 'ai_agent::A', 'ai_tool::T', 'may call', 'ai-agent-to-tool'));

    return $graph;
}

it('counts only the nodes no edge touches', function () {
    expect(reachabilityGraph()->isolatedNodeCountsByType())->toBe(['ai_tool' => 1]);
});

it('reports no isolated nodes for a fully wired graph', function () {
    $graph = new Graph;
    $graph->addNode(new Node('a', 'service', 'a'));
    $graph->addNode(new Node('b', 'service', 'b'));
    $graph->addEdge(new Edge('e', 'a', 'b', 'calls', 'call'));

    expect($graph->isolatedNodeCountsByType())->toBe([]);
});

it('counts wired-but-unreachable nodes as outside the tabs', function () {
    $full = reachabilityGraph();

    $tab = new Graph;
    $tab->addNode(new Node('route::GET::/x', 'route', 'GET /x'));
    $tab->addNode(new Node('action::A::index', 'action', 'index'));

    // The agent and its tool have edges, so isolation says nothing is wrong — yet nobody can see
    // them. That difference is why both numbers are reported.
    expect(GraphSplitter::nodesOutsideTabs($full, ['tab' => $tab]))
        ->toBe(['ai_tool' => 2, 'ai_agent' => 1, 'service' => 1]);
});

it('reports nothing outside the tabs when every node is shown', function () {
    $full = reachabilityGraph();

    expect(GraphSplitter::nodesOutsideTabs($full, ['everything' => $full]))->toBe([]);
});
