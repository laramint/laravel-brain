<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\ContainerBindingAnalyzer;

it('extracts singleton bindings from fixture AppServiceProvider', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('laravel-project'));
    $rec = $registry->get('App\Contracts\ThingRepositoryInterface');

    expect($rec)
        ->concreteFqcn->toBe('App\Repositories\SqlThingRepository')
        ->providerFqcn->toBe('App\Providers\AppServiceProvider')
        ->kind->toBe('singleton');
});

it('extracts bindings from a provider that opens with declare(strict_types=1)', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('strict-types-project'));
    $rec = $registry->get('App\Contracts\ClockInterface');

    expect($rec)
        ->concreteFqcn->toBe('App\Support\SystemClock')
        ->providerFqcn->toBe('App\Providers\StrictTypesServiceProvider')
        ->kind->toBe('singleton');
});

// ── Configurable provider paths ───────────────────────────────────────────────

it('finds nothing in a packaged application while the default app/Providers path is used', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\LedgerInterface'))->toBeNull();
});

it('reads bindings from providers that live in packages', function () {
    $registry = (new ContainerBindingAnalyzer(null, ['packages/*/src/Providers']))
        ->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\LedgerInterface'))
        ->concreteFqcn->toBe('Acme\Billing\Support\SqlLedger')
        ->providerFqcn->toBe('Acme\Billing\Providers\BillingServiceProvider')
        ->kind->toBe('singleton');
});

it('reaches a provider nested more than one directory deep', function () {
    // The scan used to be `*.php` plus `**/*.php`, and PHP's glob does not cross
    // directory separators — so exactly one level down was as far as it went.
    $registry = (new ContainerBindingAnalyzer(null, ['packages/*/src/Providers']))
        ->analyze(fixture('packaged-app'));

    expect($registry->get('Acme\Billing\Support\InvoiceNumberer'))
        ->concreteFqcn->toBe('Acme\Billing\Support\SequentialInvoiceNumberer')
        ->providerFqcn->toBe('Acme\Billing\Providers\Nested\InvoiceServiceProvider')
        ->kind->toBe('bind');
});

it('still reads app/Providers when it is among the configured paths', function () {
    $registry = (new ContainerBindingAnalyzer(null, ['app/Providers', 'packages/*/src/Providers']))
        ->analyze(fixture('laravel-project'));

    expect($registry->get('App\Contracts\ThingRepositoryInterface'))
        ->concreteFqcn->toBe('App\Repositories\SqlThingRepository');
});

// ── Registration shapes ───────────────────────────────────────────────────────

it('records a single-argument self-binding with the abstract as its own concrete', function () {
    // `count($args) >= 2` used to drop these outright. Container::bind() does
    // `if (is_null($concrete)) { $concrete = $abstract; }`, so the concrete is not unknown here
    // — leaving it null would say "bound through a closure", which is a different fact.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('App\Support\SystemClock'))
        ->concreteFqcn->toBe('App\Support\SystemClock')
        ->providerFqcn->toBe('App\Providers\BindingShapesServiceProvider')
        ->kind->toBe('singleton');

    expect($registry->get('App\Support\SearchClient'))
        ->concreteFqcn->toBe('App\Support\SearchClient')
        ->kind->toBe('scoped');
});

it('records a bare container alias as the abstract', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('ledger'))
        ->concreteFqcn->toBe('App\Support\Ledger')
        ->kind->toBe('singleton');
});

it('records a dotted container alias, and one bound through a closure parameter', function () {
    // `app()->singleton('clock.system', …)` and `$app->bind('search.client', …)` inside a
    // resolving() closure — the two container spellings that are not `$this->app`.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('clock.system'))->concreteFqcn->toBe('App\Support\SystemClock');
    expect($registry->get('search.client'))->concreteFqcn->toBe('App\Support\SearchClient');
});

it('reads a bare alias out of the $bindings array property', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('reporting'))
        ->concreteFqcn->toBe('App\Support\Reporter')
        ->kind->toBe('bind');
});

it('binds named arguments by name rather than by position', function () {
    // Written `bind(concrete: Ledger::class, abstract: LedgerInterface::class)`. Reading
    // $args[0]/$args[1] positionally does not merely miss this one — it records it backwards,
    // with the implementation as the abstract.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('App\Contracts\LedgerInterface'))
        ->concreteFqcn->toBe('App\Support\Ledger')
        ->kind->toBe('bind');

    expect($registry->get('App\Support\Ledger'))->toBeNull();
});

// ── Discipline: what must stay out ────────────────────────────────────────────

it('ignores bind-like calls whose receiver is not the container', function () {
    // The scan is a bare method-name match, so the receiver check is the only thing keeping a
    // `$collection->bind(…)` or a custom `singleton()` out. Accepting one argument makes that
    // check load-bearing where two arguments used to hide the problem.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    foreach ($registry->all() as $abstract => $record) {
        expect($record->providerFqcn)
            ->not->toBe('App\Providers\DecoyServiceProvider', "recorded {$abstract} from a decoy call");
    }
});

it('requires the single-argument form to be class-shaped', function () {
    // `$this->app->bind('unresolvable-on-its-own')` binds a key to nothing resolvable, and is
    // the shape an unrelated one-argument bind() most often takes.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('unresolvable-on-its-own'))->toBeNull();
});

it('rejects a string that cannot be a container key', function () {
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('a key with spaces'))->toBeNull();
});

it('leaves an alias in the concrete position unresolved rather than filing it as a class', function () {
    // `bind(Reporter::class, 'reporting')` chains onto another registration. The abstract is a
    // real registration and is recorded; the alias must not land in concreteFqcn, which
    // GraphBuilder turns into a class node.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('App\Support\Reporter'))
        ->not->toBeNull()
        ->concreteFqcn->toBeNull();
});

it('gives up on a registration whose arguments are spread', function () {
    // `bind(Ledger::class, ...$rest)` writes a concrete that cannot be read. Recording the
    // abstract and stopping would file it as a self-binding of Ledger to itself — a claim the
    // source contradicts — so the call is dropped whole. Missing beats wrong here.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->get('App\Support\Ledger'))->toBeNull();
});

it('survives a first-class callable spelled like a registration', function () {
    // `$this->app->bind(...)` puts a VariadicPlaceholder in the argument list where every other
    // shape puts an Arg. Reading ->name or ->unpack off it is a fatal error, so the analyzer has
    // to recognise it before touching it.
    $registry = (new ContainerBindingAnalyzer)->analyze(fixture('container-bindings-project'));

    expect($registry->all())->not->toBeEmpty();
});
