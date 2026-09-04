<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;

class TransientFailureRetry
{
    public static function applyTo(PendingRequest $request): PendingRequest
    {
        return $request->retry(3, 100);
    }
}
