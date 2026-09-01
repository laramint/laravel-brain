<p align="center">
  <img src="art/banner.png" alt="Laravel Brain" width="300px"/>
</p>

<p align="center">
  <strong>Visualize your Laravel application's full request lifecycle as an interactive graph.</strong><br/>
  Understand how routes, controllers, services, models, jobs, events, commands, and channels connect — in seconds.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-9%20%7C%2010%20%7C%2011%20%7C%2012%20%7C%2013-red?style=flat-square&logo=laravel" alt="Laravel"/>
  <img src="https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php" alt="PHP"/>
  <img src="https://img.shields.io/badge/license-MIT-green?style=flat-square" alt="License"/>
  <img src="https://img.shields.io/badge/Sponsor-GitHub-EA4AAA?style=flat-square&logo=github-sponsors" alt="Sponsor"/>
  <br/><br/>
  <a href="https://www.buymeacoffee.com/MrMarchOne"><img src="https://img.shields.io/badge/Buy%20Me%20a%20Coffee-MrMarchOne-FFDD00?style=flat-square&logo=buy-me-a-coffee&logoColor=black" alt="Buy Me a Coffee"/></a>
</p>

---

## What is LaraMint\LaravelBrain?

LaraMint\LaravelBrain is a zero-config developer tool that analyzes your Laravel codebase and renders an interactive node graph of your application's architecture. It traces every route through its controller, services, repositories, models, jobs, events, Artisan commands, scheduled tasks, broadcast channels, and Filament panels — giving you a bird's-eye view of the entire application without reading a single line of code.

The scan writes JSON graph files to `storage/app/laravel-brain/`. The viewer is served at `/_laravel-brain` entirely through your existing Laravel routes — no separate server process needed.

<p align="center">
  <img src="art/example.png" alt="Laravel Brain"/>
</p>

## Features

