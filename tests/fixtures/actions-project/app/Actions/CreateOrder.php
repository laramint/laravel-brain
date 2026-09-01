<?php

namespace App\Actions;

use App\Models\Order;

class CreateOrder
{
    public function handle()
    {
        (new StoreOrderRequest)->rules();

        SendInvoiceJob::dispatch();

        return Order::create([]);
    }

    protected function normalise()
    {
        return [];
    }
}
