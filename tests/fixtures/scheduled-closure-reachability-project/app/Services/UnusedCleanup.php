<?php

namespace App\Services;

/**
 * Nothing calls this, so the walk must report it. Without it, a walk that reached nothing at all
 * would pass the test too.
 */
class UnusedCleanup
{
    public function run(): void
    {
        //
    }
}
