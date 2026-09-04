<?php

namespace App\Providers;

use App\Support\OrderAnalytics;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;

class OtherMacrosProvider extends ServiceProvider
{
    public function boot(): void
    {
        Collection::mixin(OrderAnalytics::class);
    }
}
