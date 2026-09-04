<?php

namespace App\Clients;

class ReportBuilder
{
    /**
     * Same shape as a request builder, and not one: nothing here declares a PendingRequest, so
     * `$builder->rows()->get(...)` is a collection being read, not a third party being called.
     */
    public function rows(): array
    {
        return [];
    }
}
