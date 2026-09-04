<?php

namespace App\Http\Controllers;

use App\Services\LedgerClient;
use Illuminate\Support\Facades\Http;

class PaymentController
{
    public function __construct(private LedgerClient $ledger) {}

    public function store($request)
    {
        // Two identical requests: the node lists the third party once.
        Http::get('https://api.example.test/ping');
        Http::get('https://api.example.test/ping');

        // Nested inside a loop, so only a walk of the whole step tree finds it.
        foreach ($request->ids as $id) {
            Http::get('https://api.example.test/orders/'.$id);
        }

        $charge = Http::withToken('secret')
            ->retry(3, 250)
            ->timeout(5)
            ->post('https://api.stripe.test/v1/charges', ['amount' => 100]);

        return $charge->json();
    }

    public function status()
    {
        return $this->ledger->balance();
    }
}
