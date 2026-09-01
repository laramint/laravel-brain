<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Ai;

use LaraMint\LaravelBrain\Storage\GraphStore;

class RulesExporter
{
    /** @var array<string, array{path: string, label: string}> */
    public const TARGETS = [
        'claude' => ['path' => 'CLAUDE.md',                              'label' => 'Claude Code'],
        'cursor' => ['path' => '.cursor/rules/laravel-brain.mdc',        'label' => 'Cursor'],
        'windsurf' => ['path' => '.windsurf/rules/laravel-brain.md',      'label' => 'Windsurf'],
        'copilot' => ['path' => '.github/copilot-instructions.md',        'label' => 'GitHub Copilot'],
        'junie' => ['path' => '.junie/guidelines.md',                   'label' => 'JetBrains Junie'],
        'aider' => ['path' => 'CONVENTIONS.md',                         'label' => 'Aider'],
        'agents' => ['path' => 'AGENTS.md',                              'label' => 'AGENTS.md (universal)'],
        'codex' => ['path' => 'CODEX.md',                               'label' => 'OpenAI Codex'],
    ];

    public function __construct(
        private readonly GraphStore $store,
        private readonly string $projectPath,
    ) {}

    /**
     * Generate the file content for a given target.
     *
     * @param  key-of<self::TARGETS>  $target
     */
    public function generate(string $target): string
    {
        $data = $this->loadProjectData();
        $body = $this->buildBody($data);

        return match ($target) {
            'claude' => $this->wrapClaude($body, $data),
            'cursor' => $this->wrapCursor($body, $data),
            'windsurf' => $this->wrapWindsurf($body, $data),
            'copilot' => $this->wrapCopilot($body, $data),
            'junie' => $this->wrapJunie($body, $data),
            'aider' => $this->wrapAider($body, $data),
            'agents' => $this->wrapAgents($body, $data),
            'codex' => $this->wrapCodex($body, $data),
            default => throw new \InvalidArgumentException("Unknown target: {$target}"),
        };
    }

    /**
     * Returns the absolute path where the file should be written.
     *
     * @param  key-of<self::TARGETS>  $target
     */
    public function targetPath(string $target): string
    {
        $relative = self::TARGETS[$target]['path'] ?? throw new \InvalidArgumentException("Unknown target: {$target}");

        return rtrim($this->projectPath, '/').'/'.$relative;
    }

    // ── Data loading ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadProjectData(): array
    {
        // Reconstructed from the per-tab subgraphs (no monolithic graph).
        $graph = MergedGraph::load($this->store);
        $manifestJson = $this->store->getManifest();
        $manifest = $manifestJson !== null
            ? (json_decode($manifestJson, true) ?? [])
            : [];

        $nodes = (array) ($graph['nodes'] ?? []);

        // Count nodes by type
        $typeCounts = [];
        foreach ($nodes as $node) {
            $type = (string) ($node['type'] ?? 'unknown');
            $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
        }

        // Top routes by complexity of their action nodes (up to 10)
        $actionNodes = array_filter($nodes, fn ($n) => isset($n['data']['metrics']));
        usort($actionNodes, fn ($a, $b) => ($b['data']['metrics']['cyclomaticComplexity'] ?? 0) <=>
            ($a['data']['metrics']['cyclomaticComplexity'] ?? 0)
        );
        $hotspots = array_slice($actionNodes, 0, 10);

        // Smell nodes
        $n1Nodes = array_values(array_filter($nodes, fn ($n) => ! empty($n['data']['hasN1'])));
        $fatMethods = array_values(array_filter($nodes, fn ($n) => ! empty($n['data']['fatMethod'])));
        $fatClasses = array_values(array_filter($nodes, fn ($n) => ! empty($n['data']['fatClass'])));

        // Top routes (up to 15)
        $routeNodes = array_values(array_filter($nodes, fn ($n) => ($n['type'] ?? '') === 'route'));
        $topRoutes = array_slice($routeNodes, 0, 15);

        // Packages
        $backendPackages = $this->readComposerPackages();
        $frontendPackages = $this->readFrontendPackages();

        // PHP + Laravel version
        $phpVersion = $this->extractPhpVersion($backendPackages);
        $laravelVersion = $this->extractPackageVersion('laravel/framework', $backendPackages);
        $frontendStack = $this->detectFrontendStack($frontendPackages);

        return [
            'project' => (string) ($manifest['project'] ?? ($graph['meta']['project'] ?? 'unknown')),
            'analyzedAt' => (string) ($manifest['analyzedAt'] ?? ($graph['meta']['analyzedAt'] ?? '')),
            'typeCounts' => $typeCounts,
            'hotspots' => $hotspots,
            'n1Nodes' => $n1Nodes,
            'fatMethods' => $fatMethods,
            'fatClasses' => $fatClasses,
            'topRoutes' => $topRoutes,
            'backendPackages' => $backendPackages,
            'frontendPackages' => $frontendPackages,
            'phpVersion' => $phpVersion,
            'laravelVersion' => $laravelVersion,
            'frontendStack' => $frontendStack,
        ];
    }

