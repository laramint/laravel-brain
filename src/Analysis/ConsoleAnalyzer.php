<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Analysis;

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

class ConsoleCommandDefinition
{
    public function __construct(
        public string $signature,
        public string $description,
        public string $class,       // FQCN for class-based, '' for closures
        public string $file,
        public string $source,      // 'route' | 'class' | 'kernel'
    ) {}
}

class ScheduleEntry
{
    /**
     * @param  string[]  $frequencyArguments  Literal arguments of the cadence call — ['05:30'] for dailyAt('05:30').
     * @param  string[]  $modifiers  Guard methods on the chain: withoutOverlapping, onOneServer, …
     */
    public function __construct(
        public string $type,        // 'command' | 'job' | 'call'
        public string $target,      // command signature or job FQCN
        public string $frequency,   // 'daily' | 'hourly' | etc.
        public string $file,
        public array $frequencyArguments = [],
        public array $modifiers = [],
        public string $timezone = '',
        /**
         * What a `Schedule::call(fn)` actually does.
         *
         * A closure task has no class to link to, so without this its tab is one node and
         * nothing else — the only kind of scheduled work the viewer could say nothing about.
         * The steps are read where the closure is already in hand rather than by re-parsing the
         * file at a remembered line.
         *
         * @var array<int, array<string, mixed>>
         */
        public array $flowSteps = [],
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $flowSteps
     */
    public static function fromChain(string $type, string $target, string $file, ScheduleChain $chain, array $flowSteps = []): self
    {
        return new self(
            type: $type,
            target: $target,
            frequency: $chain->frequency,
            file: $file,
            frequencyArguments: $chain->frequencyArguments,
            modifiers: $chain->modifiers,
            timezone: $chain->timezone,
            flowSteps: $flowSteps,
        );
    }

    /**
     * Id of the graph node this entry produces.
     *
     * Lives here because the builder that creates the node and the splitter that seeds a tab
     * from it both need it, and they used to spell the same hash out separately — so widening
     * the hash in one place silently detached the tab from its own node.
     *
     * The cadence arguments are part of the hash: the same command scheduled at 05:00 and at
     * 17:00 is two tasks, and hashing the method name alone collapsed them into one.
     */
    public function nodeId(): string
    {
        return 'schedule::'.md5($this->type.$this->target.$this->frequency.implode(',', $this->frequencyArguments));
    }

    /**
     * When it runs, in the shortest form that is still unambiguous: the raw expression for
     * `cron('0 3 * * *')`, "dailyAt 05:30" for a cadence that took arguments, the bare method
     * name otherwise. Empty when the chain never states one.
     */
    public function cadence(): string
    {
        if ($this->frequency === '') {
            return '';
        }

        if ($this->frequency === 'cron') {
            return $this->frequencyArguments[0] ?? 'cron';
        }

        if ($this->frequencyArguments === []) {
            return $this->frequency;
        }

        return $this->frequency.' '.implode(', ', $this->frequencyArguments);
    }
}

class ConsoleAnalyzer
{
    /**
     * The steps inside a `Schedule::call(fn)`, read from the closure the registration was given.
     *
     * Shared by both visitors — the Schedule facade form and the kernel form — because a closure
     * task is the one kind of scheduled work with no class behind it, and without this its tab
     * shows a single node and nothing about what runs every minute.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function closureFlowSteps(?Node\Arg $arg): array
    {
        $closure = $arg?->value;

        if (! $closure instanceof Node\Expr\Closure && ! $closure instanceof Node\Expr\ArrowFunction) {
            return [];
        }

        return (new FlowExtractor)->extractFromClosure($closure);
    }

    /**
     * Schedule methods that state a cadence.
     *
     * `timezone` used to be on this list and is not a cadence — it qualifies one. It was read
     * off the chain first, so `->daily()->timezone('Europe/Warsaw')` was recorded as running
     * on a frequency of "timezone"; it is read into its own field now.
     *
     * @var string[]
     */
    public const FREQUENCY_METHODS = [
        'everySecond', 'everyTwoSeconds', 'everyFiveSeconds', 'everyTenSeconds',
        'everyFifteenSeconds', 'everyTwentySeconds', 'everyThirtySeconds',
        'everyMinute', 'everyTwoMinutes', 'everyThreeMinutes', 'everyFourMinutes',
        'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes', 'everyThirtyMinutes',
        'hourly', 'hourlyAt', 'everyOddHour', 'everyTwoHours', 'everyThreeHours',
        'everyFourHours', 'everySixHours', 'daily', 'dailyAt', 'twiceDaily', 'twiceDailyAt',
        'weekly', 'weeklyOn', 'monthly', 'monthlyOn', 'twiceMonthly', 'lastDayOfMonth',
        'quarterly', 'quarterlyOn', 'yearly', 'yearlyOn', 'cron',
    ];

