<?php

namespace App\Jobs;

use App\Ai\Agents\InjectedToolsAgent;
use App\Ai\Agents\SupportAgent;
use App\Support\AssistantToolProvider;

class AnswerTicketJob
{
    public function handle()
    {
        // The fallback agent is named in the same argument list as the tool provider. It has
        // tools of its own, and none of them belong to this agent.
        $agent = new InjectedToolsAgent(
            tools: resolve(AssistantToolProvider::class)->toolsForSupport(),
            fallback: new SupportAgent,
        );

        return $agent->prompt('Answer the ticket.')->text;
    }
}
