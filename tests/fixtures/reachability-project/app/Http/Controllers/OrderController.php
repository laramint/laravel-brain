<?php

namespace App\Http\Controllers;

use App\Services\OrderService;

class OrderController
{
    public function store(OrderService $orders): void
    {
        $orders->place();
    }
}