- **Full lifecycle tracing** — Follows every route from HTTP verb → controller → service → repository → model → events/jobs
- **Event listener discovery** — Finds listeners registered by convention, via `EventServiceProvider::$listen`/`$subscribe`, or `#[AsEventListener]` attributes, and links them to the events they handle
- **Unresolved dispatch detection** — Flags a job/event dispatch that can't be resolved statically (a variable or factory result) instead of silently showing "no impact"; recognizes custom dispatch helper functions too
- **Filament PHP support** — Discovers panels, resources, pages, widgets, and relation managers; traces call chains from Filament page methods the same way controller actions are traced
- **Artisan command discovery** — Maps class-based commands, closure commands from `routes/console.php`, and Kernel-registered commands
- **Scheduler tracing** — Lists every scheduled task (`command`, `job`, `call`) with the time it runs, its timezone, and its overlap/one-server guards
- **Broadcast channel mapping** — Discovers class-based and closure channels from `routes/channels.php`
- **DB query tracing** — Surfaces Eloquent and raw queries per method
- **Cache operation tracing** — Surfaces `Cache::` facade and `cache()` helper calls per method, split into read / write / invalidate / lock, with the key (literal or constructed — a computed one is labelled, never guessed), plus tags, store and TTL where declared
- **Fat-class detection** — Flags controllers and services with more than 300 lines or 10 methods
- **Cyclomatic complexity** — Highlights hotspots by complexity tier (Low / Moderate / High / Critical)
- **Interactive graph** — Dark/light theme, accent-colored nodes, and interactive edges
- **Per-route tabs** — Each route gets its own isolated subgraph tab
- **Middleware mapping** — Shows which middleware guards each route
- **Model relationships** — Displays `hasMany`, `belongsTo`, and other Eloquent relations
- **Action recognition** — Draws single-purpose action classes (one class, one `__invoke`/`handle`/`execute`, filed under `Actions/`) as their own kind rather than as generic services, and surfaces the entry method they are invoked through
- **Observer discovery** — Finds Eloquent observers (`#[ObservedBy]`, `Model::observe()`, or `booted()`) and links them to the models they observe
- **Policy resolution** — Resolves each model's authorization policy (explicit map, `#[UsePolicy]` attribute, or naming convention) and links it in the graph
- **View composition mapping** — Traces `@include`, `@extends`, `@component`, `@each`, and `<x-...>` components as view → view edges, so a shared partial shows every entry point it reaches
- **API resource edges** — Traces `UserResource::make()`/`::collection()`/`new` and nested resource composition as edges, so a changed resource shows every controller and resource that uses it
- **Method flowcharts** — See internal flow as a step-by-step diagram with a large modal popup view
- **Sequence diagrams** — Route nodes render an SVG sequence diagram (exportable as PNG or Mermaid) showing the full actor chain
- **Source viewer** — Read the actual source file inline or in a focused popup
- **Export** — Export any graph as PNG or Mermaid diagram
- **Multiple layouts** — Hierarchical (dagre), force-directed (cose-bilkent), breadth-first, circle, grid
- **Watch mode** — Auto-rescans on PHP file changes; a change confined to `app/` re-traces only the affected controllers and merges the result into the previous graph instead of rebuilding it from scratch
- **Route stress test** — From a selected **route** node, run concurrent HTTP load against that endpoint (via [`laramint/laravel-stress`](https://github.com/LaraMint/laravel-stress)): configure request count, concurrency, headers, body, and timeout; see timing percentiles (min/avg/p50/p95/p99/max), throughput, and status distribution in the sidebar. While a run is active, the graph highlights the route and animates packets along the request path
- **AI context export** — Copy a deterministic, token-optimized context snapshot for any node to your clipboard with one click (🤖 button in the sidebar). Also available as `brain:export-context` Artisan command and `GET /_laravel-brain/api/context` API endpoint. Context includes call chain, complexity hotspots, DB operations, cache operations, source snippets, and all backend/frontend packages — always reproducible from the same scan data
- **AI rules generation** — Generate ready-to-use context files for seven AI coding assistants (Claude Code, Cursor, Windsurf, GitHub Copilot, JetBrains Junie, Aider, AGENTS.md) directly from the UI (**Export → Generate AI Rules**) or via `brain:generate-rules`. Each file is populated with your project's real architecture, routes, packages, and code-health data

## Requirements

- PHP 8.0+
- Laravel 9, 10, 11, 12, or 13
- Composer

## Installation

Install as a dev dependency (it's a development tool, not needed in production):

```bash
composer require --dev laramint/laravel-brain
```

Laravel will auto-discover the service provider. No manual registration needed.

## Usage

### Scan your project

```bash
php artisan brain:scan
```

This analyzes your entire codebase and writes the graph data to `storage/app/laravel-brain/`. When complete it prints the URL to open:

```
  LaraMint\LaravelBrain — analyzing project...
  Path: /your/project

  Scanning routes, controllers, models and call chains...

  Done! Open the viewer at: http://localhost:8000/_laravel-brain
```

#### Memory limit

The scanner defaults to **1024M**. On larger codebases you can raise the limit with `--memory-limit`:

```bash
php artisan brain:scan --memory-limit=1G
php artisan brain:scan --memory-limit=2G
php artisan brain:scan --memory-limit=2048M

# Unlimited (use with caution)
php artisan brain:scan --memory-limit=-1
```

Accepted formats: `<number>M` (megabytes), `<number>G` (gigabytes), or `-1` (unlimited). The minimum allowed value is `1024M`.

### Export AI context for a node

Click the **🤖** button in the node sidebar to copy a structured Markdown context block to your clipboard, ready to paste into Claude, ChatGPT, or any LLM.

The context is **deterministic**: same scan + same node = identical output every time. It uses BFS up to depth 3 from the selected node and enforces a token budget (default 6 000 tokens), truncating only source snippets once structural metadata is fully included.

You can also run it from the terminal:

```bash
# Full project summary
php artisan brain:export-context

# Focused on a specific route
php artisan brain:export-context --route="GET /users" --budget=4000

# Target a specific node ID
php artisan brain:export-context --node="action::App\Http\Controllers\UserController::index"

# Write to a file instead of stdout
php artisan brain:export-context --route="GET /api/orders" --output=/tmp/context.md

# JSON format
php artisan brain:export-context --format=json
```

Or via the API:

```
GET /_laravel-brain/api/context?nodeId=<id>&budget=6000
GET /_laravel-brain/api/context?route=GET+/users&format=json
```

The exported Markdown contains:

- **Route** — method, URI, middleware
- **Call chain** — `Route → Controller → Service → Model` (depth ≤ 3)
- **Complexity hotspots** — cyclomatic complexity + line count table
- **Database operations** — Eloquent and raw queries per node
- **Cache operations** — read / write / invalidate / lock per node, with key, tags, store and TTL
- **Source snippets** — focal node first, truncated to fit the token budget
- **Backend packages** — all `composer.json` dependencies with versions, dev flag
- **Frontend packages** — all `package.json` dependencies with versions, dev flag

---

### Generate AI assistant rules files

Populate context files for your AI coding tools directly from the scan data.

**From the UI:** Toolbar → **Export** → **Generate AI Rules** → select targets → **Generate**.

**From the terminal:**

```bash
# Generate all 7 files at once
php artisan brain:generate-rules

# Specific targets only
php artisan brain:generate-rules --target=claude --target=cursor

# Preview paths without writing anything
php artisan brain:generate-rules --dry-run

# Overwrite existing files without prompting
php artisan brain:generate-rules --force
```

| Target | File written | Used by |
|--------|-------------|---------|
| `claude` | `CLAUDE.md` | Claude Code CLI & IDE extension |
| `cursor` | `.cursor/rules/laravel-brain.mdc` | Cursor (MDC format with frontmatter) |
| `windsurf` | `.windsurf/rules/laravel-brain.md` | Windsurf by Codeium |
| `copilot` | `.github/copilot-instructions.md` | GitHub Copilot (applied repo-wide) |
| `junie` | `.junie/guidelines.md` | JetBrains AI / Junie |
| `aider` | `CONVENTIONS.md` | Aider (`aider --read CONVENTIONS.md`) |
| `agents` | `AGENTS.md` | Universal open standard — 60+ tools |

Each generated file contains your project's tech stack, architecture counts, top routes, complexity hotspots, detected code smells, and full package lists. Re-run after every scan to keep the files current.

---

### MCP Server

Everything above exports a static snapshot. The MCP server makes the last scan queryable — Claude Code, Cursor, and any other [Model Context Protocol](https://modelcontextprotocol.io) client can ask the graph questions directly instead of re-exporting each time.

It's an optional, auto-detected add-on — install [Laravel's own MCP package](https://github.com/laravel/mcp) and Brain wires itself up automatically:

```bash
composer require --dev laravel/mcp
```

Requires Laravel 11+ (`laravel/mcp` needs `symfony/process ^7.4.5|^8.0.5`, which conflicts with the `symfony/process ^6.x` that Laravel 9/10 themselves require — PHP version isn't the limiter here). Nothing else in Brain needs this — it's the only feature gated on it, and skipping this package leaves everything else untouched.

Register the server with your MCP client (e.g. Claude Code):

```bash
claude mcp add brain -- php artisan mcp:start brain
```

| Tool | What it does |
|------|--------------|
| `brain_get_manifest` | The tab index of the last scan — every route/command/channel tab, its risk level |
| `brain_get_context` | Focused AI context for a route or node — call chain, hotspots, DB ops, source |
| `brain_find_usages` | Every direct caller of a node, grouped by file |
| `brain_get_route_security` | Every route's exposure and risk level, filterable — "which routes are public?" |
| `brain_get_subgraph` | One tab's nodes and edges by id |
| `brain_get_graph` | The full merged graph, optionally filtered by node type |
| `brain_get_agent_rules` | The content `brain:generate-rules` would write, for any target, without writing it |
| `brain_rescan` | Re-scans and persists a fresh graph — every other tool reads whatever was scanned last |

Every tool reads the last persisted scan, not the live filesystem — call `brain_rescan` after code changes before trusting the rest. Set `LARAVEL_BRAIN_MCP_ENABLED=false` to disable the server without removing the package.

---

### Watch mode

Re-scan automatically whenever a PHP file changes:

```bash
php artisan brain:scan --watch
php artisan brain:scan --watch --interval=5   # poll every 5 seconds (default: 3)
```

Each rescan traces only the controllers declared in the changed files and merges the result into the previous graph, rather than rebuilding it from scratch — the output is identical to a full scan, just faster. A full rescan still runs automatically whenever a file is added or deleted, or when anything outside `app/` changes (routes, config).

### Open the viewer

Navigate to `/_laravel-brain` in your browser while your Laravel app is running (e.g. via `php artisan serve`).

## How It Works

```
php artisan brain:scan
        │
        ├─ RouteAnalyzer      → scans all files in routes/**/*.php
        ├─ MiddlewareAnalyzer → reads Kernel.php or bootstrap/app.php
        ├─ ControllerAnalyzer → resolves controller classes + methods
        ├─ MethodTracer       → deep-traces call chains (services, repos, models)
        ├─ ModelAnalyzer      → extracts Eloquent relationships
        ├─ QueryTracer        → surfaces DB queries per method
        ├─ ConsoleAnalyzer    → discovers Artisan commands and scheduled tasks
        ├─ ChannelAnalyzer    → discovers broadcast channels
        ├─ FilamentAnalyzer   → discovers panels, resources, pages, widgets, relation managers
        └─ GraphBuilder       → assembles nodes + edges, flags fat classes
                │
                └─ Writes JSON → storage/app/laravel-brain/

GET /_laravel-brain
        │
        └─ BrainController → serves the React SPA + graph JSON via Laravel routes
```

### Route discovery

LaravelBrain recursively scans your entire `routes/` directory — not just `web.php` and `api.php`. Any PHP file under `routes/**` is analyzed, including versioned files like `routes/v1/users.php` or module-specific files like `routes/modules/admin.php`.

#### Auto-discover mode

Set `'auto_discover_routes' => true` in `config/laravel-brain.php` to skip AST parsing and pull routes from the live Laravel router (`Route::getRoutes()`) instead. This captures routes registered programmatically by service providers and packages (Filament, Sanctum, Livewire, Telescope, etc.) that the AST scanner can't see.

By default, routes whose handler (controller class or closure) lives under your project's `vendor/` directory are excluded — so package-internal routes such as Telescope, Horizon, or Ignition stay out of the graph. Flip `'auto_discover_exclude_vendor' => false` if you want them included.

Both settings are env-overridable, so you can toggle them per-environment without editing the published config file:

```dotenv
LARAVEL_BRAIN_AUTO_DISCOVER_ROUTES=true
LARAVEL_BRAIN_AUTO_DISCOVER_EXCLUDE_VENDOR=false
```

To force auto-discover for a single scan without changing config, pass the flag:

```bash
php artisan brain:scan --auto-discover
```

> **Heads up:** in auto-discover mode the source file and line number of each route are not available, so the sidebar will not group routes by their declaring file (everything falls under a single group). Use the default AST mode if file/line grouping matters to you.

### Modular applications

Class names are resolved through the PSR-4 map built from the root `composer.json` **and** from every local package: packages installed from a `path` repository, and nwidart/laravel-modules under `Modules/`. An application whose code lives in packages rather than in `app/` therefore resolves normally — without it, classes are discovered and then dropped, because no file can be found for them.

Regular vendor dependencies are deliberately left out of that map, so the call-chain tracer never walks into framework or library internals.

### Artisan commands

A command is recognised however it names itself: a `$signature` (or the older `$name`) property, Laravel's `#[Signature]` / `#[Description]` attributes, or Symfony's `#[AsCommand]`. A property wins over an attribute, matching Laravel's own precedence.

Scheduled tasks are read from `routes/console.php`, from a schedule split into its own `routes/schedule.php`, from the legacy `Console\Kernel::schedule()` method, and from the `->withSchedule(…)` closure a Laravel 11+ `bootstrap/app.php` uses instead — in both the `Schedule::command()` and `$schedule->command()` spellings, along with `job()` and `call()` entries. Each task keeps the cadence it was chained with *and the arguments that cadence was given* (`dailyAt('05:30')`, `cron('0 3 * * *')`), its timezone, and the guards that decide whether it fires at all (`withoutOverlapping()`, `onOneServer()`, `runInBackground()`, `evenInMaintenanceMode()`). Every task gets its own entry in the sidebar's Schedules bucket, showing what runs and when without opening it.

### Broadcast channels

Channels registered through the `Broadcast` facade are found out of the box. If your application registers them through a wrapper of its own — one that scopes every channel to a tenant, for instance — name that class in `channel_registrars` and its `::channel()` calls are read the same way:

```php
// config/laravel-brain.php
'channel_registrars' => [
    App\Broadcasting\TenantChannel::class,
],
```

### Call chain tracing

From each controller action (and Filament page method), the tracer follows:
- Direct method calls to injected services/repositories
- Static calls (`MyService::method()`)
- Job dispatches (`dispatch(new SendEmail(...))`)
- Event dispatches (`event(new OrderPlaced(...))`)

This produces the full edge list used to build the graph.

### Blade views

Templates are scanned to link a view to the views it `@include`s or renders as a component, and a view name is resolved back to its file the same way. Both read the same list, so an application that keeps templates in packages points them there once:

```php
// config/laravel-brain.php
'views' => [
    'paths' => ['resources/views', 'app-modules/*/resources/views'],
],
```

An include is linked when the template exists under *any* configured root, so one package rendering another's partial is still an edge.

### Source paths and watch mode

Two lists drive everything that is not an analyzer of its own:

```php
'source_paths' => ['app', 'src'],              // where application classes live
'watch_paths'  => ['app', 'routes', 'config'], // what can change the graph
```

`source_paths` backs the last-resort file lookup — the by-file-name search Brain falls back to when Composer's PSR-4 map cannot place a class name — and marks the part of the tree a scoped rescan may be limited to.

`watch_paths` is what `brain:scan --watch` polls and what the build fingerprint hashes. A change confined to `source_paths` can be handled by a scoped rescan; a change anywhere else — a route file, a config file — forces a full rebuild.

### Service providers and facades

Container registrations (`bind()`, `singleton()`, `scoped()` and their `*If` variants, plus the `$bindings` property) are read from service providers, and application-level facades — classes whose inheritance chain reaches `Illuminate\Support\Facades\Facade` — from the application's source tree. Both feed the graph: an edge that lands on an interface or an abstract class is wired through to the concrete class bound to it, and a facade call is wired through to the class behind its accessor.

Both directories default to the standard skeleton and take glob patterns, so an application whose providers and facades live in packages points them at its own layout:

```php
// config/laravel-brain.php
'container_bindings' => [
    'provider_paths' => ['app-modules/*/src'],
],

'facades' => [
    'paths' => ['app-modules/*/src'],
],
```

### Filament PHP support

When Filament is installed, the scanner discovers every panel registered via service providers, then resolves its resources, pages, widgets, and relation managers — both explicitly listed (`->resources([...])`) and auto-discovered (`->discoverResources(for: '...')`). Filament page methods are traced through the same call-chain engine as controller actions, so models and services they touch appear in the graph.

Resources and relation managers are recognised through their whole `extends` chain, so a project base class (`class OrderResource extends AppResource`, `AppResource extends Resource`) does not hide them.

Applications that keep their Filament classes somewhere other than `app/Filament` - a modular monolith with no `app/` directory, for instance — point the scanner at their own layout. An entry is used as-is when it is a directory and expanded as a glob pattern otherwise:

```php
// config/laravel-brain.php
'filament' => [
    'panel_paths' => ['app-modules/*/src/Filament'],
    'paths' => ['app-modules/*/src/Filament'],
],
```

A file named `*PanelProvider.php` is treated as a panel by convention. Any other file counts as a panel only when it actually builds a `Panel::make()` chain, so pointing `panel_paths` at a whole source tree does not turn every class into a panel.

## laravel/ai agents and tools

Where an application uses [`laravel/ai`](https://github.com/laravel/ai), every LLM call goes through an agent class. Brain puts those agents on the graph as nodes of their own, together with the tools they expose to the model and the configuration that decides what a call costs and what it is allowed to do.

An agent is recognised when it implements `Laravel\Ai\Contracts\Agent` or uses the `Laravel\Ai\Promptable` trait, and so is any class that extends one — a project's own `BaseAgent` and its children all appear. A tool is recognised when it implements `Laravel\Ai\Contracts\Tool` or `CanActAsTool`, **or** when it extends `Laravel\Mcp\Server\Tool`: the SDK accepts an MCP server tool straight from `tools()` and wraps it itself, and in a real application those are often the majority.

Three kinds of edge come out of it:

- **caller → agent** — the controller action, job or command method that names the agent, so "which code paths talk to an LLM" is a question the graph answers.
- **agent → tool** — every tool the agent returns from `tools()`.
- **agent → agent** — an agent returned from another agent's `tools()`, which the SDK wraps in an `AgentTool`; a delegation rather than a call.

### The model is reported as honestly as it can be known

`#[Model('gpt-4o-mini')]` names a model, and the node shows it. The other spellings do not, and the node says so rather than guessing:

- **`#[UseSmartestModel]` / `#[UseCheapestModel]` pick a tier, not a name.** The SDK turns them into `$provider->smartestTextModel()`, whose answer depends on the provider chosen at runtime and on `config('ai.<lab>.models.text.smartest')`. The node reports the tier.
- **A `model()` method shadows `#[Model]` completely.** `Promptable::getProvidersAndModels()` is an if/else, not a coalesce: if the class declares (or inherits) a `model()` method, the attribute is never read at all — a method returning `null` leaves the agent with no model, not with the attribute's value. When the method body is a literal Brain reports it; otherwise the node says the model is decided at runtime, and lists the now-dead attribute separately so the discrepancy is visible.
- The same rule applies to `provider()` versus `#[Provider]`.

Attributes are **not** inherited — PHP does not hand class attributes to subclasses, and the SDK reads them with plain `getAttributes()` — so a base agent's `#[Model]` really is inert for its children, and Brain does not report it on them. Interfaces, knob methods and `tools()` are inherited, and those Brain does fold in.

Alongside the model, the agent node carries provider, max steps, max tokens, temperature, top P, timeout, strict mode, and the contracts the class implements (`HasTools`, `HasStructuredOutput`, `Conversational`, `RemembersConversations`, `Approvable`, …). Two mismatches get their own line: a `tools()` method on a class that never implements `HasTools` (the SDK's `resolveTools()` returns early, so the model is never offered them, and Brain does not draw the edges), and a tool reference `tools()` builds at runtime that no static reading can resolve.

### Tools an agent cannot name itself

An agent that takes its tools through the constructor cannot name them:

```php
public function tools(): iterable
{
    return $this->tools;   // constructor-injected
}
```

That is genuinely unreadable, and Brain says so — the node is marked *tools decided at runtime*, which is a different statement from "no tools" and reaches the reader as one. It then looks where the agent is built: any class named in the constructor arguments whose own body instantiates recognised tools is treated as supplying them, so

```php
new ChatAssistantAgent(tools: resolve(ChatToolProvider::class)->toolsFor($user))
```

wires the agent to every tool that provider instantiates. Those edges are labelled `may call (supplied)`, apart from the ones the agent declares itself, because the two are known with different certainty. A class that is itself an agent or a tool is never treated as a provider.

### The AI Agents tab

Agents get a standalone tab, like the Model ERD, rather than relying on a route reaching them. That is a measurement, not a preference: on a real application with 5 agents and 17 tools, all six call sites resolved to a queued job, two services and a listener helper that no route reaches statically, so every AI node sat in the full graph and in **no tab at all** — invisible to anyone opening the viewer. The tab shows each agent, the tools it can call, and the methods that prompt it.

For the same reason the caller node is created when no other pass made one, exactly as `addFilament()` already does for a resource's model. A class that talks to an LLM earns a node whether or not anything else in the project reaches it.

The scan summary reports two numbers that measure this directly: **Isolated nodes** (no edge at all, almost always a pass that built nodes and forgot to wire them) and **Outside tabs** (wired, but in a cluster no tab seed reaches). They are separate because they catch different failures — the AI nodes above were correctly wired and still invisible, so only the second one would have caught them.

### It is inert when the package is absent

`laravel/ai` is optional and will not be installed in most applications. Nothing in this pass imports one of its classes; detection is by fully-qualified name matched against the AST. Source files are prefiltered on the literal string `Laravel\Ai\`, so an application that does not use the SDK pays one read per source file, parses nothing, and contributes no nodes.

Agents are ordinary application classes, so the scan follows `source_paths` by default. Point it somewhere narrower, or switch the pass off entirely, with its own config section:

```php
// config/laravel-brain.php
'ai' => [
    'enabled' => env('LARAVEL_BRAIN_AI_ENABLED', true),
    'paths' => ['app-modules/*/src'],
],
```

`enabled` is a second, independent switch: it answers "this application uses the SDK and I still do not want it on the graph", which the string prefilter above cannot. Turning it off skips the pass before the directory scan, so nothing is read and nothing is parsed.

### Action Classes

Single-purpose "action" classes — one class, one public entry method (`__invoke`, `handle` or
`execute`), performing one deliberate unit of work — are drawn as their own node kind rather than
as generic services, so an application's units of work read apart from its incidental
collaborators. The node carries the entry method it is invoked through, and the edge that reaches
it is labelled `runs`.

Placement is the signal, because the pattern has no naming convention to key on: the classes are
named for verbs (`CreateOrder`, `PublishPost`), so a `*Action` suffix test would miss every one of
them while claiming every Filament table action. Point the config at wherever they live:

```php
'actions' => [
    'enabled' => true,
    'paths' => ['app/Actions'],
    // Modular monolith:
    // 'paths' => ['app-modules/*/src/Actions'],
],
```

Reclassification is conservative and one-way: only a class that nothing more specific already
recognised becomes an action class. A job, listener, model, event, facade or Form Request sitting
under one of these directories keeps the kind that recognised it.

Set `enabled` to `false` to turn the kind off; nothing is classified, and no directory is resolved
or tested. An empty `paths` array reaches the same result by a different route — no root to match,
so nothing matches — and unlike the source and view paths an empty array here is honoured rather
than replaced by the default. Prefer `enabled`: it says what it means instead of leaving it to be
inferred.

The node type is `action_class`, which is distinct from the long-standing `action` — the graph's
name for a *controller action*. The two are unrelated and neither affects the other. Because
"an Action" unqualified means this pattern in ordinary Laravel usage, the interface gives the
unqualified label to `action_class` and calls `action` a **Controller action**.

## Graph Node Types

| Node | Accent Color | Represents |
|------|-------------|------------|
| Route | Green `#4CAF50` | HTTP endpoint (`GET /users`) |
| Middleware | Orange `#FF9800` | Middleware applied to a route |
| Controller | Blue `#2196F3` | Controller class |
| Controller action | Light Blue `#03A9F4` | Controller method (node type `action`) |
| Action | Lime `#84cc16` | Single-purpose action class under `Actions/` (node type `action_class`) |
| Service | Purple `#9C27B0` | Service or helper class |
| Model | Red `#F44336` | Eloquent model |
| Event | Yellow `#FFD600` | Laravel event |
| Job | Slate `#607D8B` | Queued job |
| AI Agent | Lime `#A3E635` | `laravel/ai` agent class |
| AI Tool | Dark Lime `#65A30D` | Tool an agent exposes to the model |
| Filament Panel | Violet `#7C3AED` | Filament panel definition |
| Filament Resource | Purple `#A855F7` | Filament resource class |
| Filament Page | Lavender `#C084FC` | Filament page class |
| Filament Page Method | Pink `#E879F9` | Method on a Filament page |
| Filament Widget | Cyan `#06B6D4` | Filament widget class |
| Filament Relation Manager | Teal `#0891B2` | Filament relation manager |

> **Note:** Command, Schedule, Channel, and Repository nodes are discovered and added to the graph but use the closest matching accent color from their parent type.

## Viewer Shortcuts

| Action | How |
|--------|-----|
| Zoom | Scroll wheel |
| Pan | Click + drag on canvas |
| Inspect node | Click any node |
| View source | Click a node → Source tab in sidebar |
| Source popup | Click ⤢ in source section to open focused view |
| View flowchart | Click a class node → Flow tab |
| Flowchart popup | Click ⤢ in flow section to open large view |
| View sequence diagram | Click a route node → Sequence Diagram section in sidebar |
| See what a method caches | Click a node → Info tab → Cache section |
| Filter by type | Filter panel on the left |
| Fit all nodes | Toolbar → Fit button |
| Export PNG | Toolbar → Export → Download PNG |
| Export Mermaid | Toolbar → Export → Copy Mermaid Code |
| Generate AI rules | Toolbar → Export → Generate AI Rules |
| Toggle theme | Toolbar → ☀️ / 🌙 button |
| Copy AI context | Click any node → 🤖 button in sidebar header |
| Stress test a route | Click a **route** node → open **Stress Test** in the sidebar → set options → **Run** |

## Routes Registered

The package registers the following routes in your application (all under the `/_laravel-brain` prefix):

```
GET  /_laravel-brain                          → Interactive graph viewer (SPA)
GET  /_laravel-brain/api/source               → Returns PHP source file content
POST /_laravel-brain/api/scan                 → Triggers a full project scan
GET  /_laravel-brain/api/context              → Exports a deterministic AI context snapshot
POST /_laravel-brain/api/generate-rules       → Generates AI assistant rules files
POST /_laravel-brain/api/stress-test          → Starts a stress-test job (or returns sync result)
GET  /_laravel-brain/api/stress-test/{id}     → Polls background job status/results
GET  /_laravel-brain/assets/*                 → Serves frontend static assets
GET  /_laravel-brain/.graph-*.json            → Serves graph data written by the scan
```

Stress testing uses [`laramint/laravel-stress`](https://github.com/LaraMint/laravel-stress), which is installed automatically as a dependency. Target URLs are validated server-side against an allowlist of development hosts: `localhost`, `127.0.0.1`, `*.test`, `*.local`, `*.ddev.site`, single-label Docker service names (e.g. `nginx`, `app`), private IPv4 ranges (`10.x`, `172.16–31.x`, `192.168.x`), and the host in `APP_URL`.

### Docker and stress testing

> **Docker?** The stress test subprocess runs **inside** the container — `localhost:8080` is the host-side mapped port and won't be reachable there. Change the **Base URL** field to the internal service address, e.g. `http://nginx` or `http://localhost:80`.

Single-label hostnames (Docker service names with no dots) and private IPv4 ranges are automatically allowed by the host validator, so pointing the Base URL at your internal network address will work without any extra config.

## Memory

A scan holds the whole graph and a parsed-AST cache at once, so a large application needs more than the 1024M default. When it does not fit, PHP kills the process — and with nothing to allocate, neither PHP's own error report nor anything else can render, so the run ends mid-step at exit 255 with no output at all.

The scan holds a small reserve back for exactly that moment: it releases it at shutdown and says what happened and what to change.

```
The scan ran out of memory at --memory-limit=1024M. Raise it (--memory-limit=2048M),
lift it entirely (--memory-limit=-1), or set `memory_limit` in config/laravel-brain.php
so every scan of this project gets the larger value.
```

Set it once per project rather than remembering the flag:

```php
// config/laravel-brain.php
'memory_limit' => env('LARAVEL_BRAIN_MEMORY_LIMIT', '2048M'),
```

`--memory-limit` still overrides it for a single run.

## Storage Driver

Scan output (the graph) can be persisted in one of two ways, selected with the
`driver` config key (env `LARAVEL_BRAIN_DRIVER`):

| Driver | Where it stores | Setup |
| --- | --- | --- |
| `file` (default) | `.graph-*.json` files under `storage/app/laravel-brain/` | none |
| `database` | a database table (`laravel_brain_graphs`) | none — table auto-created on first scan |

Use the `database` driver when `storage/` is not writable or not shared between
the web and CLI processes (read-only containers, multi-node deploys):

```dotenv
LARAVEL_BRAIN_DRIVER=database
# Optional — defaults to laravel_brain_graphs
LARAVEL_BRAIN_DB_TABLE=laravel_brain_graphs
```

By default it uses your app's default connection. You can instead point it at a
**dedicated database** with its own credentials — no need to edit
`config/database.php`. Set the connection name to the bundled `laravel-brain`
connection and fill in its credentials:

```dotenv
LARAVEL_BRAIN_DB_CONNECTION=laravel-brain

LARAVEL_BRAIN_DB_DRIVER=mysql
LARAVEL_BRAIN_DB_HOST=127.0.0.1
LARAVEL_BRAIN_DB_PORT=3306
LARAVEL_BRAIN_DB_DATABASE=laravel_brain
LARAVEL_BRAIN_DB_USERNAME=brain
LARAVEL_BRAIN_DB_PASSWORD=secret
```

Or set `LARAVEL_BRAIN_DB_CONNECTION` to the name of any existing connection
already defined in your `config/database.php`. All reads/writes honour the
chosen connection.

The graph table is **created automatically the first time you run a scan**
(`brain:scan` or the "Scan" button in the UI) — no `php artisan migrate` step
is required. If the table already exists, it is left untouched.

If you'd rather manage the table with your normal migrations instead, publish
the migration and run it yourself:

```bash
php artisan vendor:publish --tag=laravel-brain-migrations
php artisan migrate
```

## Output Files

With the default `file` driver, `brain:scan` writes these files to
`storage/app/laravel-brain/`:

```
.graph-manifest.json   — Tab manifest (list of all route tabs)
.graph-{tab-id}.json   — Per-route subgraph (one per route)
```

Every scan rewrites the whole set and drops the subgraphs it did not write, so a tab
disappears from the viewer when the route behind it disappears from the application.

They are safe to gitignore:

```gitignore
storage/app/laravel-brain/
```

(The `database` driver stores the same payloads as rows in the configured table.)

## Security

The `/_laravel-brain` routes, the artisan commands, and the MCP server are all only registered in the `local` environment by default. Since it's a `require-dev` dependency, it will not be present in production builds (`composer install --no-dev`).

If you do install it in a non-production environment accessible over a network, consider protecting the routes with middleware.

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you'd like to change.

```bash
git clone https://github.com/LaraMint/laravel-brain
cd laravel-brain
composer install
cd frontend && npm install && npm run dev
```

Tests:

```bash
composer test
```

## License

MIT — see [LICENSE](LICENSE) for details.
