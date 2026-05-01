<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain;

use Illuminate\Support\ServiceProvider;
use LaraMint\LaravelBrain\Commands\ScanCommand;

class LaravelBrainServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-brain.php', 'laravel-brain');

        // Only register routes and commands in local environment for security
        if (! $this->app->isLocal()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-brain.php' => config_path('laravel-brain.php'),
        ], 'laravel-brain-config');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-brain');
        $this->commands([ScanCommand::class]);
        $this->loadRoutesFrom(__DIR__.'/../routes/brain.php');
    }
}
