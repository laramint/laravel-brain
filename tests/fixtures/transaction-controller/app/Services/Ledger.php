<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Ledger
{
    public function __construct(private readonly Writer $writer) {}

    /** Reached from the controller; opens a span of its own around a deeper hop. */
    public function record(): void
    {
        DB::transaction(function (): void {
            $this->writer->write();
        });
    }

    public function audit(): void {}

    /** Reached from a route closure. */
    public function reconcile(): void {}
}
