<?php

namespace App\Providers;

use App\Support\Ledger;
use Illuminate\Support\ServiceProvider;

/**
 * The pre-5.8 spelling of "deferred". Nothing reads $defer any more, so this provider is
 * registered eagerly on every single request — and nothing may claim that resolving Ledger boots
 * it, because an eager provider is already loaded before any resolution happens.
 */
class LegacyDeferServiceProvider extends ServiceProvider
{
    protected $defer = true;

    public function register(): void
    {
        $this->app->singleton(Ledger::class);
    }

    public function provides(): array
    {
        return [Ledger::class];
    }
}
