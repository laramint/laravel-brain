<?php

use LaraMint\LaravelBrain\Analysis\JobGroup;
use LaraMint\LaravelBrain\Analysis\JobGroups;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** The group read off the first call in a method body, or null when there is none. */
function groupIn(string $body): ?JobGroup
{
    $code = "<?php\nclass Probe {\n    public function handle(): void\n    {\n{$body}\n    }\n}\n";
    $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($code) ?? [];

    $found = (new NodeFinder)->find($ast, fn (Node $node): bool => JobGroups::at($node) !== null);

    return $found === [] ? null : JobGroups::at($found[0]);
}

it('reads a chain dispatched through the Bus facade, in order', function () {
    $group = groupIn('        Bus::chain([new ChargeOrder, new NotifyWarehouse]);');

    expect($group?->kind)->toBe('chain');
    expect($group?->jobs())->toBe(['ChargeOrder', 'NotifyWarehouse']);
    expect($group?->unresolved)->toBeFalse();
});

it('reads a batch, and the entries written as ::class', function () {
    $group = groupIn('        Bus::batch([ReindexOrder::class, new NotifyWarehouse]);');

    expect($group?->kind)->toBe('batch');
    expect($group?->jobs())->toBe(['ReindexOrder', 'NotifyWarehouse']);
});

it('puts the job withChain was called on at the head of the sequence', function () {
    $group = groupIn('        ShipOrder::withChain([new NotifyWarehouse])->dispatch();');

    expect($group?->jobs())->toBe(['ShipOrder', 'NotifyWarehouse']);
    // Nothing else in the tracer sees this head: `withChain` is not a dispatch verb, and the
    // `->dispatch()` around it is called on a pending chain rather than on the job.
    expect($group?->headDispatchesItself)->toBeFalse();
});

it('puts the dispatched job at the head of a chain appended to it', function () {
    $group = groupIn('        dispatch(new ChargeOrder)->chain([new NotifyWarehouse]);');

    expect($group?->jobs())->toBe(['ChargeOrder', 'NotifyWarehouse']);
    expect($group?->headDispatchesItself)->toBeTrue();
});

it('reads the head through the static dispatch verb', function () {
    $group = groupIn('        ShipOrder::dispatch($order)->chain([new NotifyWarehouse]);');

    expect($group?->jobs())->toBe(['ShipOrder', 'NotifyWarehouse']);
});

it('takes the head of a facade dispatch from its argument, not from the facade', function () {
    $group = groupIn('        Bus::dispatch(new ShipOrder)->chain([new NotifyWarehouse]);');

    expect($group?->jobs())->toBe(['ShipOrder', 'NotifyWarehouse']);
});

it('reports a chain holding an entry it cannot name, and keeps the ones it can', function () {
    $group = groupIn('        Bus::chain([$job, new NotifyWarehouse]);');

    expect($group?->jobs())->toBe(['NotifyWarehouse']);
    expect($group?->unresolved)->toBeTrue();
});

it('reports a group whose argument is not an array literal at all', function () {
    $group = groupIn('        Bus::batch($jobs);');

    expect($group?->jobs())->toBe([]);
    expect($group?->unresolved)->toBeTrue();
});

it('is not a dispatch site when nothing was passed', function () {
    expect(groupIn('        Bus::batch();'))->toBeNull();
});

it('leaves a domain method named chain alone', function () {
    // An array of `new` objects handed to a method called `chain` is what a pipeline builder
    // looks like. Only a chain appended to a dispatch is a chain of jobs.
    expect(groupIn('        $pipeline->chain([new ChargeOrder, new ShipOrder]);'))->toBeNull();
});
