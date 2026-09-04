<?php

namespace App\Http\Controllers;

use App\Services\Ledger;
use Illuminate\Support\Facades\DB;

class LedgerController
{
    public function __construct(private readonly Ledger $ledger) {}

    public function store(): void
    {
        DB::transaction(function (): void {
            $this->ledger->record();
        });

        $this->ledger->audit();
    }
}
