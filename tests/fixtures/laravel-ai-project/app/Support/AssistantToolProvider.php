<?php

namespace App\Support;

use App\Ai\Tools\RefundTool;
use App\Ai\Tools\SearchOrdersTool;

/**
 * Builds the tool set for an agent that cannot name its own. The shape a real application reaches
 * for once more than one agent shares a tool list.
 */
final class AssistantToolProvider
{
    public function toolsForSupport(): array
    {
        return [
            new SearchOrdersTool,
            new RefundTool,
        ];
    }
}
