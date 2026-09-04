<?php

namespace App\Jobs;

use App\Ai\Agents\TranslationAgent;

class TranslateJob
{
    public function handle(TranslationAgent $agent)
    {
        return $agent->prompt('Translate this.')->text;
    }
}
