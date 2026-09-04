<?php

namespace App\Ai\Agents;

use App\Ai\Tools\RefundTool;
use App\Ai\Tools\SearchOrdersTool;
use App\Mcp\Tools\InventoryTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\UseSmartestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\SimilaritySearch;

#[UseSmartestModel]
#[MaxSteps(12)]
#[Temperature(0.2)]
#[Strict]
class SupportAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a support agent.';
    }

    public function messages(): iterable
    {
        return [];
    }

    public function tools(): iterable
    {
        return [
            new SearchOrdersTool,
            RefundTool::class,
            new InventoryTool,
            new SimilaritySearch('knowledge-base'),
        ];
    }

    public function schema($schema): array
    {
        return [];
    }
}
