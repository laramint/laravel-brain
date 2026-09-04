<?php

namespace Acme\Shop\Jobs;

use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class GuardedJob
{
    public function middleware(): array
    {
        return [
            // Parenthesised so the fixture parses on every PHP the package supports. PHP 8.4 also
            // allows `new X()->m()`, which PhpParser reads into the same MethodCall(var: New_),
            // so the analyzer sees no difference between the two spellings.
            (new WithoutOverlapping('shipment:'.$this->id))
                ->releaseAfter(60)
                ->expireAfter(180),
            new ThrottlesExceptions(5, 15),
            RateLimited::class,
        ];
    }
}
