<?php

namespace App\Contracts;

interface ClockInterface
{
    public function now(): string;
}
