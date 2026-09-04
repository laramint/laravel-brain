<?php

namespace App\Support;

/**
 * Unreached, and named as a class-string in a registration array somewhere else — there is
 * no call for the tracer to follow, and the class is very much in use.
 */
class ReportRenderer
{
    public function render(): string
    {
        return '';
    }
}
