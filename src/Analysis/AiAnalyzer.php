<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * One `laravel/ai` agent class: the LLM call itself, plus every input that decides
 * which model answers it and what it is allowed to do while answering.
 */
class AiAgentDefinition
{
    public function __construct(
        public string $fqcn,
        public string $file,
        /** @var list<string> short names of the SDK contracts the class implements, e.g. ['Agent', 'HasTools'] */
        public array $contracts,
        /** Concrete model id, when one is knowable without running the app. */
        public ?string $model,
        /** 'smartest'|'cheapest' when the class only picks a tier and the id comes from the provider. */
        public ?string $modelTier,
        /** Where the model came from: 'method'|'attribute'|'tier'|'provider-default'. */
        public string $modelSource,
        /** A `#[Model]` value that a `model()` method makes unreachable — see {@see AiAnalyzer}. */
        public ?string $shadowedModelAttribute,
        public ?string $provider,
        /** 'method'|'attribute'|'config-default'. */
        public string $providerSource,
        public ?string $shadowedProviderAttribute,
        public ?int $maxSteps,
        public ?int $maxTokens,
        public ?float $temperature,
        public ?float $topP,
        public ?int $timeout,
        /** @var list<string> knobs the class can still decide at runtime, because it declares (or inherits) a method of that name */
        public array $methodOverrides,
        public bool $strict,
        public bool $repairToolCalls,
        public bool $withoutBroadcasting,
        /** @var list<string> FQCNs returned by tools() that Brain recognised as tools */
        public array $tools,
        /** @var list<string> FQCNs returned by tools() that are agents themselves (the SDK wraps these in AgentTool) */
        public array $toolAgents,
        /** @var list<string> FQCNs returned by tools() that Brain could not classify */
        public array $unresolvedTools,
        /**
         * Whether tools() builds its list from something no static reading can enumerate — a
         * constructor-injected property, a container call, a generator. True means "this agent
         * has tools and Brain cannot name them", which is a different statement from an empty
         * tools list and has to reach the reader as one.
         */
        public bool $toolsAreDynamic,
        /** @var list<string> tool FQCNs handed to the agent's constructor where it is built */
        public array $injectedTools,
        /** Whether the class implements HasTools — without it the SDK never calls tools() at all. */
        public bool $declaresHasTools,
        /** An abstract base carries configuration for readers, but is never itself prompted. */
        public bool $isAbstract,
    ) {}
}

/**
 * One class an agent may hand to the model as a callable tool.
 */
class AiToolDefinition
{
    public function __construct(
        public string $fqcn,
        public string $file,
        /** 'ai' for `Laravel\Ai\Contracts\Tool`, 'mcp' for a `Laravel\Mcp\Server\Tool` the SDK wraps. */
        public string $kind,
        /** The literal returned by description(), when it is a literal. */
        public string $description = '',
        public bool $isAbstract = false,
    ) {}
}

/**
 * A method that names an agent class, i.e. a place where the application talks to an LLM.
 */
class AiAgentCallSite
{
    public function __construct(
        public string $callerFqcn,
        public string $callerMethod,
        public string $agentFqcn,
        /**
         * Classes named inside the argument list of a `new <Agent>(...)` at this site. An agent
         * whose own tools() is unreadable is often handed its tools right here.
         *
         * @var list<string>
         */
        public array $constructionArgClasses = [],
    ) {}
}

/**
 * Finds `laravel/ai` agents and the tools they expose to the model.
 *
 * `laravel/ai` is optional and will be absent from almost every application Brain scans, so
 * nothing here may touch one of its classes: detection is by fully-qualified name written as a
 * string and matched against the AST. That is not a stylistic preference — a `use` of a missing
 * class in a code path that runs is a fatal error, and this analyzer runs on every scan.
 *
 * Two passes, in this order, because the first one is also the feature gate:
 *
 *  1. Seed pass. Every source file is read and tested for the literal `Laravel\Ai\` (or
 *     `Laravel\Mcp\`) with str_contains, and only the files that match are parsed. A class is an
 *     agent when it implements `Laravel\Ai\Contracts\Agent` or uses the `Laravel\Ai\Promptable`
 *     trait; a tool when it implements `Laravel\Ai\Contracts\Tool` / `CanActAsTool` or extends
 *     `Laravel\Mcp\Server\Tool`. No seed agent means the application does not use the SDK, and
 *     analyze() returns detected=false having parsed nothing at all.
 *  2. Reference pass, only when something was seeded. Files are prefiltered on the short names of
 *     the classes found so far, so this pass sees `class SummaryAgent extends BaseAgent` (whose
 *     file never mentions the SDK) and every method that names an agent. Newly promoted
 *     subclasses widen the short-name set, so the pass repeats until it stops finding classes —
 *     an application with a base agent, a base tool and their children converges in two rounds.
 *
 * What is deliberately not modelled: the SDK's domain events (`AgentPrompted`, `AgentFailed`, …).
 * They are dispatched from inside the vendor package on every run, so as graph edges they would
 * be the same edge for every agent in the project and would say nothing about this application's
 * wiring. An application listener for one of them is already a listener node via the normal
 * listener scan, which is where it belongs.
 */
