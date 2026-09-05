<?php

use LaraMint\LaravelBrain\Mcp\Tools\GetRiskiestFilesTool;
use Laravel\Mcp\Server\Tool;

// GetRiskiestFilesTool extends Laravel\Mcp\Server\Tool. laravel/mcp is an optional
// require-dev dependency (dropped entirely on Laravel < 11 in CI, since it needs
// symfony/process ^7.4.5|^8.0.5, which conflicts with the symfony/process ^6.x that
// Laravel < 11 itself requires) — so autoloading GetRiskiestFilesTool here would fatal
// wherever it isn't installed. Checking the *parent* class, not GetRiskiestFilesTool
// itself, is the load-bearing part: class_exists() on GetRiskiestFilesTool would autoload
// it and hit the same fatal before this check could return false.
if (! class_exists(Tool::class)) {
    return;
}

it('returns the riskiestFiles entries from the manifest, up to the limit', function () {
    $manifest = [
        'riskiestFiles' => [
            ['file' => '/app/A.php', 'commitCount' => 10, 'lastChangedAt' => '2026-01-01', 'maxComplexity' => 9, 'riskScore' => 90],
            ['file' => '/app/B.php', 'commitCount' => 5, 'lastChangedAt' => '2026-01-01', 'maxComplexity' => 4, 'riskScore' => 20],
        ],
    ];

    $files = GetRiskiestFilesTool::topFiles($manifest, 1);

    expect($files)->toHaveCount(1)
        ->and($files[0]['file'])->toBe('/app/A.php');
});

it('returns an empty list when the manifest has no riskiestFiles key', function () {
    expect(GetRiskiestFilesTool::topFiles(['tabs' => []], 20))->toBe([]);
});

it('returns an empty list when riskiestFiles is not an array', function () {
    expect(GetRiskiestFilesTool::topFiles(['riskiestFiles' => 'not-an-array'], 20))->toBe([]);
});
