<?php

namespace App\Events;

class BillingRunStarted
{
    public function __construct(public string $period = '2026-01') {}
}