class AiAnalyzer
{
    public const AGENT_CONTRACT = 'Laravel\\Ai\\Contracts\\Agent';

    public const PROMPTABLE_TRAIT = 'Laravel\\Ai\\Promptable';

    public const TOOL_CONTRACT = 'Laravel\\Ai\\Contracts\\Tool';

    public const CAN_ACT_AS_TOOL_CONTRACT = 'Laravel\\Ai\\Contracts\\CanActAsTool';

    /**
     * Tools are as often MCP server tools as native ones: `laravel/ai` accepts a
     * `Laravel\Mcp\Server\Tool` straight from tools() and wraps it in its own McpServerTool.
     * Recognising only the native contract would miss most of the tools in a real application.
     */
    public const MCP_SERVER_TOOL = 'Laravel\\Mcp\\Server\\Tool';

    /**
     * Substrings that make a file worth parsing in the seed pass.
     *
     * @var list<string>
     */
    private const SEED_MARKERS = ['Laravel\\Ai\\', 'Laravel\\Mcp\\'];

    /**
     * SDK contracts worth reporting on an agent node, keyed by FQCN. The value is what the
     * sidebar shows, so it is the short name a reader would recognise from the class's own
     * `implements` clause.
     *
     * @var array<string, string>
     */
    private const REPORTED_CONTRACTS = [
        'Laravel\\Ai\\Contracts\\Agent' => 'Agent',
        'Laravel\\Ai\\Contracts\\HasTools' => 'HasTools',
        'Laravel\\Ai\\Contracts\\HasStructuredOutput' => 'HasStructuredOutput',
        'Laravel\\Ai\\Contracts\\Conversational' => 'Conversational',
        'Laravel\\Ai\\Contracts\\RemembersConversations' => 'RemembersConversations',
        'Laravel\\Ai\\Contracts\\HasMiddleware' => 'HasMiddleware',
        'Laravel\\Ai\\Contracts\\Approvable' => 'Approvable',
        'Laravel\\Ai\\Contracts\\CanActAsTool' => 'CanActAsTool',
        'Laravel\\Ai\\Contracts\\Schemable' => 'Schemable',
        'Laravel\\Ai\\Contracts\\HasProviderOptions' => 'HasProviderOptions',
    ];

    /**
     * Class attributes carrying a single value, keyed by FQCN => the knob they set.
     *
     * @var array<string, string>
     */
    private const VALUE_ATTRIBUTES = [
        'Laravel\\Ai\\Attributes\\Model' => 'model',
        'Laravel\\Ai\\Attributes\\Provider' => 'provider',
        'Laravel\\Ai\\Attributes\\MaxSteps' => 'maxSteps',
        'Laravel\\Ai\\Attributes\\MaxTokens' => 'maxTokens',
        'Laravel\\Ai\\Attributes\\Temperature' => 'temperature',
        'Laravel\\Ai\\Attributes\\TopP' => 'topP',
        'Laravel\\Ai\\Attributes\\Timeout' => 'timeout',
    ];

    /**
     * Class attributes that carry no value — their presence is the whole statement.
     *
     * @var array<string, string>
     */
    private const FLAG_ATTRIBUTES = [
        'Laravel\\Ai\\Attributes\\UseSmartestModel' => 'smartest',
        'Laravel\\Ai\\Attributes\\UseCheapestModel' => 'cheapest',
        'Laravel\\Ai\\Attributes\\Strict' => 'strict',
        'Laravel\\Ai\\Attributes\\RepairToolCalls' => 'repairToolCalls',
        'Laravel\\Ai\\Attributes\\WithoutBroadcasting' => 'withoutBroadcasting',
    ];

    /**
     * Knobs an agent may also decide with a method of the same name.
     *
     * @var list<string>
     */
    private const KNOB_METHODS = ['model', 'provider', 'maxSteps', 'maxTokens', 'temperature', 'topP', 'timeout'];

    /**
     * A round cap for the reference pass. Growth is monotone over a finite set of classes, so the
     * loop terminates on its own; the cap only bounds a pathological hierarchy.
     */
    private const MAX_REFERENCE_ROUNDS = 8;

    private PhpFileParser $parser;

    private NodeFinder $finder;

    /** @var string[] directories holding application classes, relative to the project root */
    private array $paths;

    private bool $enabled;

    /**
     * Every class named by every class the scan parsed, kept so a "tool provider" — a class whose
     * job is to build a list of tools for somebody else — can be recognised once the tool set is
     * known. Populated during the scan and read at the end of it.
     *
     * @var array<string, list<string>>
     */
    private array $classToolRefs = [];

