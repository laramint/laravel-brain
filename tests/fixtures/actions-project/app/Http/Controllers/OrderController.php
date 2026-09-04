<?php

namespace App\Http\Controllers;

use App\Actions\ArchiveOrder;
use App\Actions\CreateOrder;
use App\Actions\Orders\RefundOrder;
use App\Actions\ShipOrder;
use App\Services\OrderPricing;

class OrderController
{
    public function __construct(
        private CreateOrder $createOrder,
        private OrderPricing $pricing,
    ) {}

    public function store()
    {
        $this->pricing->quote();

        return $this->createOrder->handle();
    }

    public function ship()
    {
        return (new ShipOrder)->execute();
    }

    public function refund()
    {
        $refund = new RefundOrder;

        return $refund();
    }

    public function archive()
    {
        return (new ArchiveOrder)->handle();
    }
}
