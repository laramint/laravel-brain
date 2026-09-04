<?php

namespace App\Providers;

use App\Contracts;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers through a loop, so not one binding key is legible. provides() is legible and not
 * empty — but with nothing known about what this provider registers, calling the promise
 * unbacked would be a guess, so nothing is claimed.
 *
 * Also the grouped-import spelling: `use App\Contracts;` plus `Contracts\ClockInterface::class`.
 */
class LoopBoundServiceProvider extends ServiceProvider implements DeferrableProvider
{
    /** @var array<string, string> */
    private array $map = [];

    public function register(): void
    {
        foreach ($this->map as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
    }

    public function provides(): array
    {
        return [Contracts\ClockInterface::class];
    }
}