    /**
     * Chained guards that change whether — or where — a due task actually runs. They answer
     * the second half of "what fires at 3am": a task guarded by `withoutOverlapping()` may
     * not fire at all, and one without `onOneServer()` fires on every box in the fleet.
     *
     * @var string[]
     */
    public const MODIFIER_METHODS = [
        'withoutOverlapping', 'onOneServer', 'runInBackground', 'evenInMaintenanceMode',
    ];

    /**
     * Files that hold a scheduling closure or method.
     *
     * bootstrap/app.php is where Laravel 11 moved `->withSchedule(…)`, and a skeleton from
     * that generation has no Console Kernel at all — scanning only the legacy path found no
     * schedule in it whatsoever.
     *
     * @var string[]
     */
    public const DEFAULT_KERNEL_PATHS = ['app/Console/Kernel.php', 'bootstrap/app.php'];

    private PhpFileParser $parser;

    /** @var string[] */
    private array $consoleRoutePaths;

    /** @var string[] */
    private array $classPaths;

    /** @var string[] */
    private array $kernelPaths;

    /**
     * @param  string[]  $consoleRoutePaths  Glob patterns for closure-command route files (basename must contain "console").
     * @param  string[]  $classPaths  Glob patterns for directories containing Command classes.
     * @param  string[]  $kernelPaths  Glob patterns pointing to Console Kernel file(s).
     */
    public function __construct(
        array $consoleRoutePaths = ['routes/*/*.php'],
        array $classPaths = ['app/Console/Commands/*/*.php'],
        array $kernelPaths = self::DEFAULT_KERNEL_PATHS,
    ) {
        $this->parser = new PhpFileParser;
        $this->consoleRoutePaths = $consoleRoutePaths ?: ['routes/*/*.php'];
        $this->classPaths = $classPaths ?: ['app/Console/Commands/*/*.php'];
        $this->kernelPaths = $kernelPaths ?: self::DEFAULT_KERNEL_PATHS;
    }

