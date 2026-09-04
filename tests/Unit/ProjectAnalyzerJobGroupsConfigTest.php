<?php

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use LaraMint\LaravelBrain\Analysis\MethodTracer;
use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;

/**
 * Build a ProjectAnalyzer against the given laravel-brain config and report whether the tracer
 * it made reads chains and batches.
 *
 * The container is booted by hand because this suite has no application: the analyzer reads its
 * settings through `config()` like every other one, and the value it read is only visible on the
 * tracer it constructed. Reaching for it is the point of the test — the setting is one line of
 * wiring, and a mistyped key there is silent, leaving a switch that does nothing.
 *
 * @param  array<string, mixed>  $brain
 */
function tracerDetectsJobGroupsUnder(array $brain): bool
{
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new Repository(['laravel-brain' => $brain]));

    try {
        $tracer = (new ReflectionProperty(ProjectAnalyzer::class, 'methodTracer'))
            ->getValue(new ProjectAnalyzer);

        return (bool) (new ReflectionProperty(MethodTracer::class, 'detectJobGroups'))->getValue($tracer);
    } finally {
        Container::setInstance(null);
    }
}

it('switches the tracer off when the config says so', function () {
    expect(tracerDetectsJobGroupsUnder(['job_groups' => ['enabled' => false]]))->toBeFalse();
});

it('switches the tracer on when the config says so', function () {
    expect(tracerDetectsJobGroupsUnder(['job_groups' => ['enabled' => true]]))->toBeTrue();
});

it('reads chains and batches when nothing was configured at all', function () {
    // The published config ships this on. An application that never publishes it, or publishes an
    // older copy, gets the feature rather than silence.
    expect(tracerDetectsJobGroupsUnder([]))->toBeTrue();
});
