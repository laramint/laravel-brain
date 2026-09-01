<?php

use LaraMint\LaravelBrain\Analysis\CallChainEdge;
use LaraMint\LaravelBrain\Analysis\EventAnalyzer;

function analyzeEvents(): array
{
    return (new EventAnalyzer(['app/Events'], ['app/Listeners']))
        ->analyze(fixture('events-project'));
}

it('finds every event class in the configured directories', function () {
    // Not only the ones something dispatches. Measured on a real application, discovery by
    // directory finds 211 events where the traced call chains had reached 45.
    expect(array_keys(analyzeEvents()))
        ->toContain('Acme\\Shop\\Events\\OrderPlaced')
        ->toContain('Acme\\Shop\\Events\\OrderShipped');
});

it('skips an abstract base event, which is never dispatched', function () {
    expect(array_keys(analyzeEvents()))->not->toContain('Acme\\Shop\\Events\\AbstractDomainEvent');
});

it('reads promoted constructor properties as payload', function () {
    // How nearly every modern event carries its payload. Missing these leaves an event
    // registered with no fields, and a consumer with nothing to branch on.
    expect(analyzeEvents()['Acme\\Shop\\Events\\OrderPlaced']->properties)
        ->toBe(['order', 'channel']);
});

it('leaves out non-public properties, which a consumer cannot read', function () {
    expect(analyzeEvents()['Acme\\Shop\\Events\\OrderPlaced']->properties)->not->toContain('secret')
        ->and(analyzeEvents()['Acme\\Shop\\Events\\OrderShipped']->properties)->toBe(['order']);
});

it('finds the events a listener dispatches, continuing the chain', function () {
    $analyzer = new EventAnalyzer(['app/Events'], ['app/Listeners']);
    $events = $analyzer->analyze(fixture('events-project'));

    expect($analyzer->firedBy(fixture('events-project'), $events))
        ->toBe(['Acme\\Shop\\Listeners\\NotifyWarehouse' => ['Acme\\Shop\\Events\\OrderShipped']]);
});

it('ignores a constructed class that is not a known event', function () {
    // Constructing something is not dispatching it, and guessing from the name would link
    // every value object a listener happens to build.
    $analyzer = new EventAnalyzer(['app/Events'], ['app/Listeners']);
    $fired = $analyzer->firedBy(fixture('events-project'), $analyzer->analyze(fixture('events-project')));

    expect($fired['Acme\\Shop\\Listeners\\NotifyWarehouse'])->not->toContain('Acme\\Shop\\Support\\Receipt');
});

it('names events that only a listener edge knows about', function () {
    // An event kept outside the configured directories is still an event once something
    // listens to it.
    $edges = [
        new CallChainEdge('Acme\\Shop\\Legacy\\OrderRefunded', '__construct', 'Acme\\Shop\\Listeners\\ChargeCard', 'handle', 'listener'),
        new CallChainEdge('Acme\\Shop\\Http\\Controller', 'index', 'Acme\\Shop\\Services\\Thing', 'run', 'service'),
    ];

    expect(EventAnalyzer::fqcnsFrom($edges))->toBe(['Acme\\Shop\\Legacy\\OrderRefunded']);
});
