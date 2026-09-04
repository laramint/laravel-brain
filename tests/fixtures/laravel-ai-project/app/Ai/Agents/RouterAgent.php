<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\HasTools;

/**
 * An agent by inheritance: the seed pass cannot see it, because the SDK is named only through
 * its base class. Its tools() returns another agent, which the SDK wraps in an AgentTool.
 */
class RouterAgent extends BaseAgent implements HasTools
{
    public function tools(): iterable
    {
        return [
            TranslationAgent::class,
        ];
    }
}
