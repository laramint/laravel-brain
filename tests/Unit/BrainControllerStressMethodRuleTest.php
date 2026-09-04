<?php

use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;

/** Runs the real rule through the real validator, without booting an application. */
function stressMethodPasses(string $method): bool
{
    $translator = new Translator(new ArrayLoader, 'en');

    return (new Validator(
        $translator,
        ['method' => $method],
        ['method' => BrainController::stressMethodRule()],
    ))->passes();
}

it('accepts every verb the analyzer can put in the sidebar', function () {
    foreach (RouteAnalyzer::HTTP_VERBS as $verb) {
        expect(stressMethodPasses($verb))->toBeTrue("{$verb} should be runnable from the stress panel");
    }
});

it('accepts OPTIONS and QUERY, which the panel could not fire before', function () {
    expect(stressMethodPasses('OPTIONS'))->toBeTrue()
        ->and(stressMethodPasses('QUERY'))->toBeTrue();
});

it('still accepts HEAD, which the panel offers but the graph never shows', function () {
    expect(stressMethodPasses('HEAD'))->toBeTrue();
});

it('rejects a verb that is not one of them', function () {
    // The allow-list is the only thing standing between this endpoint and an arbitrary method
    // being sent at the host, so a widened list must stay a list.
    expect(stressMethodPasses('TRACE'))->toBeFalse()
        ->and(stressMethodPasses(''))->toBeFalse();
});
