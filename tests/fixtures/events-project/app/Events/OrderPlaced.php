<?php

namespace Acme\Shop\Events;

class OrderPlaced
{
    public function __construct(
        public Order $order,
        public string $channel,
        private string $secret,
    ) {}
}
