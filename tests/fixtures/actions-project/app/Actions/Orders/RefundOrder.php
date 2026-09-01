<?php

namespace App\Actions\Orders;

use App\Models\Order;

class RefundOrder
{
    public function __invoke()
    {
        return Order::query()->first();
    }

    /**
     * Protected, so it is not a way in — the class still has exactly one entry method.
     */
    protected function handle()
    {
        return null;
    }
}
