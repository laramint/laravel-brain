<?php

namespace Modules\Billing\Services;

use Modules\Billing\Models\Invoice;

class InvoiceService
{
    public function list(): array
    {
        Invoice::query();

        return [];
    }
}
