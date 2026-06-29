<?php

namespace App\Listeners;

use App\Events\InvoiceRequested;
use Illuminate\Events\Attributes\AsEventListener;

// Class-level attribute names the event explicitly; handle() carries no hint.
#[AsEventListener(event: InvoiceRequested::class)]
class ProcessInvoice
{
    public function handle(): void {}
}
