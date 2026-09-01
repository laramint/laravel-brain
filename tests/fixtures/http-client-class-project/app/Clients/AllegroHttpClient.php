<?php

namespace App\Clients;

use App\Support\TransientFailureRetry;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AllegroHttpClient
{
    public function api(): PendingRequest
    {
        $request = TransientFailureRetry::applyTo(
            Http::baseUrl('https://api.allegro.test')
        )->timeout(5);

        return $request;
    }
}
