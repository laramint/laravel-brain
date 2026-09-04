<?php

use LaraMint\LaravelBrain\Analysis\JobAnalyzer;
use LaraMint\LaravelBrain\Analysis\JobDefinition;

function describeJob(string $class): ?JobDefinition
{
    return (new JobAnalyzer)->describe(
        "Acme\\Shop\\Jobs\\{$class}",
        fixture('jobs-project')."/app/Jobs/{$class}.php",
    );
}

it('reads the retry envelope a job declares as properties', function () {
    $job = describeJob('RetryingJob');

    expect($job->tries)->toBe(5)
        ->and($job->timeout)->toBe(120);
});

it('reads a typed property, not only an untyped one', function () {
    // `public int $tries = 5` is how most of these are written now; a reader that only matched
    // the untyped form would miss them silently.
    expect(describeJob('RetryingJob')->tries)->toBe(5);
});

it('treats a true property as the flag it is', function () {
    expect(describeJob('RetryingJob')->afterCommit)->toBeTrue();
});

it('takes the value from a method whose body is just a return', function () {
    // `backoff(): int { return 60; }` states a value as plainly as a property does.
    $job = describeJob('ComputedBackoffJob');

    expect($job->backoff)->toBe(60)
        ->and($job->dynamic)->not->toContain('backoff');
});

it('reports a fact the class computes rather than inventing a number for it', function () {
    // retryUntil() returns a time from now(). There is no number to read, and printing a guess
    // would be worse than printing nothing.
    expect(describeJob('ComputedBackoffJob')->dynamic)->toContain('retryUntil');
});

it('names the middleware class, not the chained configuration around it', function () {
    // Middleware is habitually configured by chaining, so the outermost node of the expression is
    // a method call and the class anyone means sits at the root of it.
    expect(describeJob('GuardedJob')->middleware)
        ->toContain('WithoutOverlapping')
        ->toContain('ThrottlesExceptions');
});

it('reads middleware declared as a class name without constructing it', function () {
    expect(describeJob('GuardedJob')->middleware)->toContain('RateLimited');
});

it('says nothing at all about a job that declares nothing', function () {
    // Six nulls in a panel say less than no panel section.
    expect(describeJob('PlainJob'))->toBeNull();
});
