<?php

namespace App\Actions;

use App\Models\Order;

/**
 * Two public entry methods, so no single one can be named. Still an action class by
 * placement — the graph just does not claim to know where to start reading it.
 */
class ArchiveOrder
{
    public function handle()
    {
        return $this->execute();
    }

    public function execute()
    {
        return Order::query()->count();
    }
}
