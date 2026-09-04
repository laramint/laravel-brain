<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\LedgerInterface;
use App\Support\Ledger;
use App\Support\MetricsCollector;
use App\Support\Reporter;
use App\Support\SearchClient;
use App\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

/**
 * Every registration shape the analyzer is expected to record, in one provider.
 */
class BindingShapesServiceProvider extends ServiceProvider
{
    /** @var array<string, string> */
    public $bindings = [
        'reporting' => Reporter::class,
    ];

    /** @var array<string, string> */
    public $singletons = [
        MetricsCollector::class => MetricsCollector::class,
    ];

    public function register(): void
    {
        // Self-binding: one argument, concrete defaults to the abstract.
        $this->app->singleton(SystemClock::class);
        $this->app->scoped(SearchClient::class);

        // Bare container alias, the shape a facade accessor points at.
        $this->app->singleton('ledger', Ledger::class);

        // Named arguments, written the other way round from the declaration.
        $this->app->bind(concrete: Ledger::class, abstract: LedgerInterface::class);

        // The container reached through the helper, and through a closure parameter.
        app()->singleton('clock.system', SystemClock::class);

        $this->app->resolving(function ($app): void {
            $app->bind('search.client', SearchClient::class);
        });

        // A real registration whose CONCRETE is an alias chaining onto another one. The
        // abstract is recorded; the alias is not filed as a class name.
        $this->app->bind(Reporter::class, 'reporting');
    }
}
