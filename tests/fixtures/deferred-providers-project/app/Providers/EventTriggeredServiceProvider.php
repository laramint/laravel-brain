<?php

namespace App\Providers;

use App\Events\BillingRunStarted;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Empty provides(), but reachable all the same: ProviderRepository::registerLoadEvents() listens
 * for every event when() names and registers the provider when one is dispatched. Not a defect.
 */
class EventTriggeredServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        //
    }

    public function provides(): array
    {
        return [];
    }

    public function when(): array
    {
        return [BillingRunStarted::class];
    }
}
