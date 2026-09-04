<?php

namespace App\Services;

use App\Clients\AllegroHttpClient;

class OfferSync
{
    public function __construct(private AllegroHttpClient $client) {}

    public function sync()
    {
        return $this->client->api()->get('/sale/offers');
    }
}
