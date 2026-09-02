<?php

namespace App\Events;

class OrderPlaced
{
    public function __construct(public readonly int $orderId) {}
}
