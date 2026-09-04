<?php

namespace App\Providers;

use App\Contracts\ReportBuilderInterface;
use App\Support\PdfReportBuilder;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * A correctly deferred provider: what it promises is what it binds.
 */
class ReportServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportBuilderInterface::class, PdfReportBuilder::class);
    }

    public function provides(): array
    {
        return [ReportBuilderInterface::class];
    }
}
