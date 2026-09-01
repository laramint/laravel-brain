<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;

/**
 * Takes its tools through the constructor, so tools() names nothing a static reading can follow.
 * The tools are visible where the agent is built, not here.
 */
final readonly class InjectedToolsAgent implements Agent, HasTools
{
    /** @param iterable<int, object> $tools */
    public function __construct(private iterable $tools, private ?Agent $fallback = null) {}

    public function instructions(): string
    {
        return 'Answer using the supplied tools.';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}
