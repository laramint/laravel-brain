<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class ReportBuilder
{
    public function build($id)
    {
        return cache()->rememberForever("report:{$id}", fn () => $this->compute($id));
    }

    public function compute($id)
    {
        Cache::flush();

        return $id;
    }
}
