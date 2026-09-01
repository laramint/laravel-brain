<?php

namespace App\Jobs;

/**
 * Unreached, but named by a config file — the framework resolves it long after any call
 * chain, so "nothing reaches it" here says nothing about whether it runs.
 */
class RebuildIndex
{
    public function handle(): void
    {
        //
    }
}
