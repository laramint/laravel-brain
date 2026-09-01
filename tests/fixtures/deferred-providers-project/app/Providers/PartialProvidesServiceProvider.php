<?php

namespace App\Providers;

use App\Support\ExportBuilder;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Half of provides() is a literal and half is computed, and what it binds matches neither.
 *
 * Tempting to report ExportBuilder as unbacked — it is right there, and 'reporting.export' is the
 * only thing registered. But a provider that computes half its declarations is a provider whose
 * registrations we are no more likely to have read in full, and matching half of one list
 * against half of another manufactures findings. Nothing is claimed here.
 */
class PartialProvidesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('reporting.export');
    }

    public function provides(): array
    {
        return [ExportBuilder::class, $this->driverKey()];
    }

    private function driverKey(): string
    {
        return 'reporting.'.config('reporting.driver');
    }
}
