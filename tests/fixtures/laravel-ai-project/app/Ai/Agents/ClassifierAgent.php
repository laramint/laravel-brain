<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Declares both a `#[Model]` attribute and a `model()` method. The SDK reads the method and
 * never looks at the attribute, so the attribute is dead — which is exactly what the graph
 * should say instead of printing 'claude-opus-4-8' as the answer.
 */
#[Model('claude-opus-4-8')]
#[UseCheapestModel]
class ClassifierAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'Classify the given text.';
    }

    public function model(): ?string
    {
        return config('services.classifier.model');
    }
}
