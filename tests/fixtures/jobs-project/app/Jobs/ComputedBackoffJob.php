<?php

namespace Acme\Shop\Jobs;

class ComputedBackoffJob
{
    public function backoff(): int
    {
        return 60;
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }
}
