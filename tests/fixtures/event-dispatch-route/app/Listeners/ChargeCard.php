<?php

namespace App\Listeners;

use App\Events\OrderPlaced;

class ChargeCard
{
    public function handle(OrderPlaced $event): void {}
}
