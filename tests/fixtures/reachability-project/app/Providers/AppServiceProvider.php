<?php

namespace App\Providers;

use App\Contracts\Importer;
use App\Services\LegacyImporter;
use App\Support\ReportRenderer;

class AppServiceProvider
{
    /** @var array<int, class-string> registered by name, never called from here */
    protected $renderers = [
        ReportRenderer::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Importer::class, LegacyImporter::class);
    }
}
