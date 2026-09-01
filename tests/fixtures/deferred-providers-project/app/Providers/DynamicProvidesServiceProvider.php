<?php

namespace App\Providers;

use App\Support\Ledger;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * provides() is computed, so no static reading of it is possible — and therefore no defect may be
 * claimed about it either way.
 */
class DynamicProvidesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public $singletons = [
        Ledger::class => Ledger::class,
    ];

    public function provides(): array
    {
        return array_keys($this->singletons);
    }
}
