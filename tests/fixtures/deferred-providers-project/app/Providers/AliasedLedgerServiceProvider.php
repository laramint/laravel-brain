<?php

namespace App\Providers;

use App\Support\Ledger;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The container-alias shape Laravel's own first-party providers use: the manifest key is a bare
 * string, not a class name, and it is still a perfectly valid thing to provide.
 *
 * Keeps the pre-5.8 $defer property alongside DeferrableProvider — the shape left behind when a
 * provider is upgraded. Dead weight, not a defect: the interface already defers it.
 */
class AliasedLedgerServiceProvider extends ServiceProvider implements DeferrableProvider
{
    protected $defer = true;

    public function register(): void
    {
        $this->app->singleton('ledger', function () {
            return new Ledger;
        });

        $this->app->alias('ledger', 'billing.ledger');
    }

    public function provides(): array
    {
        return ['ledger', 'billing.ledger'];
    }
}
