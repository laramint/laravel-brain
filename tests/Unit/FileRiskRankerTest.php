<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\FileRiskRanker;
use LaraMint\LaravelBrain\Graph\Graph;
use LaraMint\LaravelBrain\Graph\Node;

function riskRankerNode(string $id, string $file, ?int $cyclomaticComplexity): Node
{
    $data = ['file' => $file];
    if ($cyclomaticComplexity !== null) {
        $data['metrics'] = ['cyclomaticComplexity' => $cyclomaticComplexity];
    }

    return new Node($id, 'action', $id, $data);
}

it('scores a file by its most complex method, not the sum or average', function () {
    $graph = new Graph;
    $graph->addNode(riskRankerNode('a', '/app/Controller.php', 3));
    $graph->addNode(riskRankerNode('b', '/app/Controller.php', 9));

    $ranked = (new FileRiskRanker)->apply($graph, [
        '/app/Controller.php' => ['commitCount' => 10, 'lastChangedAt' => '2026-01-01'],
    ], 50);

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]['maxComplexity'])->toBe(9)
        ->and($ranked[0]['commitCount'])->toBe(10)
        ->and($ranked[0]['riskScore'])->toBe(90);
});

it('stamps churn on every node in a file even when the file is excluded from the ranking', function () {
    $graph = new Graph;
    $graph->addNode(riskRankerNode('m', '/app/Model.php', null)); // no complexity metric at all

    $ranked = (new FileRiskRanker)->apply($graph, [
        '/app/Model.php' => ['commitCount' => 5, 'lastChangedAt' => '2026-01-01'],
    ], 50);

    expect($ranked)->toBe([])
        ->and($graph->getNode('m')->data['churn'])->toBe(['commitCount' => 5, 'lastChangedAt' => '2026-01-01']);
});

it('does not stamp or rank a file with no churn data', function () {
    $graph = new Graph;
    $graph->addNode(riskRankerNode('a', '/app/Untouched.php', 20));

    $ranked = (new FileRiskRanker)->apply($graph, [], 50);

    expect($ranked)->toBe([])
        ->and($graph->getNode('a')->data)->not->toHaveKey('churn');
});

it('sorts descending by riskScore and truncates to the limit', function () {
    $graph = new Graph;
    $graph->addNode(riskRankerNode('low', '/app/Low.php', 2));
    $graph->addNode(riskRankerNode('high', '/app/High.php', 10));
    $graph->addNode(riskRankerNode('mid', '/app/Mid.php', 5));

    $churn = [
        '/app/Low.php' => ['commitCount' => 1, 'lastChangedAt' => '2026-01-01'],
        '/app/High.php' => ['commitCount' => 20, 'lastChangedAt' => '2026-01-01'],
        '/app/Mid.php' => ['commitCount' => 5, 'lastChangedAt' => '2026-01-01'],
    ];

    $ranked = (new FileRiskRanker)->apply($graph, $churn, 2);

    expect($ranked)->toHaveCount(2)
        ->and($ranked[0]['file'])->toBe('/app/High.php')
        ->and($ranked[1]['file'])->toBe('/app/Mid.php');
});
