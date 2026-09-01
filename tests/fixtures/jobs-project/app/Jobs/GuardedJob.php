<?php

namespace Acme\Shop\Jobs;

use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class GuardedJob
{
    public function middleware(): array
    {
        return [
            new WithoutOverlapping('shipment:'.$this->id)
                ->releaseAfter(60)
                ->expireAfter(180),
            new ThrottlesExceptions(5, 15),
            RateLimited::class,
        ];
    }
}
