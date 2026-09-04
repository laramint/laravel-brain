<?php

namespace App\Http\Controllers;

use App\Services\OfferSync;
use App\Services\ReportReader;

class OfferController
{
    public function __construct(
        private OfferSync $sync,
        private ReportReader $reader,
    ) {}

    public function index()
    {
        return $this->sync->sync();
    }

    public function reports()
    {
        return $this->reader->read();
    }
}
