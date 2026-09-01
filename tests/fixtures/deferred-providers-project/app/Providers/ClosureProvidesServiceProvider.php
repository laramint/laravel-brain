<?php

namespace App\Providers;

use App\Support\Ledger;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * A closure inside provides(). Its `return` belongs to the closure, not to the method — reading
 * it as this provider's answer would invent a service list out of code that may never run.
 */
class ClosureProvidesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('reporting.queue');
    }

    public function provides(): array
    {
        $legacy = static function (): array {
            return [Ledger::class];
        };

        return $this->app->bound('reporting.legacy') ? $legacy() : ['reporting.queue'];
    }
}
