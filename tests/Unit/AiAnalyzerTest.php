<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\AiAgentDefinition;
use LaraMint\LaravelBrain\Analysis\AiAnalyzer;
use LaraMint\LaravelBrain\Analysis\AiToolDefinition;

/**
 * @param  array{detected: bool, agents: AiAgentDefinition[], tools: AiToolDefinition[], callSites: mixed}  $result
 */
function aiAgent(array $result, string $shortName): AiAgentDefinition
{
    foreach ($result['agents'] as $agent) {
        if (str_ends_with($agent->fqcn, '\\'.$shortName)) {
            return $agent;
        }
    }

    throw new RuntimeException("No agent named {$shortName} in the result.");
}

/**
 * @param  array{detected: bool, agents: AiAgentDefinition[], tools: AiToolDefinition[], callSites: mixed}  $result
 */
function aiTool(array $result, string $shortName): AiToolDefinition
{
    foreach ($result['tools'] as $tool) {
        if (str_ends_with($tool->fqcn, '\\'.$shortName)) {
            return $tool;
        }
    }

    throw new RuntimeException("No tool named {$shortName} in the result.");
}

it('reports detected=false for a project that never names laravel/ai', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-project'));

    expect($result)
        ->detected->toBeFalse()
        ->agents->toBe([])
        ->tools->toBe([])
        ->callSites->toBe([]);
});

it('detects agents declared through the SDK contract', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    expect($result['detected'])->toBeTrue();

    expect(array_map(fn (AiAgentDefinition $a): string => $a->fqcn, $result['agents']))
        ->toContain('App\\Ai\\Agents\\SupportAgent')
        ->toContain('App\\Ai\\Agents\\TranslationAgent');
});

it('leaves an abstract base agent off the graph', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    expect(array_map(fn (AiAgentDefinition $a): string => $a->fqcn, $result['agents']))
        ->not->toContain('App\\Ai\\Agents\\BaseAgent');
});

it('promotes a subclass whose own file never names the SDK', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    // RouterAgent extends BaseAgent and imports nothing from Laravel\Ai except HasTools;
    // it is only an agent because its parent is, which the seed pass cannot see.
    expect(aiAgent($result, 'RouterAgent'))
        ->fqcn->toBe('App\\Ai\\Agents\\RouterAgent')
        ->contracts->toContain('Agent');
});

it('reads a named model from the Model attribute', function () {
    $agent = aiAgent((new AiAnalyzer)->analyze(fixture('laravel-ai-project')), 'TranslationAgent');

    expect($agent)
        ->model->toBe('gpt-4o-mini')
        ->modelSource->toBe('attribute')
        ->modelTier->toBeNull()
        ->provider->toBe('openai')
        ->providerSource->toBe('attribute')
        ->maxTokens->toBe(512)
        ->timeout->toBe(30);
});

it('reports a tier attribute as a tier rather than guessing a model id', function () {
    $agent = aiAgent((new AiAnalyzer)->analyze(fixture('laravel-ai-project')), 'SupportAgent');

    expect($agent)
        ->model->toBeNull()
        ->modelTier->toBe('smartest')
        ->modelSource->toBe('tier');
});

it('reports a Model attribute shadowed by a model() method instead of printing it', function () {
    $agent = aiAgent((new AiAnalyzer)->analyze(fixture('laravel-ai-project')), 'ClassifierAgent');

    // Promptable::getProvidersAndModels() is an if/else: with a model() method present the
    // attribute is never read, so 'claude-opus-4-8' is not this agent's model.
    expect($agent)
        ->modelSource->toBe('method')
        ->model->toBeNull()
        ->shadowedModelAttribute->toBe('claude-opus-4-8')
        ->methodOverrides->toContain('model');
});

it('records the cost and behaviour knobs', function () {
    $agent = aiAgent((new AiAnalyzer)->analyze(fixture('laravel-ai-project')), 'SupportAgent');

    expect($agent)
        ->maxSteps->toBe(12)
        ->temperature->toBe(0.2)
        ->strict->toBeTrue()
        ->repairToolCalls->toBeFalse()
        ->contracts->toContain('HasStructuredOutput')
        ->contracts->toContain('Conversational');
});

it('resolves tools() to both native and MCP tools, and keeps the rest as unresolved', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));
    $agent = aiAgent($result, 'SupportAgent');

    expect($agent->tools)
        ->toContain('App\\Ai\\Tools\\SearchOrdersTool')
        ->toContain('App\\Ai\\Tools\\RefundTool')
        ->toContain('App\\Mcp\\Tools\\InventoryTool');

    expect($agent->unresolvedTools)->toBe(['Laravel\\Ai\\Tools\\SimilaritySearch']);
});

it('classifies an MCP server tool as a tool of kind mcp', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    expect(aiTool($result, 'InventoryTool'))
        ->kind->toBe('mcp')
        ->description->toBe('Look up warehouse stock.');

    expect(aiTool($result, 'SearchOrdersTool'))
        ->kind->toBe('ai')
        ->description->toBe('Search the customer orders by e-mail address.');
});

it('records an agent returned from tools() as a delegation, not a tool', function () {
    $agent = aiAgent((new AiAnalyzer)->analyze(fixture('laravel-ai-project')), 'RouterAgent');

    expect($agent)
        ->toolAgents->toBe(['App\\Ai\\Agents\\TranslationAgent'])
        ->tools->toBe([]);
});

it('marks an agent that declares tools() without the HasTools contract', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    expect(aiAgent($result, 'DraftAgent'))
        ->declaresHasTools->toBeFalse()
        ->tools->toBe(['App\\Ai\\Tools\\RefundTool']);

    expect(aiAgent($result, 'SupportAgent')->declaresHasTools)->toBeTrue();
});

it('finds the methods that prompt an agent', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    $sites = array_map(
        fn ($site): string => $site->callerFqcn.'::'.$site->callerMethod.' -> '.$site->agentFqcn,
        $result['callSites'],
    );

    expect($sites)
        ->toContain('App\\Http\\Controllers\\SupportController::ask -> App\\Ai\\Agents\\SupportAgent')
        ->toContain('App\\Jobs\\TranslateJob::handle -> App\\Ai\\Agents\\TranslationAgent');
});

it('does not treat an agent listed in another agent tools() as a caller', function () {
    $result = (new AiAnalyzer)->analyze(fixture('laravel-ai-project'));

    foreach ($result['callSites'] as $site) {
        expect($site->callerFqcn)->not->toBe('App\\Ai\\Agents\\RouterAgent');
    }
});
