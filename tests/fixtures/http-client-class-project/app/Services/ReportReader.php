<?php

namespace App\Services;

use App\Clients\ReportBuilder;

class ReportReader
{
    public function __construct(private ReportBuilder $reports) {}

    public function read()
    {
        return collect($this->reports->rows())->get('total');
    }
}
