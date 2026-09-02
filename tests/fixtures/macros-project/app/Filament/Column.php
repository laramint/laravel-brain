<?php

namespace App\Filament;

/**
 * Stands in for a Filament component: it has its OWN macro() through a separate Macroable
 * trait, not Illuminate's. The call shape is identical, which is the point.
 */
class Column
{
    public static function macro(string $name, callable $macro): void {}
}
