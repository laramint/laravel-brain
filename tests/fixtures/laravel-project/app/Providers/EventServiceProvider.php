<?php

namespace App\Providers;

use App\Events\InvoiceArchived;
use App\Events\OrderShipped;
use App\Events\PaymentCaptured as Captured;
use App\Events\UserLoggedIn;
use App\Listeners\HandleUserLoggedIn;
use App\Listeners\SendShipmentNotification;
use App\Subscribers\UserEventSubscriber;

class EventServiceProvider
{
    protected $listen = [
        OrderShipped::class => [
            SendShipmentNotification::class,
        ],
        // Also discoverable by convention — must not produce a duplicate edge.
        UserLoggedIn::class => [
            HandleUserLoggedIn::class,
        ],
        // String FQCN key + legacy 'Class@method' listener string.
        'App\Events\ReportReady' => [
            'App\Listeners\SendShipmentNotification@sendReport',
        ],
        // Aliased import + [Class::class, 'method'] tuple shape.
        Captured::class => [
            [SendShipmentNotification::class, 'onCaptured'],
        ],
        // Single listener value (not wrapped in an array).
        InvoiceArchived::class => SendShipmentNotification::class,
    ];

    protected $subscribe = [
        UserEventSubscriber::class,
    ];
}
