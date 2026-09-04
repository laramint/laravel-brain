<?php

namespace App\Services;

use App\Events\OrderPlaced;
use App\Jobs\SendReceipt;
use App\Support\BaseWorkflow;

class OrderService extends BaseWorkflow
{
    public function place(): void
    {
        SendReceipt::dispatch();

        event(new OrderPlaced);
    }
}
