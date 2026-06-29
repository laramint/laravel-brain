<?php

namespace App\Listeners;

// Untyped handle() and not present in $listen/$subscribe/attribute — must yield no edge.
class UnregisteredListener
{
    public function handle($event): void {}
}
