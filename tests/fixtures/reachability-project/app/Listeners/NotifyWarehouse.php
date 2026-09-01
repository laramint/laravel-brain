<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A queued listener: a worker runs it with no caller, which makes it a root of its own
 * rather than something reached through whoever dispatched the event.
 */
class NotifyWarehouse implements ShouldQueue
{
    public function handle(OrderPlaced $event): void
    {
        //
    }
}
