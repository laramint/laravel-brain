<?php

namespace App\Actions;

use App\Models\Order;

class ShipOrder
{
    public function execute()
    {
        return Order::query()->get();
    }
}
