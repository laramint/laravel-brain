<?php

namespace App\Services;

use GuzzleHttp\Client;

class LedgerClient
{
    public function balance()
    {
        $client = new Client(['base_uri' => 'https://ledger.test', 'timeout' => 2.5]);

        return $client->request('GET', '/v2/balance');
    }
}
