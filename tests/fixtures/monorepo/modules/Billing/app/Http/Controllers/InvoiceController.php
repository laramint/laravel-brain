<?php

namespace Modules\Billing\Http\Controllers;

use Modules\Billing\Services\InvoiceService;
use Shared\Services\SharedService;

class InvoiceController
{
    public function __construct(
        private InvoiceService $invoiceService,
        private SharedService $sharedService,
    ) {}

    public function index(): array
    {
        $this->sharedService->ping();

        return $this->invoiceService->list();
    }
}
