<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

abstract class BaseAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Base instructions.';
    }
}
