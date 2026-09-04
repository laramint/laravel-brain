<?php

namespace App\Support;

use App\Contracts\ClockInterface;

class SystemClock implements ClockInterface
{
    public function now(): string
    {
        return '2026-01-01';
    }
}