    // ── Shared content body ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildBody(array $data): string
    {
        $lines = [];

        // Stack
        $lines[] = '## Tech Stack';
        $lines[] = '- **Backend:** PHP '.$data['phpVersion'].' · Laravel '.$data['laravelVersion'];
        if ($data['frontendStack'] !== '') {
            $lines[] = '- **Frontend:** '.$data['frontendStack'];
        }
        $lines[] = '';

        // Architecture at a glance
        $tc = (array) $data['typeCounts'];
        $counts = [
            'Routes' => $tc['route'] ?? 0,
            'Actions' => $tc['action'] ?? 0,
            'Action classes' => $tc['action_class'] ?? 0,
            'Services' => $tc['service'] ?? 0,
            'Validation requests' => $tc['validation_request'] ?? 0,
            'Models' => $tc['model'] ?? 0,
            'Events' => $tc['event'] ?? 0,
            'Jobs' => $tc['job'] ?? 0,
            'Commands' => $tc['command'] ?? 0,
            'Channels' => $tc['channel'] ?? 0,
        ];
        $counts = array_filter($counts, fn ($v) => $v > 0);

        $lines[] = '## Architecture';
        foreach ($counts as $label => $count) {
            $lines[] = "- {$count} {$label}";
        }
        $lines[] = '- Flow: **Route → Middleware → Controller → Service → Model**';
        $lines[] = '';

        // Top routes
        $topRoutes = (array) $data['topRoutes'];
        if (! empty($topRoutes)) {
            $lines[] = '## Routes (top '.\count($topRoutes).')';
            $lines[] = '| Method | URI | Controller |';
            $lines[] = '|--------|-----|-----------|';
            foreach ($topRoutes as $route) {
                $method = (string) ($route['data']['method'] ?? '?');
                $uri = (string) ($route['data']['uri'] ?? '?');
                $controller = (string) ($route['data']['controller'] ?? '');
                if ($controller === '') {
                    $controller = (string) ($route['label'] ?? '?');
                }
                $lines[] = "| {$method} | `{$uri}` | {$controller} |";
            }
            $lines[] = '';
        }

        // Complexity hotspots
        $hotspots = (array) $data['hotspots'];
        if (! empty($hotspots)) {
            $lines[] = '## Complexity Hotspots';
            $lines[] = '> Methods with high cyclomatic complexity — review before modifying.';
            $lines[] = '';
            $lines[] = '| Class / Method | Cyclomatic | Lines |';
            $lines[] = '|---------------|-----------|-------|';
            foreach ($hotspots as $node) {
                $m = (array) ($node['data']['metrics'] ?? []);
                $cc = $m['cyclomaticComplexity'] ?? '?';
                $loc = $m['lineCount'] ?? '?';
                $lines[] = "| {$node['label']} | {$cc} | {$loc} |";
            }
            $lines[] = '';
        }

        // Code smells
        $smellLines = [];
        foreach ((array) $data['n1Nodes'] as $n) {
            $smellLines[] = "- ⚠️  **N+1 Query** in `{$n['label']}`";
        }
        foreach ((array) $data['fatMethods'] as $n) {
            $smellLines[] = "- 🧱 **Fat Method** `{$n['label']}`";
        }
        foreach ((array) $data['fatClasses'] as $n) {
            $smellLines[] = "- 🏗️  **Fat Class** `{$n['label']}`";
        }
        if (! empty($smellLines)) {
            $lines[] = '## Code Smells';
            array_push($lines, ...$smellLines);
            $lines[] = '';
        }

        // Backend packages
        $backend = (array) $data['backendPackages'];
        if (! empty($backend)) {
            $lines[] = '## Backend Packages';
            $lines[] = '| Package | Version | Dev |';
            $lines[] = '|---------|---------|-----|';
            foreach ($backend as $pkg) {
                $dev = $pkg['dev'] ? '✓' : '';
                $lines[] = "| `{$pkg['name']}` | {$pkg['version']} | {$dev} |";
            }
            $lines[] = '';
        }

        // Frontend packages
        $frontend = (array) $data['frontendPackages'];
        if (! empty($frontend)) {
            $lines[] = '## Frontend Packages';
            $lines[] = '| Package | Version | Dev |';
            $lines[] = '|---------|---------|-----|';
            foreach ($frontend as $pkg) {
                $dev = $pkg['dev'] ? '✓' : '';
                $lines[] = "| `{$pkg['name']}` | {$pkg['version']} | {$dev} |";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    // ── Per-target wrappers ───────────────────────────────────────────────────

    /** @param array<string, mixed> $data */
    private function wrapClaude(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # CLAUDE.md

        > Auto-generated by [Laravel Brain](https://github.com/laramint/laravel-brain) on {$date}.
        > Re-run `php artisan brain:generate-rules --target=claude` after code changes.

        This file provides guidance to Claude Code when working with the **{$project}** codebase.

        {$body}
        ## Development Commands

        ```bash
        php artisan brain:scan               # Re-analyse project architecture
        php artisan brain:generate-rules     # Regenerate all AI context files
        php artisan brain:export-context     # Export focused AI context for a route or node
        ```
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapCursor(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        $frontmatter = <<<YAML
        ---
        description: "Laravel project architecture, routes, packages, and code health for {$project}"
        globs: ["**/*.php", "app/**/*", "routes/**/*", "config/**/*"]
        alwaysApply: false
        ---
        YAML;

        return <<<MD
        {$frontmatter}

        # {$project} — Project Context

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=cursor` after code changes.

        {$body}
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapWindsurf(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — Project Context

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=windsurf` after code changes.

        {$body}
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapCopilot(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — Copilot Instructions

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=copilot` after code changes.

        {$body}
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapJunie(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — JetBrains AI Guidelines

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=junie` after code changes.

        {$body}
        ## Quick-Start Rules

        - Always run `php artisan test` before marking a task complete.
        - Follow PSR-12 coding standards.
        - Use Laravel conventions (Eloquent, Facades, service container) — avoid raw PDO.
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapAider(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — Conventions

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=aider` after code changes.
        > Load with: `aider --read CONVENTIONS.md` or add `read: CONVENTIONS.md` to `.aider.conf.yml`.

        {$body}
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapAgents(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — AGENTS.md

        > Auto-generated by Laravel Brain on {$date}.
        > Re-run `php artisan brain:generate-rules --target=agents` after code changes.
        > Recognised by: Cursor, Windsurf, Junie, Copilot, Aider, OpenAI Codex, Google Jules, Zed, and 60+ tools.

        {$body}
        MD;
    }

    /** @param array<string, mixed> $data */
    private function wrapCodex(string $body, array $data): string
    {
        $project = $data['project'];
        $date = $data['analyzedAt'];

        return <<<MD
        # {$project} — Codex Instructions

        > Auto-generated by [Laravel Brain](https://github.com/laramint/laravel-brain) on {$date}.
        > Re-run `php artisan brain:generate-rules --target=codex` after code changes.
        > Load with: `codex --context CODEX.md` or place at the repository root for automatic discovery.

        {$body}
        ## Development Commands

        ```bash
        php artisan brain:scan               # Re-analyse project architecture
        php artisan brain:generate-rules     # Regenerate all AI context files
        php artisan brain:export-context     # Export focused AI context for a route or node
        ```
        MD;
    }

    // ── Package helpers ───────────────────────────────────────────────────────

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function readComposerPackages(): array
    {
        $file = rtrim($this->projectPath, '/').'/composer.json';
        if (! file_exists($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data)) {
            return [];
        }

        $packages = [];
        foreach ((array) ($data['require'] ?? []) as $name => $version) {
            if ($name === 'php') {
                continue;
            }
            $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => false];
        }
        foreach ((array) ($data['require-dev'] ?? []) as $name => $version) {
            $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => true];
        }

        usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $packages;
    }

    /**
     * @return list<array{name: string, version: string, dev: bool}>
     */
    private function readFrontendPackages(): array
    {
        $root = rtrim($this->projectPath, '/');
        $candidates = [$root.'/package.json', $root.'/frontend/package.json'];

        foreach ($candidates as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                continue;
            }

            $packages = [];
            foreach ((array) ($data['dependencies'] ?? []) as $name => $version) {
                $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => false];
            }
            foreach ((array) ($data['devDependencies'] ?? []) as $name => $version) {
                $packages[] = ['name' => (string) $name, 'version' => (string) $version, 'dev' => true];
            }

            if (! empty($packages)) {
                usort($packages, fn ($a, $b) => strcmp($a['name'], $b['name']));

                return $packages;
            }
        }

        return [];
    }

    /**
     * @param  list<array{name: string, version: string, dev: bool}>  $packages
     */
    private function extractPhpVersion(array $packages): string
    {
        $file = rtrim($this->projectPath, '/').'/composer.json';
        if (file_exists($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            $ver = (string) ($data['require']['php'] ?? '');
            if ($ver !== '') {
                return $ver;
            }
        }

        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    /**
     * @param  list<array{name: string, version: string, dev: bool}>  $packages
     */
    private function extractPackageVersion(string $name, array $packages): string
    {
        foreach ($packages as $pkg) {
            if ($pkg['name'] === $name) {
                return $pkg['version'];
            }
        }

        return 'unknown';
    }

    /**
     * @param  list<array{name: string, version: string, dev: bool}>  $packages
     */
    private function detectFrontendStack(array $packages): string
    {
        $names = array_column($packages, 'name');
        $parts = [];

        if (in_array('react', $names, true)) {
            $parts[] = 'React';
        }
        if (in_array('vue', $names, true)) {
            $parts[] = 'Vue';
        }
        if (in_array('@angular/core', $names, true)) {
            $parts[] = 'Angular';
        }
        if (in_array('svelte', $names, true)) {
            $parts[] = 'Svelte';
        }
        if (in_array('vite', $names, true)) {
            $parts[] = 'Vite';
        }
        if (in_array('typescript', $names, true)) {
            $parts[] = 'TypeScript';
        }
        if (in_array('tailwindcss', $names, true)) {
            $parts[] = 'Tailwind CSS';
        }

        return implode(' · ', $parts);
    }
}
