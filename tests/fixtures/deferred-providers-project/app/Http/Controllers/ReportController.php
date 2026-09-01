<?php

namespace App\Http\Controllers;

use App\Contracts\ReportBuilderInterface;
use App\Support\Ledger;

class ReportController
{
    public function __construct(
        private ReportBuilderInterface $reports,
        private Ledger $ledger,
    ) {}

    public function index()
    {
        $report = $this->reports->build();
        $this->ledger->post($report);

        return $report;
    }
}
