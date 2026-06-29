<?php

namespace App\Listeners;

// Registered only via EventServiceProvider::$listen — handle() does not
// type-hint the event, so convention discovery cannot reach it.
class SendShipmentNotification
{
    public function handle($event): void {}
}