    /**
     * @return array{commands: ConsoleCommandDefinition[], schedule: ScheduleEntry[]}
     */
    public function analyze(string $projectRoot): array
    {
        $commands = [];
        $schedule = [];
        $root = rtrim($projectRoot, '/');

        // 1. Closure-based commands and schedule entries. Laravel's own skeleton keeps
        //    both in routes/console.php, but a schedule split out of it is conventionally
        //    routes/schedule.php — a file the "console" keyword alone never reaches.
        foreach ($this->consoleRoutePaths as $pattern) {
            $baseDir = $this->resolveBaseDir($root, $pattern);
            foreach ($this->findFilesContaining($baseDir, ['console', 'schedule']) as $file) {
                $result = $this->parseConsoleRouteFile($file);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // 2. Command classes
        foreach ($this->classPaths as $pattern) {
            $commandsDir = $this->resolveBaseDir($root, $pattern);
            if (is_dir($commandsDir)) {
                $commands = array_merge($commands, $this->scanCommandClasses($commandsDir));
            }
        }

        // 3. Kernel.php — $commands property + schedule() method
        foreach ($this->kernelPaths as $pattern) {
            foreach ($this->resolveKernelFiles($root, $pattern) as $kernelFile) {
                $result = $this->parseKernel($kernelFile);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        // Deduplicate: class/route-sourced entries win over kernel entries.
        // Kernel.php usually re-lists classes already found in Commands/.
        // Index by signature only — one canonical entry per signature.
        $bySignature = [];
        $byFqcn = [];

        // Pass 1: index non-kernel commands (they carry the real signature + description)
        foreach ($commands as $cmd) {
            if ($cmd->source === 'kernel') {
                continue;
            }
            $bySignature[$cmd->signature] = $cmd;
            if ($cmd->class) {
                $byFqcn[$cmd->class] = $cmd;
            }
        }

        // Pass 2: add kernel entries only when not already covered
        foreach ($commands as $cmd) {
            if ($cmd->source !== 'kernel') {
                continue;
            }
            if (isset($byFqcn[$cmd->class]) || isset($byFqcn[$cmd->signature])) {
                continue;
            }
            if (isset($bySignature[$cmd->signature])) {
                continue;
            }
            $bySignature[$cmd->signature] = $cmd;
        }

        return ['commands' => array_values($bySignature), 'schedule' => $schedule];
    }

    // ── Console route file ────────────────────────────────────────────────────

    private function parseConsoleRouteFile(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            private ScheduleChainIndex $chains;

            public function __construct(private string $file)
            {
                $this->chains = new ScheduleChainIndex;
            }

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Expr\MethodCall) {
                    $this->chains->remember($node);
                }

                if (! $node instanceof Node\Expr\StaticCall) {
                    return null;
                }
                if (! $node->class instanceof Node\Name) {
                    return null;
                }

                $class = $node->class->getLast();
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

                // Artisan::command('signature', closure)
                if ($class === 'Artisan' && $method === 'command') {
                    $sig = $this->strArg($node->args[0] ?? null);
                    if ($sig !== null) {
                        $this->commands[] = new ConsoleCommandDefinition(
                            signature: $sig,
                            description: '',
                            class: '',
                            file: $this->file,
                            source: 'route',
                        );
                    }
                }

                // Schedule::command('sig')->daily(), and the job/call siblings
                if ($class === 'Schedule' && in_array($method, ['command', 'job', 'call'], true)) {
                    $target = $this->scheduleTarget($method, $node->args[0] ?? null);
                    if ($target !== null) {
                        $this->schedule[] = ScheduleEntry::fromChain(
                            $method,
                            $target,
                            $this->file,
                            $this->chains->for($node),
                            ConsoleAnalyzer::closureFlowSteps($node->args[0] ?? null),
                        );
                    }
                }

                return null;
            }

            /** What a scheduled entry points at: a signature, a job class, or a closure. */
            private function scheduleTarget(string $method, ?Node\Arg $arg): ?string
            {
                if ($method === 'call') {
                    return 'Closure';
                }

                $signature = $this->strArg($arg);
                if ($signature !== null) {
                    return $signature;
                }

                if ($arg?->value instanceof Node\Expr\ClassConstFetch
                    && $arg->value->class instanceof Node\Name
                    && $arg->value->name instanceof Node\Identifier
                    && $arg->value->name->toString() === 'class') {
                    // The parser preserves original names, so `Job::class` reads as the short
                    // name it was written with; the resolved name is on the attribute.
                    return PhpFileParser::resolvedName($arg->value->class)
                        ?? $arg->value->class->toString();
                }

                return null;
            }

            private function strArg(?Node $node): ?string
            {
                if ($node === null) {
                    return null;
                }
                $val = $node instanceof Node\Arg ? $node->value : $node;

                return $val instanceof Node\Scalar\String_ ? $val->value : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return ['commands' => $visitor->commands, 'schedule' => $visitor->schedule];
    }

    // ── Command classes ───────────────────────────────────────────────────────

    private function scanCommandClasses(string $dir): array
    {
        $commands = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $parsed = $this->parser->parse($entry->getPathname());
            if (! $parsed || ! $parsed['ast']) {
                continue;
            }

            $cmd = $this->extractCommandDefinition($parsed['ast'], $entry->getPathname());
            if ($cmd !== null) {
                $commands[] = $cmd;
            }
        }

        return $commands;
    }

    private function extractCommandDefinition(array $ast, string $file): ?ConsoleCommandDefinition
    {
        $traverser = new NodeTraverser;
        $visitor = new class($file) extends NodeVisitorAbstract
        {
            public ?ConsoleCommandDefinition $result = null;

            private ?string $namespace = null;

            private ?string $className = null;

            private ?string $signature = null;

            private ?string $description = null;

            private ?string $attributeSignature = null;

            private ?string $attributeDescription = null;

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                }
                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                    $this->readCommandAttributes($node);
                }
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $name = $prop->name->toString();
                        // $name is the pre-signature spelling; it still names the command.
                        if (($name === 'signature' || $name === 'name') && $prop->default instanceof Node\Scalar\String_) {
                            $this->signature ??= $prop->default->value;
                        }
                        if ($name === 'description' && $prop->default instanceof Node\Scalar\String_) {
                            $this->description = $prop->default->value;
                        }
                    }
                }

