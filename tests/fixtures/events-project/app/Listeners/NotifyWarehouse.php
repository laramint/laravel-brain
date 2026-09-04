<?php

namespace Acme\Shop\Listeners;

use Acme\Shop\Events\OrderShipped;
use Acme\Shop\Support\Receipt;

class NotifyWarehouse
{
    public function handle(object $event): void
    {
        // A value object, not an event — building one is not dispatching one.
        $receipt = new Receipt;

        event(new OrderShipped);
    }
}
