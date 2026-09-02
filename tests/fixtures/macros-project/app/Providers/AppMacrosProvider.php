<?php

namespace App\Providers;

use App\Filament\Column;
use App\Support\OrderAnalytics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

class AppMacrosProvider extends ServiceProvider
{
    public function boot(): void
    {
        Blueprint::macro('money', fn (string $column) => null);
        Blueprint::macro('quantity', fn (string $column) => null);

        // A Filament-style receiver, through its own Macroable.
        Column::macro('labelIcon', fn (string $icon) => null);

        // Both mixin spellings.
        Builder::mixin(new OrderAnalytics);

        // A name this cannot read: registered, but not nameable.
        $name = 'computed';
        Blueprint::macro($name, fn () => null);
    }
}
