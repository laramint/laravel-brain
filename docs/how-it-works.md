# How It Works

What `brain:scan` actually does, in order — and how to point every analyzer at a non-standard layout.

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
        ├─ PendingRequestAnalyzer → finds the methods that build outgoing HTTP requests
        └─ GraphBuilder       → assembles nodes + edges, flags fat classes
                │
                └─ Writes JSON → storage/app/laravel-brain/

GET /_laravel-brain
        │
        └─ BrainController → serves the React SPA + graph JSON via Laravel routes
```

## Route discovery

Laravel Brain recursively scans your entire `routes/` directory — not just `web.php` and `api.php`. Any PHP file under `routes/**` is analyzed, including versioned files like `routes/v1/users.php` or module-specific files like `routes/modules/admin.php`.

### Auto-discover mode

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

::: warning Heads up
In auto-discover mode the source file and line number of each route are not available, so the sidebar will not group routes by their declaring file (everything falls under a single group). Use the default AST mode if file/line grouping matters to you.
:::

## Modular applications

Class names are resolved through the PSR-4 map built from the root `composer.json` **and** from every local package: packages installed from a `path` repository, and nwidart/laravel-modules under `Modules/`. An application whose code lives in packages rather than in `app/` therefore resolves normally — without it, classes are discovered and then dropped, because no file can be found for them.

Regular vendor dependencies are deliberately left out of that map, so the call-chain tracer never walks into framework or library internals.

## Artisan commands

A command is recognised however it names itself: a `$signature` (or the older `$name`) property, Laravel's `#[Signature]` / `#[Description]` attributes, or Symfony's `#[AsCommand]`. A property wins over an attribute, matching Laravel's own precedence.

Scheduled tasks are read from `routes/console.php`, from a schedule split into its own `routes/schedule.php`, from the legacy `Console\Kernel::schedule()` method, and from the `->withSchedule(…)` closure a Laravel 11+ `bootstrap/app.php` uses instead — in both the `Schedule::command()` and `$schedule->command()` spellings, along with `job()` and `call()` entries. Each task keeps the cadence it was chained with *and the arguments that cadence was given* (`dailyAt('05:30')`, `cron('0 3 * * *')`), its timezone, and the guards that decide whether it fires at all (`withoutOverlapping()`, `onOneServer()`, `runInBackground()`, `evenInMaintenanceMode()`). Every task gets its own entry in the sidebar's Schedules bucket, showing what runs and when without opening it.

## Broadcast channels

Channels registered through the `Broadcast` facade are found out of the box. If your application registers them through a wrapper of its own — one that scopes every channel to a tenant, for instance — name that class in `channel_registrars` and its `::channel()` calls are read the same way:

```php
// config/laravel-brain.php
'channel_registrars' => [
    App\Broadcasting\TenantChannel::class,
],
```

## Call chain tracing

From each controller action (and Filament page method), the tracer follows:
- Direct method calls to injected services/repositories
- Static calls (`MyService::method()`)
- Job dispatches (`dispatch(new SendEmail(...))`)
- Event dispatches (`event(new OrderPlaced(...))`)

This produces the full edge list used to build the graph.

## Outgoing HTTP

Every method charted as a flow is also read for calls that leave the application, and any node that
makes one lists them in the inspector under **Outgoing HTTP** and carries a 🌐 marker on the canvas.
Four shapes are recognised:

- **Laravel's client** — `Http::get(...)` and the builder around it, so
  `Http::withToken($t)->retry(3, 100)->timeout(5)->post($url)` reports one POST with its retry and
  its timeout. A pending request parked in a variable is followed, and `Http::pool(...)` is reported
  as the one concurrent call it is.
- **Guzzle** — `new Client([...])` and its verbs, including the `…Async` ones, when the client is
  constructed in the same method (inline, in a variable, or on `$this->…`). A client injected
  through the constructor cannot be seen from a single method and is not reported.
- **curl** — `curl_exec()`, carrying the URL, verb and timeout its handle was given earlier.
- **`file_get_contents()`** — only when the argument visibly starts with `http://` or `https://`;
  a computed argument is much more often a path on disk, and a wrong "this calls a third party"
  costs more than a missing one.

The address is reported as precisely as the source allows, and never more so. A literal URL is shown
as written; `'https://api.stripe.com/v1/charges/'.$id` keeps its readable prefix and is marked
*partly computed*; `config('services.allegro.url')` is shown as that key, which names the
integration as well as the URL would; anything else says the address is computed at runtime rather
than guessing.

Declared timeouts and retries are shown, and so is their absence — a request with no timeout waits
as long as the third party takes, which is worth seeing before an incident rather than during one.

### Requests built in one file and sent in another

Most applications do not call `Http::get()` at the point of use. They keep a client class that hands
out a configured request:

```php
class AllegroHttpClient
{
    public function api(): PendingRequest
    {
        return TransientFailureRetry::applyTo(Http::baseUrl($this->url()))->timeout(5);
    }
}

// elsewhere
$this->client->api()->get('/me');
```

Read one method at a time, the call site is a chain rooted in `$this->client->api()` and says
nothing. Measured on a 60-module application: 50 files made HTTP calls and the graph named one.

So a scan first collects the methods the project *declares* to return
`Illuminate\Http\Client\PendingRequest`, and a chain rooted in one of them is an outgoing call.
Their base URL, timeout and retry travel to the call site, including through a policy wrapper that
takes a request and returns it — so the panel shows the host and the timeout even though neither is
written anywhere near the `get()`.

Only a written return type counts. A method called `api()` that returns `array`, or one with no
return type, is not a builder and never becomes one.

**The limitation, in full:** the collected builders are keyed by **method name**, not by the class
the receiver resolves to. `$this->client->api()` gives the name and nothing else — the type of
`$this->client` is declared in the caller's class, and following it would need cross-file type
resolution the scanner does not do. So an unrelated class with a same-named method feeding a chain
into a `get()` is reported as well, and where two declarations of one name disagree about their base
URL or timeout, neither setting is reported rather than the wrong one.

Detection is on by default and switches off in one place, which skips the scan rather than
discarding its result:

```php
// config/laravel-brain.php
'outgoing_http' => [
    'enabled' => false,
],
```

```dotenv
LARAVEL_BRAIN_OUTGOING_HTTP_ENABLED=false
```

## Blade views

Templates are scanned to link a view to the views it `@include`s or renders as a component, and a view name is resolved back to its file the same way. Both read the same list, so an application that keeps templates in packages points them there once:

```php
// config/laravel-brain.php
'views' => [
    'paths' => ['resources/views', 'app-modules/*/resources/views'],
],
```

An include is linked when the template exists under *any* configured root, so one package rendering another's partial is still an edge.

## Source paths and watch mode

Two lists drive everything that is not an analyzer of its own:

```php
'source_paths' => ['app', 'src'],              // where application classes live
'watch_paths'  => ['app', 'routes', 'config'], // what can change the graph
```

`source_paths` backs the last-resort file lookup — the by-file-name search Brain falls back to when Composer's PSR-4 map cannot place a class name — and marks the part of the tree a scoped rescan may be limited to.

`watch_paths` is what `brain:scan --watch` polls and what the build fingerprint hashes. A change confined to `source_paths` can be handled by a scoped rescan; a change anywhere else — a route file, a config file — forces a full rebuild.

## Service providers and facades

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

### Deferred providers

The same scan reads what each provider says about its own loading. A provider is deferred when it implements `Illuminate\Contracts\Support\DeferrableProvider` — that is the whole of Laravel's `ServiceProvider::isDeferred()`, and the pre-5.8 `protected $defer = true;` property has not been read by the framework since. Deferred providers are marked as such in the graph, along with the service keys their `provides()` returns and any events their `when()` names.

Because Laravel keys its deferred manifest by exactly those strings, "resolving this service loads that provider" is a fact about the source, and the graph draws it: a `boots-deferred-provider` edge runs from each provided service to the provider its resolution would register. Whether a given request actually resolves any of them is a runtime fact, and nothing here claims it.

Three shapes are flagged, because each one fails without an error anywhere:

- **Never boots** — a deferred provider whose `provides()` is empty, usually one that implements the interface without overriding the method. Nothing maps to it, so `register()` and `boot()` never run. Not flagged when `when()` can still register it.
- **Unbacked `provides()`** — a promised key the provider is not seen to register, classically the implementation listed where the contract is bound. Resolving it boots the provider and still fails.
- **`$defer` ignored** — the legacy property without the interface. The provider is registered eagerly on every request while its author believes it is deferred.

A provider whose `provides()` is computed rather than written out is reported as such and never flagged: a declaration we could only half read is not evidence of a defect.

## Filament PHP support

When Filament is installed, the scanner discovers every panel registered via service providers, then resolves its resources, pages, widgets, and relation managers — both explicitly listed (`->resources([...])`) and auto-discovered (`->discoverResources(for: '...')`). Filament page methods are traced through the same call-chain engine as controller actions, so models and services they touch appear in the graph.

Resources and relation managers are recognised through their whole `extends` chain, so a project base class (`class OrderResource extends AppResource`, `AppResource extends Resource`) does not hide them.

Applications that keep their Filament classes somewhere other than `app/Filament` — a modular monolith with no `app/` directory, for instance — point the scanner at their own layout. An entry is used as-is when it is a directory and expanded as a glob pattern otherwise:

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

## Graph Node Types

| Node | Accent Color | Represents |
|------|-------------|------------|
| Route | <span class="color-dot" style="background:#4CAF50"></span> Green `#4CAF50` | HTTP endpoint (`GET /users`) |
| Middleware | <span class="color-dot" style="background:#FF9800"></span> Orange `#FF9800` | Middleware applied to a route |
| Controller | <span class="color-dot" style="background:#2196F3"></span> Blue `#2196F3` | Controller class |
| Action | <span class="color-dot" style="background:#03A9F4"></span> Light Blue `#03A9F4` | Controller method |
| Service | <span class="color-dot" style="background:#9C27B0"></span> Purple `#9C27B0` | Service or helper class |
| Model | <span class="color-dot" style="background:#F44336"></span> Red `#F44336` | Eloquent model |
| Event | <span class="color-dot" style="background:#FFD600"></span> Yellow `#FFD600` | Laravel event |
| Job | <span class="color-dot" style="background:#607D8B"></span> Slate `#607D8B` | Queued job |
| AI Agent | <span class="color-dot" style="background:#A3E635"></span> Lime `#A3E635` | `laravel/ai` agent class |
| AI Tool | <span class="color-dot" style="background:#65A30D"></span> Dark Lime `#65A30D` | Tool an agent exposes to the model |
| Filament Panel | <span class="color-dot" style="background:#7C3AED"></span> Violet `#7C3AED` | Filament panel definition |
| Filament Resource | <span class="color-dot" style="background:#A855F7"></span> Purple `#A855F7` | Filament resource class |
| Filament Page | <span class="color-dot" style="background:#C084FC"></span> Lavender `#C084FC` | Filament page class |
| Filament Page Method | <span class="color-dot" style="background:#E879F9"></span> Pink `#E879F9` | Method on a Filament page |
| Filament Widget | <span class="color-dot" style="background:#06B6D4"></span> Cyan `#06B6D4` | Filament widget class |
| Filament Relation Manager | <span class="color-dot" style="background:#0891B2"></span> Teal `#0891B2` | Filament relation manager |

::: tip Note
Command, Schedule, Channel, and Repository nodes are discovered and added to the graph but use the closest matching accent color from their parent type.
:::

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

::: tip
The last two rows aren't separate route registrations — `routes/brain.php` defines a single catch-all `GET /{any?}` route that serves both static SPA assets and `.graph-*.json` files, falling through to the SPA shell for everything else.
:::

Stress testing uses [`laramint/laravel-stress`](https://github.com/LaraMint/laravel-stress), installed automatically as a dependency — see [Usage → Route stress testing](/usage.md#route-stress-testing) for the host allowlist.

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
