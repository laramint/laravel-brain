<?php

namespace App\Http\Controllers;

use App\Events\OrderPlaced;

class OrderController
{
    public function store(): void
    {
        event(new OrderPlaced(1));
    }
}
