<?php

namespace Acme\Shop\Events;

class OrderShipped
{
    public Order $order;

    protected string $internal;
}