                return null;
            }

            public function afterTraverse(array $nodes): ?int
            {
                // A property wins over an attribute: that is the precedence Laravel itself
                // applies when a command declares both.
                $signature = $this->signature ?? $this->attributeSignature;
                $description = $this->description ?? $this->attributeDescription;

                if ($this->className && $signature !== null) {
                    $fqcn = $this->namespace
                        ? $this->namespace.'\\'.$this->className
                        : $this->className;

                    $this->result = new ConsoleCommandDefinition(
                        signature: $signature,
                        description: $description ?? '',
                        class: $fqcn,
                        file: $this->file,
                        source: 'class',
                    );
                }

                return null;
            }

            /**
             * Laravel 12 declares a command's signature and description as class
             * attributes rather than properties, and Symfony's #[AsCommand] carries the
             * same two values. A command written either way has no $signature property
             * at all, so reading properties alone finds nothing.
             */
            private function readCommandAttributes(Node\Stmt\Class_ $node): void
            {
                foreach ($node->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        switch ($attribute->name->getLast()) {
                            case 'Signature':
                                $this->attributeSignature ??= $this->attributeArg($attribute->args, 'signature', 0);
                                break;
                            case 'Description':
                                $this->attributeDescription ??= $this->attributeArg($attribute->args, 'description', 0);
                                break;
                            case 'AsCommand':
                                $this->attributeSignature ??= $this->attributeArg($attribute->args, 'name', 0);
                                $this->attributeDescription ??= $this->attributeArg($attribute->args, 'description', 1);
                                break;
                        }
                    }
                }
            }

            /**
             * Read a string attribute argument given either by name or by position.
             *
             * @param  Node\Arg[]  $args
             */
            private function attributeArg(array $args, string $name, int $position): ?string
            {
                $index = 0;

                foreach ($args as $arg) {
                    $named = $arg->name instanceof Node\Identifier && $arg->name->toString() === $name;
                    $positional = $arg->name === null && $index === $position;

                    if (($named || $positional) && $arg->value instanceof Node\Scalar\String_) {
                        return $arg->value->value;
                    }

                    if ($arg->name === null) {
                        $index++;
                    }
                }

                return null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor->result;
    }

    // ── Kernel.php ────────────────────────────────────────────────────────────

    private function parseKernel(string $file): array
    {
        $parsed = $this->parser->parse($file);
        if (! $parsed || ! $parsed['ast']) {
            return ['commands' => [], 'schedule' => []];
        }

        $useMap = $parsed['useMap'] ?? [];
        $commands = [];
        $schedule = [];

        $traverser = new NodeTraverser;
        $visitor = new class($file, $useMap) extends NodeVisitorAbstract
        {
            public array $commands = [];

            public array $schedule = [];

            /**
             * Names of the scheduler variables currently in scope, innermost last.
             *
             * Scoping the search to them is what makes matching `->call(...)` safe. Matching
             * the bare method name anywhere in the file turned every `$this->app->call($x)`
             * in a Console Kernel into a scheduled closure that runs on no cadence at all.
             *
             * @var string[]
             */
            private array $schedulers = [];

            private ScheduleChainIndex $chains;

            public function __construct(
                private string $file,
                private array $useMap,
            ) {
                $this->chains = new ScheduleChainIndex;
            }

            public function leaveNode(Node $node): ?int
            {
                if ($this->schedulerParameterName($node) !== null) {
                    array_pop($this->schedulers);
                }

                return null;
            }

            public function enterNode(Node $node): ?int
            {
                // Both scheduling containers hand the scheduler in as a parameter, so the name
                // to look for is read off the signature rather than assumed to be `$schedule`:
                // `Kernel::schedule(Schedule $s)` is as valid as the stub Laravel ships.
                $scheduler = $this->schedulerParameterName($node);
                if ($scheduler !== null) {
                    $this->schedulers[] = $scheduler;
                }

                if ($node instanceof Node\Expr\MethodCall) {
                    $this->chains->remember($node);
                }

                // protected $commands = [FooCommand::class, ...]
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        if ($prop->name->toString() !== 'commands') {
                            continue;
                        }
                        if (! $prop->default instanceof Node\Expr\Array_) {
                            continue;
                        }

                        foreach ($prop->default->items as $item) {
                            if (! $item) {
                                continue;
                            }
                            $fqcn = $this->resolveClassConst($item->value);
                            if ($fqcn) {
                                $this->commands[] = new ConsoleCommandDefinition(
                                    signature: $fqcn,
                                    description: '',
                                    class: $fqcn,
                                    file: $this->file,
                                    source: 'kernel',
                                );
                            }
                        }
                    }
                }

                // $schedule->command('sig')->daily()
                // $schedule->job(new MyJob)->hourly()
                // $schedule->call(function(){})->everyMinute()
                if ($node instanceof Node\Expr\MethodCall && $this->isSchedulerCall($node)) {
                    $method = $node->name instanceof Node\Identifier
                        ? $node->name->toString()
                        : '';

                    $target = $this->registrationTarget($method, $node->args[0] ?? null);
                    if ($target !== null) {
                        $this->schedule[] = ScheduleEntry::fromChain(
                            $method,
                            $target,
                            $this->file,
                            $this->chains->for($node),
                            ConsoleAnalyzer::closureFlowSteps($node->args[0] ?? null),
                        );
                    }
                }

                return null;
            }

            /** True when the call is a registration made ON a scheduler that is in scope. */
            private function isSchedulerCall(Node\Expr\MethodCall $node): bool
            {
                $method = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

                return in_array($method, ScheduleChainIndex::REGISTRATION_METHODS, true)
                    && $node->var instanceof Node\Expr\Variable
                    && is_string($node->var->name)
                    && in_array($node->var->name, $this->schedulers, true);
            }

            /** What a scheduled entry points at, or null when the argument says nothing usable. */
            private function registrationTarget(string $method, ?Node\Arg $arg): ?string
            {
                if ($method === 'call') {
                    return 'Closure';
                }

                if ($arg === null) {
                    return null;
                }

                if ($method === 'command') {
                    return $this->strArg($arg) ?: null;
                }

                // job() takes either an instance or a class name, and both spellings appear in
                // the wild; reading only `new Job` dropped every `job(Job::class)` entry.
                $value = $arg->value;
                if ($value instanceof Node\Expr\New_ && $value->class instanceof Node\Name) {
                    return $this->resolveClass($value->class->toString()) ?: null;
                }

                return $this->resolveClassConst($value) ?: null;
            }

            /**
             * The scheduler parameter of a node that opens a scheduling scope: the legacy
             * `Kernel::schedule()` method, or the closure handed to `withSchedule()` in the
             * modern bootstrap/app.php form.
             */
            private function schedulerParameterName(Node $node): ?string
            {
                if ($node instanceof Node\Stmt\ClassMethod && $node->name->toString() === 'schedule') {
                    return $this->firstParameterName($node->params);
                }

                if ($node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'withSchedule') {
                    $arg = $node->args[0] ?? null;
                    $closure = $arg instanceof Node\Arg ? $arg->value : null;

                    if ($closure instanceof Node\Expr\Closure || $closure instanceof Node\Expr\ArrowFunction) {
                        return $this->firstParameterName($closure->params);
                    }
                }

                return null;
            }

            /** @param  Node\Param[]  $params */
            private function firstParameterName(array $params): ?string
            {
                $first = $params[0] ?? null;

                return $first !== null && $first->var instanceof Node\Expr\Variable && is_string($first->var->name)
                    ? $first->var->name
                    : null;
            }

            private function resolveClassConst(Node $node): string
            {
                if ($node instanceof Node\Expr\ClassConstFetch
                    && $node->class instanceof Node\Name
                    && $node->name instanceof Node\Identifier
                    && $node->name->toString() === 'class') {
                    return $this->resolveClass($node->class->toString());
                }

                return '';
            }

            private function resolveClass(string $name): string
            {
                return $this->useMap[$name] ?? $name;
            }

            private function strArg(Node\Arg $arg): ?string
            {
                return $arg->value instanceof Node\Scalar\String_
                    ? $arg->value->value
                    : null;
            }
        };

        $traverser->addVisitor($visitor);
        $traverser->traverse($parsed['ast']);

        return ['commands' => $visitor->commands, 'schedule' => $visitor->schedule];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param  string[]  $keywords
     * @return string[]
     */
    private function findFilesContaining(string $dir, array $keywords): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (! $entry->isFile() || $entry->getExtension() !== 'php') {
                continue;
            }

            $basename = strtolower($entry->getBasename());
            foreach ($keywords as $keyword) {
                if (str_contains($basename, $keyword)) {
                    $files[] = $entry->getPathname();
                    break;
                }
            }
        }

        return $files;
    }

    /**
     * Resolves kernel file(s) from a pattern.
     * Patterns without wildcards are treated as literal paths.
     * Patterns with wildcards scan the resolved base dir for matching .php files.
     *
     * @return string[]
     */
    private function resolveKernelFiles(string $root, string $pattern): array
    {
        if (! str_contains($pattern, '*') && ! str_contains($pattern, '?') && ! str_contains($pattern, '[')) {
            $path = $root.'/'.ltrim($pattern, '/');

            return file_exists($path) ? [$path] : [];
        }

        $baseDir = $this->resolveBaseDir($root, $pattern);
        if (! is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }

    private function resolveBaseDir(string $root, string $pattern): string
    {
        $segments = explode('/', ltrim($pattern, '/'));
        $fixed = [];

        foreach ($segments as $segment) {
            if (str_contains($segment, '*') || str_contains($segment, '?') || str_contains($segment, '[')) {
                break;
            }
            $fixed[] = $segment;
        }

        if (! empty($fixed) && str_ends_with(end($fixed), '.php')) {
            array_pop($fixed);
        }

        $subPath = implode('/', $fixed);

        return $subPath !== '' ? $root.'/'.$subPath : $root;
    }
}
