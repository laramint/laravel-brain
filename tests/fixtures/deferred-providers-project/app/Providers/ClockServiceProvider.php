<?php

namespace App\Providers;

use App\Contracts\ClockInterface;
use App\Support\SystemClock;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the contract, promises the implementation. Resolving SystemClock boots this provider and
 * then still fails, because nothing bound SystemClock; resolving ClockInterface does not boot it
 * at all, because that key is not in the manifest.
 */
class ClockServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->bind(ClockInterface::class, SystemClock::class);
    }

    public function provides(): array
    {
        return [SystemClock::class];
    }
}
