<?php

namespace App\Support;

use App\Contracts\ReportBuilderInterface;

class PdfReportBuilder implements ReportBuilderInterface
{
    public function build(): string
    {
        return 'pdf';
    }
}
