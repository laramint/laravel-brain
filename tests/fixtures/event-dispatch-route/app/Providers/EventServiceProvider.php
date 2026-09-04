<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Listeners\ChargeCard;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        OrderPlaced::class => [
            ChargeCard::class,
        ],
    ];
}
