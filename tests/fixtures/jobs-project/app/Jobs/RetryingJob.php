<?php

namespace Acme\Shop\Jobs;

class RetryingJob
{
    public int $tries = 5;

    public $timeout = 120;

    public $afterCommit = true;
}
