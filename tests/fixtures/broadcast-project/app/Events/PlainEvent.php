<?php

namespace App\Events;

class PlainEvent
{
    public function __construct(public readonly int $id) {}
}
