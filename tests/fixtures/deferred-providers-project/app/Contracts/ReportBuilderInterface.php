<?php

namespace App\Contracts;

interface ReportBuilderInterface
{
    public function build(): string;
}
