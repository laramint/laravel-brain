<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[Model('gpt-4o-mini')]
#[Provider('openai')]
#[MaxTokens(512)]
#[Timeout(30)]
class TranslationAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Translate the given text.';
    }
}