    /**
     * @param  string[]  $paths  directories (or glob patterns) holding application classes
     * @param  bool  $enabled  the `laravel-brain.ai.enabled` switch, which is a different question
     *                         from whether the application uses the SDK: off means "I use it and
     *                         still do not want it on the graph"
     */
    public function __construct(
        array $paths = SourceDirectories::DEFAULT_SOURCE_PATHS,
        bool $enabled = true,
    ) {
        $this->parser = new PhpFileParser;
        $this->finder = new NodeFinder;
        $this->paths = $paths !== [] ? $paths : SourceDirectories::DEFAULT_SOURCE_PATHS;
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The shape analyze() returns when there is nothing to report, shared with callers that skip
     * the pass entirely so they need not spell it out a second time.
     *
     * @return array{
     *   detected: bool,
     *   agents: AiAgentDefinition[],
     *   tools: AiToolDefinition[],
     *   callSites: AiAgentCallSite[],
     * }
     */
    public static function emptyResult(): array
    {
        return ['detected' => false, 'agents' => [], 'tools' => [], 'callSites' => []];
    }

    /**
     * @return array{
     *   detected: bool,
     *   agents: AiAgentDefinition[],
     *   tools: AiToolDefinition[],
     *   callSites: AiAgentCallSite[],
     * }
     */
    public function analyze(string $projectRoot): array
    {
        $empty = self::emptyResult();

        // Before the directory scan, not after it: switched off has to cost nothing, rather than
        // doing the work and dropping the answer.
        if (! $this->enabled) {
            return $empty;
        }

        $this->classToolRefs = [];

        $files = $this->phpFiles($projectRoot);
        if ($files === []) {
            return $empty;
        }

        /** @var array<string, AiAgentDefinition> $agents */
        $agents = [];
        /** @var array<string, AiToolDefinition> $tools */
        $tools = [];

        // Call sites are not collected here: the agent set is still being discovered, so a
        // reference read now could be measured against a set that does not yet hold the agent.
        $seedPassCollectsNoCallSites = null;

        foreach ($files as $file) {
            if (! $this->fileMatches($file, self::SEED_MARKERS)) {
                continue;
            }
            $this->collectFromFile($file, $agents, $tools, $seedPassCollectsNoCallSites);
        }

        if ($agents === []) {
            return $empty;
        }

        $callSites = $this->referencePass($files, $agents, $tools);

        // Resolution of tools() references is deferred to here: the reference pass can promote a
        // tool subclass long after the agent that returns it was read, and an agent whose tools
        // were classified mid-scan would report them as unresolved for no reason other than
        // scan order.
        foreach ($agents as $agent) {
            $this->classifyToolReferences($agent, $agents, $tools);
        }

        $this->attributeInjectedTools($agents, $tools, $callSites);
        $this->classToolRefs = [];

        // Abstract bases stay in the lookup maps — subclass promotion and inheritance need them —
        // but they are not returned. Nothing prompts an abstract agent, and because PHP does not
        // hand its attributes down, a node for one would state configuration that applies to
        // exactly nothing.
        return [
            'detected' => true,
            'agents' => array_values(array_filter($agents, static fn (AiAgentDefinition $a): bool => ! $a->isAbstract)),
            'tools' => array_values(array_filter($tools, static fn (AiToolDefinition $t): bool => ! $t->isAbstract)),
            'callSites' => $callSites,
        ];
    }

    /**
     * Widen the class set to subclasses of what is already known, and record every method that
     * names an agent, until a round adds no class.
     *
     * @param  list<string>  $files
     * @param  array<string, AiAgentDefinition>  $agents
     * @param  array<string, AiToolDefinition>  $tools
     * @return AiAgentCallSite[]
     */
    private function referencePass(array $files, array &$agents, array &$tools): array
    {
        /** @var array<string, AiAgentCallSite> $callSites */
        $callSites = [];

        for ($round = 0; $round < self::MAX_REFERENCE_ROUNDS; $round++) {
            $shortNames = $this->shortNames(array_merge(array_keys($agents), array_keys($tools)));
            $before = count($agents) + count($tools);

            foreach ($files as $file) {
                if (! $this->fileMatches($file, $shortNames)) {
                    continue;
                }
                $this->collectFromFile($file, $agents, $tools, $callSites);
            }

            if (count($agents) + count($tools) === $before) {
                break;
            }
        }

        // A tools() body naming another agent is the SDK's agent-as-tool form, not a caller.
        $out = [];
        foreach ($callSites as $site) {
            if ($site->callerMethod === 'tools' && isset($agents[$site->callerFqcn])) {
                continue;
            }
            $out[] = $site;
        }

        return $out;
    }

    /**
     * Read one file, recording any agent or tool it declares and — when $callSites is given —
     * every method in it that names an agent already known.
     *
     * @param  array<string, AiAgentDefinition>  $agents
     * @param  array<string, AiToolDefinition>  $tools
     * @param  array<string, AiAgentCallSite>|null  $callSites
     */
    private function collectFromFile(string $file, array &$agents, array &$tools, ?array &$callSites): void
    {
        $parsed = $this->parser->parse($file);
        $ast = $parsed['ast'];
        if ($ast === null) {
            return;
        }
        $useMap = $parsed['useMap'];

        foreach ($this->classesIn($ast) as [$fqcn, $class]) {
            $parentFqcn = $class->extends instanceof Node\Name ? self::resolve($class->extends, $useMap) : null;

            if (! isset($agents[$fqcn]) && $this->isAgentClass($class, $useMap, $agents)) {
                $agents[$fqcn] = $this->buildAgent(
                    $fqcn, $file, $class, $useMap,
                    $parentFqcn !== null ? ($agents[$parentFqcn] ?? null) : null,
                );
            } elseif (! isset($tools[$fqcn]) && $this->isToolClass($class, $useMap, $tools)) {
                $tools[$fqcn] = $this->buildTool(
                    $fqcn, $file, $class, $useMap,
                    $parentFqcn !== null ? ($tools[$parentFqcn] ?? null) : null,
                );
            }

            $this->classToolRefs[$fqcn] = $this->referencedClasses($class, $useMap);

            if ($callSites !== null) {
                foreach ($this->agentReferencesIn($class, $useMap, $agents) as [$method, $agentFqcn, $argClasses]) {
                    if ($agentFqcn === $fqcn) {
                        continue;
                    }
                    $key = $fqcn.'::'.$method.'::'.$agentFqcn;
                    $callSites[$key] = new AiAgentCallSite($fqcn, $method, $agentFqcn, $argClasses);
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $useMap
     * @param  array<string, AiAgentDefinition>  $agents
     */
    private function isAgentClass(Node\Stmt\Class_ $class, array $useMap, array $agents): bool
    {
        foreach ($class->implements as $interface) {
            if (self::resolve($interface, $useMap) === self::AGENT_CONTRACT) {
                return true;
            }
        }

        foreach ($this->traitNames($class, $useMap) as $trait) {
            if ($trait === self::PROMPTABLE_TRAIT) {
                return true;
            }
        }

        $parent = $class->extends instanceof Node\Name ? self::resolve($class->extends, $useMap) : null;

        return $parent !== null && isset($agents[$parent]);
    }

    /**
     * @param  array<string, string>  $useMap
     * @param  array<string, AiToolDefinition>  $tools
     */
    private function isToolClass(Node\Stmt\Class_ $class, array $useMap, array $tools): bool
    {
        foreach ($class->implements as $interface) {
            $name = self::resolve($interface, $useMap);
            if ($name === self::TOOL_CONTRACT || $name === self::CAN_ACT_AS_TOOL_CONTRACT) {
                return true;
            }
        }

        $parent = $class->extends instanceof Node\Name ? self::resolve($class->extends, $useMap) : null;

        return $parent === self::MCP_SERVER_TOOL || ($parent !== null && isset($tools[$parent]));
    }

    /**
     * Build one agent, folding in what it inherits from a base agent already scanned.
     *
     * What is inherited and what is not was measured against v0.11.0 rather than assumed, and the
     * two halves do not agree: `(new ReflectionClass($child))->getAttributes()` on a child of a
     * class carrying `#[Model('base-model')] #[MaxSteps(7)]` returns an EMPTY array, so the child
     * resolves to no model and no step budget — PHP does not inherit class attributes and the SDK
     * reads them with plain getAttributes(). Methods and interfaces, which the SDK reaches through
     * `method_exists()` and `instanceof`, are inherited normally.
     *
     * So: contracts, knob methods and tools() come down from the parent; attributes do not. A base
     * agent's `#[Model]` really is inert for its children, and reporting it on them would be a
     * fabrication.
     *
     * @param  array<string, string>  $useMap
     */
    private function buildAgent(
        string $fqcn,
        string $file,
        Node\Stmt\Class_ $class,
        array $useMap,
        ?AiAgentDefinition $parent,
    ): AiAgentDefinition {
        $values = [];
        $flags = [];
        $this->readAttributes($class, $useMap, $values, $flags);

        $declared = $this->methodNames($class);
        $inherited = $parent !== null ? $parent->methodOverrides : [];
        $methods = array_values(array_unique(array_merge($declared, $inherited)));
        $overrides = array_values(array_filter(
            self::KNOB_METHODS,
            static fn (string $knob): bool => in_array($knob, $methods, true),
        ));

        [$model, $modelTier, $modelSource, $shadowedModel] = $this->resolveModel($class, $values, $flags, $declared, $parent);
        [$provider, $providerSource, $shadowedProvider] = $this->resolveProvider($class, $values, $declared, $parent);

        if (in_array('tools', $declared, true)) {
            $toolRefs = $this->referencedClasses($this->method($class, 'tools'), $useMap);
        } else {
            $toolRefs = $parent !== null
                ? array_merge($parent->tools, $parent->toolAgents, $parent->unresolvedTools)
                : [];
        }

        $contracts = array_values(array_unique(array_merge(
            $this->reportedContracts($class, $useMap),
            $parent !== null ? $parent->contracts : [],
        )));

        return new AiAgentDefinition(
            fqcn: $fqcn,
            file: $file,
            contracts: $contracts,
            model: $model,
            modelTier: $modelTier,
            modelSource: $modelSource,
            shadowedModelAttribute: $shadowedModel,
            provider: $provider,
            providerSource: $providerSource,
            shadowedProviderAttribute: $shadowedProvider,
            maxSteps: self::intOrNull($values['maxSteps'] ?? null),
            maxTokens: self::intOrNull($values['maxTokens'] ?? null),
            temperature: self::floatOrNull($values['temperature'] ?? null),
            topP: self::floatOrNull($values['topP'] ?? null),
            timeout: self::intOrNull($values['timeout'] ?? null),
            methodOverrides: $overrides,
            strict: isset($flags['strict']),
            repairToolCalls: isset($flags['repairToolCalls']),
            withoutBroadcasting: isset($flags['withoutBroadcasting']),
            // Left unclassified until every file has been read; see analyze().
            tools: $toolRefs,
            toolAgents: [],
            unresolvedTools: [],
            toolsAreDynamic: in_array('tools', $declared, true)
                ? $this->toolsBodyIsUnreadable($this->method($class, 'tools'))
                : ($parent !== null && $parent->toolsAreDynamic),
            // Filled once every call site is known; see attributeInjectedTools().
            injectedTools: [],
            declaresHasTools: in_array('HasTools', $contracts, true),
            isAbstract: $class->isAbstract(),
        );
    }

    /**
     * The effective model, as far as it is knowable without running the application.
     *
     * The SDK resolves it in `Promptable::getProvidersAndModels()`, and the shape of that code
     * decides what can honestly be reported here. It is an if/else, not a coalesce: when the
     * class declares a `model()` method the `#[Model]` attribute is never read at all. A method
     * returning null therefore leaves the agent with NO model — verified against v0.11.0, where
     * a class carrying `#[Model('attr-model')]` and a `model(): ?string { return null; }`
     * resolves to `['openai' => null]`, i.e. the provider's default, not 'attr-model'. That is
     * worth surfacing rather than smoothing over, so the shadowed attribute is reported
     * separately instead of being printed as the answer.
     *
     * Only when neither is present does a tier attribute apply, and a tier is not a model id:
     * `#[UseSmartestModel]` becomes `$provider->smartestTextModel()`, whose value depends on the
     * provider chosen at runtime and on `config('ai.<lab>.models.text.smartest')`. There is no
     * honest static answer, so the tier is reported as a tier.
     *
     * @param  array<string, string|float|int>  $values
     * @param  array<string, true>  $flags
     * @param  list<string>  $declaredMethods  methods written on this class, not its parent's
     * @return array{0: ?string, 1: ?string, 2: string, 3: ?string}
     */
    private function resolveModel(
        Node\Stmt\Class_ $class,
        array $values,
        array $flags,
        array $declaredMethods,
        ?AiAgentDefinition $parent,
    ): array {
        $attribute = isset($values['model']) ? (string) $values['model'] : null;

        if (in_array('model', $declaredMethods, true)) {
            $literal = $this->returnedString($this->method($class, 'model'));

            return [$literal, null, 'method', $attribute];
        }

        // An inherited model() shadows this class's own #[Model] just as a declared one would:
        // method_exists() does not care which class in the chain wrote it.
        if ($parent !== null && in_array('model', $parent->methodOverrides, true)) {
            return [$parent->model, null, 'method', $attribute];
        }

        if ($attribute !== null) {
            return [$attribute, null, 'attribute', null];
        }

        if (isset($flags['smartest'])) {
            return [null, 'smartest', 'tier', null];
        }

        if (isset($flags['cheapest'])) {
            return [null, 'cheapest', 'tier', null];
        }

        return [null, null, 'provider-default', null];
    }

    /**
     * The provider, with the same method-shadows-attribute rule as the model, and the same
     * fallback: no declaration at all means `config('ai.default')` decides.
     *
     * @param  array<string, string|float|int>  $values
     * @param  list<string>  $declaredMethods
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private function resolveProvider(
        Node\Stmt\Class_ $class,
        array $values,
        array $declaredMethods,
        ?AiAgentDefinition $parent,
    ): array {
        $attribute = isset($values['provider']) ? (string) $values['provider'] : null;

        if (in_array('provider', $declaredMethods, true)) {
            return [$this->returnedString($this->method($class, 'provider')), 'method', $attribute];
        }

        if ($parent !== null && in_array('provider', $parent->methodOverrides, true)) {
            return [$parent->provider, 'method', $attribute];
        }

        if ($attribute !== null) {
            return [$attribute, 'attribute', null];
        }

        return [null, 'config-default', null];
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private function buildTool(
        string $fqcn,
        string $file,
        Node\Stmt\Class_ $class,
        array $useMap,
        ?AiToolDefinition $parent,
    ): AiToolDefinition {
        $extends = $class->extends instanceof Node\Name ? self::resolve($class->extends, $useMap) : null;
        $kind = $extends === self::MCP_SERVER_TOOL ? 'mcp' : ($parent !== null ? $parent->kind : 'ai');

        return new AiToolDefinition(
            fqcn: $fqcn,
            file: $file,
            kind: $kind,
            // An MCP server tool states its description as a `protected string $description`
            // property rather than a method, so both spellings are read.
            description: $this->returnedString($this->method($class, 'description'))
                ?? $this->propertyString($class, 'description')
                ?? ($parent !== null ? $parent->description : ''),
            isAbstract: $class->isAbstract(),
        );
    }

    /**
     * Split an agent's raw tools() references into recognised tools, agents used as tools, and
     * everything else.
     *
     * @param  array<string, AiAgentDefinition>  $agents
     * @param  array<string, AiToolDefinition>  $tools
     */
    private function classifyToolReferences(AiAgentDefinition $agent, array $agents, array $tools): void
    {
        $recognised = [];
        $asAgents = [];
        $unresolved = [];

        foreach ($agent->tools as $fqcn) {
            if (isset($tools[$fqcn])) {
                $recognised[] = $fqcn;
            } elseif (isset($agents[$fqcn]) && $fqcn !== $agent->fqcn) {
                $asAgents[] = $fqcn;
            } elseif ($fqcn !== $agent->fqcn) {
                $unresolved[] = $fqcn;
            }
        }

        $agent->tools = $recognised;
        $agent->toolAgents = $asAgents;
        $agent->unresolvedTools = $unresolved;
    }

    /**
     * Whether tools() decides its list somewhere no static reading can follow.
     *
     * The distinction this draws is the whole point: `return [];` is an agent with no tools, and
     * `return $this->tools;` is an agent WITH tools that cannot be named here. Both leave the
     * resolved list empty, and reporting them the same way tells the reader nothing about the
     * second — which is the shape every constructor-injected agent has.
     *
     * A body with no return at all (a generator, say) counts as unreadable for the same reason.
     */
    private function toolsBodyIsUnreadable(?Node\Stmt\ClassMethod $method): bool
    {
        if ($method === null || $method->stmts === null) {
            return false;
        }

        /** @var Node\Stmt\Return_[] $returns */
        $returns = $this->finder->findInstanceOf($method, Node\Stmt\Return_::class);

        if ($returns === []) {
            return $method->stmts !== [];
        }

        foreach ($returns as $return) {
            if (! $return->expr instanceof Node\Expr\Array_) {
                return true;
            }

            foreach ($return->expr->items as $item) {
                if ($item === null || $item->unpack || ! self::isClassReference($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether an expression names a class outright, in one of the forms referencedClasses() reads.
     */
    private static function isClassReference(Node\Expr $expr): bool
    {
        return ($expr instanceof Node\Expr\New_ && $expr->class instanceof Node\Name)
            || ($expr instanceof Node\Expr\StaticCall && $expr->class instanceof Node\Name)
            || ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name);
    }

    /**
     * Give an agent the tools it is handed where it is constructed.
     *
     * An agent that takes its tools through the constructor cannot name them itself, but the code
     * that builds it can, and often does so through a provider: `new ChatAssistantAgent(tools:
     * resolve(ChatToolProvider::class)->toolsFor($user))`. Any class named inside those arguments
     * whose own body instantiates recognised tools is treated as supplying them.
     *
     * A class that is itself an agent or a tool is never treated as a provider — an agent naming
     * its own tools is tools(), which is already read, and it must not be double-counted as a
     * bundle for whoever constructs it.
     *
     * @param  array<string, AiAgentDefinition>  $agents
     * @param  array<string, AiToolDefinition>  $tools
     * @param  AiAgentCallSite[]  $callSites
     */
    private function attributeInjectedTools(array $agents, array $tools, array $callSites): void
    {
        /** @var array<string, list<string>> $providers */
        $providers = [];

        foreach ($this->classToolRefs as $owner => $refs) {
            if (isset($agents[$owner]) || isset($tools[$owner])) {
                continue;
            }
            $provided = array_values(array_filter($refs, static fn (string $ref): bool => isset($tools[$ref])));
            if ($provided !== []) {
                $providers[$owner] = $provided;
            }
        }

        foreach ($callSites as $site) {
            $agent = $agents[$site->agentFqcn] ?? null;
            if ($agent === null) {
                continue;
            }

            foreach ($site->constructionArgClasses as $named) {
                // A tool passed straight into the constructor counts too, not just a provider.
                $supplied = isset($tools[$named]) ? [$named] : ($providers[$named] ?? []);

                foreach ($supplied as $toolFqcn) {
                    if (! in_array($toolFqcn, $agent->injectedTools, true)
                        && ! in_array($toolFqcn, $agent->tools, true)) {
                        $agent->injectedTools[] = $toolFqcn;
                    }
                }
            }
        }
    }

    /**
     * Methods of $class that name one of the known agents, in any statically resolvable form:
     * `new Agent`, `Agent::class`, `Agent::make()`, or an `Agent` type on a parameter.
     *
     * @param  array<string, string>  $useMap
     * @param  array<string, AiAgentDefinition>  $agents
     * @return list<array{0: string, 1: string, 2: list<string>}> [methodName, agentFqcn, constructionArgClasses]
     */
    private function agentReferencesIn(Node\Stmt\Class_ $class, array $useMap, array $agents): array
    {
        $found = [];

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\ClassMethod) {
                continue;
            }

            $method = $stmt->name->toString();

            $record = static function (string $fqcn, array $argClasses) use (&$found, $method, $agents): void {
                if (! isset($agents[$fqcn])) {
                    return;
                }
                $key = $method.'::'.$fqcn;
                $found[$key] = [
                    $method,
                    $fqcn,
                    array_values(array_unique(array_merge($found[$key][2] ?? [], $argClasses))),
                ];
            };

            // Constructions first, and separately from the rest: the argument list of a
            // `new ChatAssistantAgent(instructions: …, tools: …)` is where an agent that builds
            // its tools from an injected property is actually given them, and that is only
            // visible while the New_ node is in hand.
            /** @var Node\Expr\New_[] $constructions */
            $constructions = $this->finder->findInstanceOf($stmt, Node\Expr\New_::class);
            foreach ($constructions as $construction) {
                if ($construction->class instanceof Node\Name) {
                    $record(
                        self::resolve($construction->class, $useMap),
                        $this->referencedClasses($construction->args, $useMap),
                    );
                }
            }

            foreach ($this->referencedClasses($stmt, $useMap) as $fqcn) {
                $record($fqcn, []);
            }

            foreach ($stmt->params as $param) {
                $type = $param->type;
                if ($type instanceof Node\Name) {
                    $record(self::resolve($type, $useMap), []);
                }
            }
        }

        return array_values($found);
    }

    /**
     * Every class name a node's subtree names outright: `new X`, `X::class`, `X::make()`.
     *
     * A variable, a property or a container binding string is not resolvable here, which is why
     * an agent's tools() can legitimately come back short — those references land in
     * unresolvedTools rather than being guessed at.
     *
     * @param  Node|Node[]|null  $node
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    private function referencedClasses($node, array $useMap): array
    {
        if ($node === null || $node === []) {
            return [];
        }

        $found = [];

        /** @var Node\Expr\New_[] $news */
        $news = $this->finder->findInstanceOf($node, Node\Expr\New_::class);
        foreach ($news as $new) {
            if ($new->class instanceof Node\Name) {
                $found[] = self::resolve($new->class, $useMap);
            }
        }

        /** @var Node\Expr\ClassConstFetch[] $fetches */
        $fetches = $this->finder->findInstanceOf($node, Node\Expr\ClassConstFetch::class);
        foreach ($fetches as $fetch) {
            if ($fetch->class instanceof Node\Name && $fetch->name instanceof Node\Identifier
                && $fetch->name->toString() === 'class') {
                $found[] = self::resolve($fetch->class, $useMap);
            }
        }

        /** @var Node\Expr\StaticCall[] $calls */
        $calls = $this->finder->findInstanceOf($node, Node\Expr\StaticCall::class);
        foreach ($calls as $call) {
            if ($call->class instanceof Node\Name) {
                $found[] = self::resolve($call->class, $useMap);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Read the SDK class attributes into a value map and a flag set.
     *
     * @param  array<string, string>  $useMap
     * @param  array<string, string|float|int>  $values
     * @param  array<string, true>  $flags
     */
    private function readAttributes(Node\Stmt\Class_ $class, array $useMap, array &$values, array &$flags): void
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = self::resolve($attribute->name, $useMap);

                if (isset(self::FLAG_ATTRIBUTES[$name])) {
                    $flags[self::FLAG_ATTRIBUTES[$name]] = true;

                    continue;
                }

                if (! isset(self::VALUE_ATTRIBUTES[$name]) || $attribute->args === []) {
                    continue;
                }

                $value = self::scalarValue($attribute->args[0]->value);
                if ($value !== null) {
                    $values[self::VALUE_ATTRIBUTES[$name]] = $value;
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    private function reportedContracts(Node\Stmt\Class_ $class, array $useMap): array
    {
        $found = [];

        foreach ($class->implements as $interface) {
            $name = self::resolve($interface, $useMap);
            if (isset(self::REPORTED_CONTRACTS[$name])) {
                $found[] = self::REPORTED_CONTRACTS[$name];
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The string a method returns when its body is a single literal return; null for anything
     * that needs the application running to know.
     */
    private function returnedString(?Node\Stmt\ClassMethod $method): ?string
    {
        if ($method === null || $method->stmts === null) {
            return null;
        }

        foreach ($method->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Return_ || $stmt->expr === null) {
                continue;
            }

            $value = self::scalarValue($stmt->expr);

            return is_string($value) ? $value : null;
        }

        return null;
    }

    /**
     * The literal default of a string property, when it has one.
     */
    private function propertyString(Node\Stmt\Class_ $class, string $name): ?string
    {
        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\Property) {
                continue;
            }
            foreach ($stmt->props as $property) {
                if ($property->name->toString() !== $name || $property->default === null) {
                    continue;
                }
                $value = self::scalarValue($property->default);

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }

    /**
     * A literal argument's value: a string, an int, a float, or a backed enum case whose value
     * is a literal (`Lab::Anthropic` is how a provider is usually written).
     */
    private static function scalarValue(Node $node): string|int|float|null
    {
        if ($node instanceof Node\Scalar\String_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Int_) {
            return $node->value;
        }
        if ($node instanceof Node\Scalar\Float_) {
            return $node->value;
        }
        if ($node instanceof Node\Expr\ClassConstFetch
            && $node->class instanceof Node\Name
            && $node->name instanceof Node\Identifier
            && $node->name->toString() !== 'class') {
            // An enum case: report the case name, which is what the reader wrote.
            return $node->name->toString();
        }

        return null;
    }

    private static function intOrNull(string|int|float|null $value): ?int
    {
        return is_int($value) || is_float($value) ? (int) $value : null;
    }

    private static function floatOrNull(string|int|float|null $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private static function resolve(Node\Name $name, array $useMap): string
    {
        $written = $name->toString();
        $resolved = PhpFileParser::resolvedName($name) ?? ($useMap[$written] ?? $written);

        return ltrim($resolved, '\\');
    }

    /**
     * @param  array<string, string>  $useMap
     * @return list<string>
     */
    private function traitNames(Node\Stmt\Class_ $class, array $useMap): array
    {
        $names = [];

        foreach ($class->stmts as $stmt) {
            if (! $stmt instanceof Node\Stmt\TraitUse) {
                continue;
            }
            foreach ($stmt->traits as $trait) {
                $names[] = self::resolve($trait, $useMap);
            }
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function methodNames(Node\Stmt\Class_ $class): array
    {
        $names = [];

        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                $names[] = $stmt->name->toString();
            }
        }

        return $names;
    }

    private function method(Node\Stmt\Class_ $class, string $name): ?Node\Stmt\ClassMethod
    {
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === $name) {
                return $stmt;
            }
        }

        return null;
    }

    /**
     * Named classes declared in a parsed file, paired with their fully-qualified names.
     *
     * @param  Node\Stmt[]  $ast
     * @return list<array{0: string, 1: Node\Stmt\Class_}>
     */
    private function classesIn(array $ast): array
    {
        $classes = [];

        /** @var Node\Stmt\Class_[] $found */
        $found = $this->finder->findInstanceOf($ast, Node\Stmt\Class_::class);
        foreach ($found as $class) {
            if ($class->name === null) {
                continue;
            }
            $namespaced = $class->namespacedName;
            $fqcn = $namespaced instanceof Node\Name
                ? ltrim($namespaced->toString(), '\\')
                : $class->name->toString();

            $classes[] = [$fqcn, $class];
        }

        return $classes;
    }

    /**
     * Whether a file's raw bytes contain any of the given needles.
     *
     * The prefilter is the whole cost of this analyzer for an application that does not use the
     * SDK: a read and a substring search per source file, and not one parse.
     *
     * @param  list<string>  $needles
     */
    private function fileMatches(string $file, array $needles): bool
    {
        if ($needles === []) {
            return false;
        }

        $code = @file_get_contents($file);
        if ($code === false) {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($code, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $fqcns
     * @return list<string>
     */
    private function shortNames(array $fqcns): array
    {
        $names = [];

        foreach ($fqcns as $fqcn) {
            $parts = explode('\\', $fqcn);
            $names[] = end($parts);
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string> absolute paths
     */
    private function phpFiles(string $projectRoot): array
    {
        $directories = SourceDirectories::resolve($projectRoot, $this->paths);

        return iterator_to_array(SourceDirectories::phpFiles($projectRoot, $directories), false);
    }
}
