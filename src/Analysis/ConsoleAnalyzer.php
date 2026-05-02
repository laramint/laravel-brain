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
    public function __construct(
        public string $type,        // 'command' | 'job' | 'call'
        public string $target,      // command signature or job FQCN
        public string $frequency,   // 'daily' | 'hourly' | etc.
        public string $file,
    ) {}
}

class ConsoleAnalyzer
{
    private PhpFileParser $parser;

    private ProjectStructure $projectStructure;

    public function __construct()
    {
        $this->parser = new PhpFileParser;
        $this->projectStructure = new ProjectStructure;
    }

    /**
     * @return array{commands: ConsoleCommandDefinition[], schedule: ScheduleEntry[]}
     */
    public function analyze(string $projectRoot): array
    {
        $commands = [];
        $schedule = [];

        // 1. routes/console.php (and any file with "console" in its name)
        foreach ($this->projectStructure->discoverRouteDirectories($projectRoot) as $routesDir) {
            foreach ($this->findFilesContaining($routesDir, 'console') as $file) {
                $result = $this->parseConsoleRouteFile($file);
                $commands = array_merge($commands, $result['commands']);
                $schedule = array_merge($schedule, $result['schedule']);
            }
        }

        foreach ($this->projectStructure->discoverRoots($projectRoot) as $root) {
            // 2. app/Console/Commands/** — command classes
            $commandsDir = $root.'/app/Console/Commands';
            if (is_dir($commandsDir)) {
                $commands = array_merge($commands, $this->scanCommandClasses($commandsDir));
            }

            // 3. app/Console/Kernel.php — $commands property + schedule() method
            $kernelFile = $root.'/app/Console/Kernel.php';
            if (file_exists($kernelFile)) {
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

            public function __construct(private string $file) {}

            public function enterNode(Node $node): ?int
            {
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

                // Schedule::command('sig')->daily()
                if ($class === 'Schedule' && $method === 'command') {
                    $sig = $this->strArg($node->args[0] ?? null);
                    $freq = $this->walkChainForFrequency($node);
                    if ($sig !== null) {
                        $this->schedule[] = new ScheduleEntry(
                            type: 'command',
                            target: $sig,
                            frequency: $freq,
                            file: $this->file,
                        );
                    }
                }

                return null;
            }

            private function walkChainForFrequency(Node $node): string
            {
                // Walk up the parent chain looking for frequency method names
                // The AST has the parent as the receiver of subsequent method calls
                return '';
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

            public function __construct(private string $file) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->namespace = $node->name?->toString();
                }
                if ($node instanceof Node\Stmt\Class_) {
                    $this->className = $node->name?->toString();
                }
                if ($node instanceof Node\Stmt\Property) {
                    foreach ($node->props as $prop) {
                        $name = $prop->name->toString();
                        if ($name === 'signature' && $prop->default instanceof Node\Scalar\String_) {
                            $this->signature = $prop->default->value;
                        }
                        if ($name === 'description' && $prop->default instanceof Node\Scalar\String_) {
                            $this->description = $prop->default->value;
                        }
                    }
                }

                return null;
            }

            public function afterTraverse(array $nodes): null
            {
                if ($this->className && $this->signature !== null) {
                    $fqcn = $this->namespace
                        ? $this->namespace.'\\'.$this->className
                        : $this->className;

                    $this->result = new ConsoleCommandDefinition(
                        signature: $this->signature,
                        description: $this->description ?? '',
                        class: $fqcn,
                        file: $this->file,
                        source: 'class',
                    );
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

            public function __construct(
                private string $file,
                private array $useMap,
            ) {}

            public function enterNode(Node $node): null
            {
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
                if ($node instanceof Node\Expr\MethodCall) {
                    $method = $node->name instanceof Node\Identifier
                        ? $node->name->toString()
                        : null;

                    if ($method === 'command' && ! empty($node->args)) {
                        $sig = $this->strArg($node->args[0]);
                        if ($sig) {
                            $this->schedule[] = new ScheduleEntry(
                                type: 'command',
                                target: $sig,
                                frequency: $this->chainFrequency($node),
                                file: $this->file,
                            );
                        }
                    }

                    if ($method === 'job' && ! empty($node->args)) {
                        $arg = $node->args[0]->value;
                        $target = '';
                        if ($arg instanceof Node\Expr\New_ && $arg->class instanceof Node\Name) {
                            $target = $this->resolveClass($arg->class->toString());
                        }
                        if ($target) {
                            $this->schedule[] = new ScheduleEntry(
                                type: 'job',
                                target: $target,
                                frequency: $this->chainFrequency($node),
                                file: $this->file,
                            );
                        }
                    }

                    if ($method === 'call') {
                        $this->schedule[] = new ScheduleEntry(
                            type: 'call',
                            target: 'Closure',
                            frequency: $this->chainFrequency($node),
                            file: $this->file,
                        );
                    }
                }

                return null;
            }

            /** Walk the method chain to find the first frequency-like method. */
            private function chainFrequency(Node\Expr\MethodCall $node): string
            {
                $freq = ['everyMinute', 'everyFiveMinutes', 'everyTenMinutes',
                    'everyFifteenMinutes', 'everyThirtyMinutes', 'hourly',
                    'daily', 'dailyAt', 'weekly', 'weeklyOn', 'monthly',
                    'monthlyOn', 'quarterly', 'yearly', 'cron',
                    'everyTwoMinutes', 'everyThreeMinutes', 'twiceDaily',
                    'twiceMonthly', 'lastDayOfMonth', 'timezone'];

                // The node itself may be wrapped by frequency calls further up;
                // we look at the var chain (the receiver of this call)
                $current = $node;
                while ($current instanceof Node\Expr\MethodCall) {
                    $m = $current->name instanceof Node\Identifier
                        ? $current->name->toString()
                        : '';
                    if (in_array($m, $freq, true)) {
                        return $m;
                    }
                    $current = $current->var;
                }

                return '';
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

    private function findFilesContaining(string $dir, string $keyword): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()
                && $entry->getExtension() === 'php'
                && str_contains(strtolower($entry->getBasename()), $keyword)) {
                $files[] = $entry->getPathname();
            }
        }

        return $files;
    }
}
