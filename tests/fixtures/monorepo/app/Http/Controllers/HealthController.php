<?php

namespace App\Http\Controllers;

class HealthController
{
    public function index(): array
    {
        return ['status' => 'ok'];
    }
}
