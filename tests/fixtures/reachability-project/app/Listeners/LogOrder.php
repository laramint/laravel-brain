<?php

namespace App\Listeners;

use App\Events\OrderPlaced;

/**
 * A synchronous listener. It runs inside the call chain of whoever dispatched the event, so
 * it is reached rather than a root — and it must not appear among the entry points.
 */
class LogOrder
{
    public function handle(OrderPlaced $event): void
    {
        //
    }
}
