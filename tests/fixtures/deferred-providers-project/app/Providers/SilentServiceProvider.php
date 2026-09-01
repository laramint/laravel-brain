<?php

namespace App\Providers;

use App\Support\Ledger;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Deferred but never reachable: no provides() override, so the inherited one returns [] and the
 * deferred manifest gets no entry pointing here. register() and boot() never run.
 */
class SilentServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(Ledger::class);
    }
}
