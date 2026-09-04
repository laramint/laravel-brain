<?php

namespace App\Http\Controllers;

use App\Ai\Agents\SupportAgent;

class SupportController
{
    public function ask()
    {
        return (new SupportAgent)->prompt('How do I return an item?')->text;
    }

    public function index()
    {
        return [];
    }
}
