<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RefundTool;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Declares tools() but not HasTools. The SDK's resolveTools() returns early on the missing
 * contract, so the model is never offered RefundTool — the tools() body is dead code.
 */
class DraftAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Draft a reply.';
    }

    public function tools(): iterable
    {
        return [
            new RefundTool,
        ];
    }
}
