<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ServiceProviderAnalyzer;

// ── Deferral itself ───────────────────────────────────────────────────────────

it('marks a provider deferred only when it implements DeferrableProvider', function () {
    $registry = (new ServiceProviderAnalyzer)->analyze(fixture('deferred-providers-project'));

    expect($registry->get('App\Providers\ReportServiceProvider'))->deferred->toBeTrue()
        ->and($registry->get('App\Providers\AppServiceProvider'))->deferred->toBeFalse();
});

it('does not treat the pre-5.8 $defer property as deferral', function () {
    // ServiceProvider::isDeferred() is `$this instanceof DeferrableProvider` and nothing else
    // (laravel/framework v13.29.0). Grepping the framework for `$this->defer` returns nothing,
    // so this provider is registered eagerly on every request.
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\LegacyDeferServiceProvider');

    expect($record)
        ->deferred->toBeFalse()
        ->legacyDeferProperty->toBeTrue()
        ->and($record?->legacyDeferIgnored())->toBeTrue();
});

// ── provides() and when() ─────────────────────────────────────────────────────

it('reads the service keys a provider promises', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\ReportServiceProvider');

    expect($record?->provides)->toBe(['App\Contracts\ReportBuilderInterface'])
        ->and($record?->providesIsDynamic)->toBeFalse();
});

it('keeps bare container aliases verbatim, because that is what the manifest is keyed by', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\AliasedLedgerServiceProvider');

    expect($record?->provides)->toBe(['ledger', 'billing.ledger'])
        ->and($record?->bindingKeys)->toContain('ledger')
        ->and($record?->bindingKeys)->toContain('billing.ledger');
});

it('reads the events that register a provider through when()', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\EventTriggeredServiceProvider');

    expect($record?->when)->toBe(['App\Events\BillingRunStarted']);
});

it('reports a computed provides() as unreadable rather than empty', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\DynamicProvidesServiceProvider');

    expect($record)
        ->providesIsDynamic->toBeTrue()
        ->provides->toBe([]);
});

// ── The static half of Laravel's deferred manifest ────────────────────────────

it('builds the deferred-services map the way ProviderRepository would', function () {
    $manifest = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->deferredServices();

    expect($manifest)
        ->toHaveKey('App\Contracts\ReportBuilderInterface', 'App\Providers\ReportServiceProvider')
        ->toHaveKey('ledger', 'App\Providers\AliasedLedgerServiceProvider')
        // Eager providers never reach the deferred map, whatever their provides() says —
        // LegacyDeferServiceProvider promises Ledger and is registered on every request anyway.
        ->not->toHaveKey('App\Support\Ledger');
});

// ── Defects ───────────────────────────────────────────────────────────────────

it('flags a deferred provider that provides nothing as never booting', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\SilentServiceProvider');

    expect($record?->neverBoots())->toBeTrue();
});

it('does not call a deferred provider unbootable when when() can register it', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\EventTriggeredServiceProvider');

    expect($record?->provides)->toBe([])
        ->and($record?->neverBoots())->toBeFalse();
});

it('leaves the pre-5.8 property alone when the interface is there too', function () {
    // $defer alongside DeferrableProvider is dead weight, not a defect: the interface already
    // defers the provider, so nothing about its loading is wrong.
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\AliasedLedgerServiceProvider');

    expect($record)
        ->deferred->toBeTrue()
        ->legacyDeferProperty->toBeTrue()
        ->and($record?->legacyDeferIgnored())->toBeFalse();
});

it('does not judge a half-readable provides() against a half-readable set of bindings', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\PartialProvidesServiceProvider');

    // ExportBuilder is legible, and 'reporting.export' is the only registration we can see —
    // reporting the mismatch would still be a guess about the half we could not read.
    expect($record?->provides)->toBe(['App\Support\ExportBuilder'])
        ->and($record?->providesIsDynamic)->toBeTrue()
        ->and($record?->bindingKeys)->toBe(['reporting.export'])
        ->and($record?->unbackedProvides())->toBe([]);
});

it('flags provides() entries the provider does not register', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\ClockServiceProvider');

    expect($record?->unbackedProvides())->toBe(['App\Support\SystemClock']);
});

it('stays silent on providers whose promises are all backed', function () {
    $registry = (new ServiceProviderAnalyzer)->analyze(fixture('deferred-providers-project'));

    expect($registry->get('App\Providers\ReportServiceProvider')?->unbackedProvides())->toBe([])
        ->and($registry->get('App\Providers\AliasedLedgerServiceProvider')?->unbackedProvides())->toBe([])
        // Unreadable provides() means unreadable, not wrong.
        ->and($registry->get('App\Providers\DynamicProvidesServiceProvider')?->unbackedProvides())->toBe([])
        // An eager provider's provides() is inert; there is no defect to report on it.
        ->and($registry->get('App\Providers\LegacyDeferServiceProvider')?->unbackedProvides())->toBe([]);
});

it('counts the single-argument singleton form as a registration', function () {
    // ContainerBindingAnalyzer skips `singleton(Foo::class)` because it has no concrete to pair
    // with — reusing that rule here would report every such provider as promising the unbound.
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\SilentServiceProvider');

    expect($record?->bindingKeys)->toBe(['App\Support\Ledger']);
});

it('reads the $singletons property array as registrations', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\DynamicProvidesServiceProvider');

    expect($record?->bindingKeys)->toBe(['App\Support\Ledger']);
});

it('resolves a grouped-import class name in provides()', function () {
    // `use App\Contracts;` then `Contracts\ClockInterface::class` — the head segment is the
    // imported one, and the rest hangs off it rather than off the file namespace.
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\LoopBoundServiceProvider');

    expect($record?->provides)->toBe(['App\Contracts\ClockInterface']);
});

it('says nothing about promises when no binding of the provider is legible', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\LoopBoundServiceProvider');

    expect($record?->bindingKeys)->toBe([])
        ->and($record?->unbackedProvides())->toBe([]);
});

it('does not read a return inside a closure as the provider answer', function () {
    $record = (new ServiceProviderAnalyzer)
        ->analyze(fixture('deferred-providers-project'))
        ->get('App\Providers\ClosureProvidesServiceProvider');

    expect($record?->provides)->toBe([])
        ->and($record?->providesIsDynamic)->toBeTrue()
        // Unreadable, therefore unjudged — despite provides() being right there.
        ->and($record?->unbackedProvides())->toBe([])
        ->and($record?->neverBoots())->toBeFalse();
});

// ── Configurable provider paths ───────────────────────────────────────────────

it('reads providers that live in packages', function () {
    $registry = (new ServiceProviderAnalyzer(null, ['packages/*/src/Providers']))
        ->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Providers\BillingServiceProvider'))
        ->not->toBeNull()
        ->deferred->toBeFalse();
});
