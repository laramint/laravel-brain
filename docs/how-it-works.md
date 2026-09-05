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

## Broadcasting

Separate from channel *registration* above, Brain also reads which events *broadcast*, and onto which channels. An event declares this about itself — implementing `ShouldBroadcast` (or `ShouldBroadcastNow`) and naming channels in `broadcastOn()` — so it's read from the class declaratively, with no dispatch trace needed: an event nothing traced ever reaches still shows what it would broadcast if dispatched.

A matching channel gets a real edge from the event to it, labelled **broadcasts on**. Channels are matched by shape, not string equality — `orders.{id}` from the event and `orders.{orderId}` from `routes/channels.php` are recognised as the same channel — and a channel name built entirely from a runtime value is reported as computed rather than matched to a guess.

The event's own detail panel gets a **Broadcasts** section: whether it broadcasts immediately or is queued first, its `broadcastAs()` alias when it overrides the class name, and any conditional or custom-payload logic (`broadcastWhen()`, `broadcastWith()`) it declares.

```php
// config/laravel-brain.php
'broadcasting' => [
    'enabled' => env('LARAVEL_BRAIN_BROADCASTING_ENABLED', true),
    'paths' => ['app/Events'],
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

## Cache operations

Every method charted as a flow is also read for calls to the `Cache` facade and the `cache()` helper — including a `store()`/`driver()`/`tags()` chain in front of them — and shown on the node as a **Cache** section: one row per call, split into **read**, **write**, **invalidate**, and **lock**, with the key (a literal string shown as written, a constructed one labelled rather than guessed at, or "whole store" for a store-wide flush), plus any declared TTL, store, and tags. A matching step in the method's flowchart carries the same badge.

`Cache::lock($key)->get(fn)` is read as a `lock`, and `Cache::memo()->get()` — Laravel's read-through memoization — is read as a `read`, the same as any other cache read.

This is only the facade and the helper — an injected `$this->cache` property using the `Repository` contract directly is not recognised, since there's no fixed call shape to key detection on.

```php
// config/laravel-brain.php
'cache_operations' => [
    'enabled' => env('LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED', true),
],
```

Turning it off is off at the source — no statement is inspected for a cache call at all, rather than a filter applied to results computed anyway. Measured over a 1,185-file, 5,571-method corpus with no cache calls in it, flow extraction goes from 11.5ms to 14.2ms with the pass on — the cost of looking and finding nothing; over source saturated with cache calls (1,000 methods, six calls each) it goes from 9.9ms to 18.4ms. Against a full scan of that same corpus (494ms) neither delta is measurable above run-to-run noise. Turn it off instead when the application wraps caching in its own abstraction rather than the facade, so what Brain can see would be a misleading fraction of what's actually cached — a half-true panel is worse than no panel.

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

The whole read is a switch, on by default. It costs a second walk over `container_bindings.provider_paths`; parse results are shared across the build, so nothing is re-read from disk. Off means the walk does not happen — not that its result is discarded:

```php
// config/laravel-brain.php
'service_providers' => [
    'enabled' => false,
],
```

Or `LARAVEL_BRAIN_SERVICE_PROVIDERS_ENABLED=false` in the environment.

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


## Macros

Laravel's `Macroable` trait lets a class marked with it grow new methods at runtime — a macro is invisible by construction: `$table->money()` resolves to nothing if you open `Blueprint` itself, and nothing about the class explains where the method came from.

Brain scans `macros.paths` (`app` by default) for two forms:

- `X::macro('name', function (...) {...})` — one named method.
- `X::mixin(new Y)` / `X::mixin(Y::class)` — every public, non-static, non-constructor method of `Y` becomes a method of `X`.

Detection keys on the **call**, not on the receiver's own traits — Filament ships its own separate `Macroable`, so checking for Illuminate's specifically would silently miss every Filament component macro. What isn't attempted is resolving a *call site* back to its macro: proving `$table` is actually a `Blueprint` at the point `$table->money()` is called would need cross-file type inference the scanner doesn't do, and migrations aren't part of a scan's traced call chains anyway. This shows where a method is **defined**, not everywhere it's used.

Each receiver class gets a `macro_group` node (labelled `ClassName (N)`) and each added method its own `macro` node underneath it, together in a dedicated **Macros** tab. Selecting a macro node shows where it comes from — `macro()`, one method at a time, or `mixin`, and which class it was mixed in from — and an edge to the file or provider that registered it.

```php
// config/laravel-brain.php
'macros' => [
    'enabled' => env('LARAVEL_BRAIN_MACROS_ENABLED', true),
    'paths' => ['app'],
],
```

Turning it off skips the scan for macro/mixin calls entirely, rather than hiding results computed anyway.

## Transaction, chain & batch regions

Three things a call chain can belong to are drawn as a boundary around the nodes that belong to it, rather than as a badge on any one of them — a `DB::transaction()` closure commits or rolls back as a unit, and `Bus::chain()`/`Bus::batch()` jobs are dispatched as a unit, so the canvas draws the unit.

**Transactions.** A `DB::transaction(function () {...})` closure, or a hand-rolled `DB::beginTransaction()` … `DB::commit()` range, gets a dashed boundary around every node it runs, labelled "transaction 1", "transaction 2", and so on for however many a method opens. A `catch` block that rolls back gets its own separate boundary, so the rollback path reads apart from the happy path instead of overlapping it.

```php
// config/laravel-brain.php
'transactions' => [
    'enabled' => env('LARAVEL_BRAIN_TRANSACTIONS_ENABLED', true),
],
```

The detector walks every method body the tracer scans, whether or not the application opens a single transaction — on a synthetic corpus with no `DB::transaction` at all, that walk measured +8.2% on a full 1,185-file scan. That's the price of confirming there's nothing to draw. Turning it off skips the walk rather than discarding its result, so off costs nothing at all.

**Chains and batches.** The same drawing mechanism generalises to `Bus::chain([...])`, `Bus::batch([...])`, `Job::withChain([...])`, and `dispatch(...)->chain([...])` — literal entries in the array become a boundary around the jobs dispatched together: **chains** run in order, drawn with arrows through the sequence; **batches** run without one, drawn without them.

| Region | Color | Ordered |
|--------|-------|---------|
| Transaction | Amber `#d99a2b` | — |
| Rollback | Red `#c2554a` | — |
| Chain | Blue `#5f8fa8` | Yes — arrows show run order |
| Batch | Purple `#8a7fb5` | No |

Only literal members are drawn — `Bus::chain([new A, new B])` — a member built from a variable, a factory call, or a spread expression is dropped from the drawing and reported unresolved, the same policy the rest of a scan uses for any dispatch it can't resolve statically. A batch nested inside a chain (or the reverse) isn't drawn.

```php
// config/laravel-brain.php
'job_groups' => [
    'enabled' => env('LARAVEL_BRAIN_JOB_GROUPS_ENABLED', true),
],
```

Turning chains/batches off doesn't hide the jobs themselves — each member of a `Bus::chain([...])` is still traced as an edge — only the boundary drawn around them. It's independent of `transactions` above; turning either off doesn't affect the other.

## Event choreography

Every other tab grows forward from a route, a command, or some other single entry point. Events are different: a listener can itself fire further events, and that cascade doesn't belong to any one route — it's a property of the event system as a whole.

The **Events** tab holds every event the application declares as its own graph: an edge to each listener that handles it, and an edge onward from that listener to any event *it* fires in turn — so a chain of events triggering each other is visible as a path, not just as separate one-hop facts. It's also where an event with no listeners at all — dispatched into the void — is easiest to spot, since it's a node with nothing leaving it.

The facts behind the tab — listener count, whether it's an orphan, whether it broadcasts, whether it's queued (`ShouldQueue`), whether it fires before or after the enclosing transaction commits (`ShouldDispatchAfterCommit` / `ShouldHandleEventsAfterCommit`) — are also stamped onto that same event node wherever else it appears, so a route's own lifecycle graph shows them too, not only the dedicated tab.

```php
// config/laravel-brain.php
'events' => [
    'enabled' => true,
    'paths' => ['app/Events'],
],
```

## Job lifecycle

Selecting a queued job node shows a **Queue behaviour** section: attempts, timeout, backoff, max exceptions, uniqueness (and its scope and duration, for a job implementing `ShouldBeUnique`), whether it's batchable, whether it dispatches after the enclosing transaction commits, whether its payload is encrypted, and any queue middleware it declares (`WithoutOverlapping`, for instance).

This isn't a toggleable pass — a job that declares none of the above simply gets no section, since several blank fields would say less than nothing. Nor is it a graph overlay: unlike transactions and chains/batches above, a job's lifecycle facts live in its detail panel only, with no distinct color or boundary on the canvas.

A property computed from a method body that reads runtime state, rather than written as a literal, is reported as "decided at runtime" rather than guessed at — the same policy the rest of a scan uses whenever a value depends on something the scan can't see.

## Table statistics and database schema

Two passes read the live database during a scan rather than parsing source — the only parts of a scan that touch a database at all.

**Table statistics** read how much data each model's table actually holds: total size, a row-count estimate, and the heap/index split where the driver can answer for it. It's shown as a **Table Data** section on the model's node — row count (prefixed `~` when it's a planner estimate rather than an exact count; exact on SQL Server), table size, index size, and total.

```php
// config/laravel-brain.php
'table_stats' => [
    'enabled' => env('LARAVEL_BRAIN_TABLE_STATS', true),
    'connection' => null, // set a connection name when models don't live on the default one
],
```

**Database schema** reads each model's real columns, indexes, and foreign keys from the database catalogue — not from migrations, which say what was *intended*, and any project of age has a schema that no longer matches the sum of them. It's shown as a **Schema** tab on the model's node, and a foreign key with no covering index (its columns must be a leading prefix of some index's columns, not merely present in one) is flagged there — and again, alongside routes' own security exposure, in the shared **Risks** tab as a `MISSING_FK_INDEX` finding.

```php
// config/laravel-brain.php
'schema' => [
    'enabled' => env('LARAVEL_BRAIN_SCHEMA', true),
    'connection' => null,
    'timeout' => env('LARAVEL_BRAIN_SCHEMA_TIMEOUT', 2), // seconds; null uses the driver's own default (~30s)
],
```

Both fail quietly by design: no connection, no permission on the catalogue, or a driver nobody anticipated all end as missing numbers rather than a failed scan, so leaving either on costs nothing where there's nothing to read. Both also need `Schema::getTables()`, added in Laravel 10 — on Laravel 9 they report nothing, by design, rather than erroring. `schema.timeout` exists because a *refused* connection fails instantly, but a host that drops packets (a database reached over a VPN that isn't up, say) blocks for the driver's full default — on every scan and every watch-mode poll — without it.

## Morph map aliases

`Relation::morphMap()` gives a model a short alias — the value that actually lands in your `*_type` columns, and so the one you're holding when you arrive from a row or a query rather than the class name you'd expect. Brain shows that alias on the model's node, beside its other schema facts.

Unlike everything else in a scan, this one fact is read from the **running application** rather than parsed out of source: a scan runs inside your own app, so `Relation::morphMap()` already holds the whole answer, including aliases a package registered from its own provider or a branch only one environment takes — none of which a file parser alone could see. Where the application calls `Relation::requireMorphMap()`, a model left out of the map is flagged, since there's no class-name fallback in that mode — the first `getMorphClass()` call on it throws.

```php
// config/laravel-brain.php
'morph_map' => [
    'enabled' => env('LARAVEL_BRAIN_MORPH_MAP_ENABLED', true),
],
```

Turning it off means `Relation::morphMap()` is never consulted at all — not consulted and the answer discarded — which is the escape hatch if you'd rather the scanner not touch framework state. Models still appear either way; they just carry no alias, and nothing is flagged as missing one.

## File history and riskiest files

Two more passes read git directly, rather than the application's source or its database.

### A file's last commit

A node's Source view — inline in the sidebar or in the popup (⤢) — shows who last committed its file and a side-by-side diff against the revision before it: author, date, message, and short hash. This is read on demand, once per file, the moment you open it — not precomputed at scan time — so it's always current between scans. It's the file's single most recent commit, not a browsable history.

When the repository's `origin` remote points at a recognised host, the hash becomes a link out to that commit: `.../commit/<hash>` on GitHub (and GitHub Enterprise, which shares its URL shape), `.../-/commit/<hash>` on GitLab (nested subgroups included), `.../commits/<hash>` on Bitbucket. No remote, or a host Brain doesn't recognise, leaves the hash as plain text.

MCP: `brain_get_file_history` returns the same commit metadata and diff for any node id, so an AI assistant can see recent authorship and change context before proposing an edit.

### Riskiest files

A scan-time pass counts how many commits touched each file in a recent window, then combines that with the file's own single most complex method into one ranking: complexity alone says a method is hard to read, commit frequency alone says a file is popular, and the two together are the "code as a crime scene" hotspot signal (Adam Tornhill's technique) — `riskScore = maxComplexity × commitCount`. The result surfaces two ways: a **Riskiest Files** panel in the sidebar (collapsed by default), and a **Git Activity** section — commit count, last-changed date, last author — on every node belonging to a file with recent commits, whether or not that file makes the ranking itself.

```php
// config/laravel-brain.php
'churn' => [
    'enabled' => env('LARAVEL_BRAIN_CHURN', true),
    'since' => env('LARAVEL_BRAIN_CHURN_SINCE', '1 year ago'), // bounds cost to a fixed window regardless of repo age
    'limit' => env('LARAVEL_BRAIN_CHURN_LIMIT', 50), // files kept in the ranking, highest risk first
],
```

Like table statistics and schema above, this is the one part of a scan that shells out to git rather than the filesystem or a database, and it fails just as quietly: no git binary, no repository, or no commits in the window all end as an empty ranking rather than a failed scan. A rename resets a file's churn history — `git log --name-only` without `--find-renames` sees a rename as a delete plus an add — matching the underlying technique's usual simplicity rather than working around it.

MCP: `brain_get_riskiest_files` returns the same ranked list.

## Reachability

Every other tab is grown forward from one entry point, which means a gap in the graph is invisible from inside it. Measured on one application, the graph knew 45 of its 211 event classes and 27 of its 113 job classes, and no screen said so.

The **Reachability** tab is the inverse view. It has three sections:

1. **Entry points**, grouped by kind — routes, console commands, scheduled entries, broadcast channels, queued listeners, Filament panels/resources/pages. Nothing in the application is reachable except through one of these, so their inventory is the denominator for everything below.
2. **Nothing reaches these from an entry point**, grouped by kind, largest group first — so "17 jobs nothing dispatches" is answerable at a glance.
3. **Outside what the tracer follows** — service providers and exceptions, kinds Brain has no call edge for at all. The framework boots a provider and an exception is thrown rather than called, so their absence from the graph is the expected outcome and says nothing either way. They are kept apart so they do not bury the section above.

::: warning This is not a dead-code report
"Nothing reaches this from a traced entry point" is a statement about the tracer, not about whether the code runs. A class resolved out of the container, fronted by a facade, named as a string in config, or built by reflection is alive and will still land on the list.

Every reference Brain *did* find is shown next to the class, so you can tell the two apart:

| Shown as | Means |
|----------|-------|
| bound in the container | named as the abstract or the concrete of a `bind()` / `singleton()` in a provider |
| reached through a facade | is a facade, or the class one resolves to |
| named in config/ | appears as `Foo::class` or a quoted FQCN under `config/` |
| inherited by a class that is reached | a class the tracer did reach extends it, implements it, or uses it as a trait |
| named as a class-string elsewhere | appears as `Foo::class` or a quoted FQCN in another source file |

A class with none of these is the one worth opening first — and still worth opening rather than deleting.
:::

The tab reads the classes declared under [`source_paths`](#source-paths-and-watch-mode) and costs one extra parse pass over them plus `config/`. **Off by default** — the only setting in the config file that's a judgement call rather than a fact, because what that extra pass costs depends entirely on how much of the codebase the rest of the scan already parses on its own: measured worst case ×3.0 the scan time (an app whose normal build only touches a fraction of its total classes), measured best case +2% (within noise — an app whose modules are nearly all already parsed for other reasons). Turn it on when you're hunting for what nothing reaches; leave it off for a scan you run often:

```php
// config/laravel-brain.php — off by default
'reachability' => [
    'enabled' => true, // or LARAVEL_BRAIN_REACHABILITY_ENABLED=true
],
```

## Graph Node Types

| Node | Accent Color | Represents |
|------|-------------|------------|
| Route | <span class="color-dot" style="background:#4CAF50"></span> Green `#4CAF50` | HTTP endpoint (`GET /users`) |
| Middleware | <span class="color-dot" style="background:#FF9800"></span> Orange `#FF9800` | Middleware applied to a route |
| Controller | <span class="color-dot" style="background:#2196F3"></span> Blue `#2196F3` | Controller class |
| Controller action | <span class="color-dot" style="background:#03A9F4"></span> Light Blue `#03A9F4` | Controller method (node type `action`) |
| Action | <span class="color-dot" style="background:#84cc16"></span> Lime `#84cc16` | Single-purpose action class under `Actions/` (node type `action_class`) |
| Macro Receiver | <span class="color-dot" style="background:#B45309"></span> Dark Amber `#B45309` | A class that methods were added to via `macro()`/`mixin()` |
| Macro | <span class="color-dot" style="background:#F59E0B"></span> Amber `#F59E0B` | A method added to a class it wasn't declared on |
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
| Entry Point | <span class="color-dot" style="background:#22D3EE"></span> Cyan `#22D3EE` | A root on the Reachability tab |
| Not Reached | <span class="color-dot" style="background:#94A3B8"></span> Grey `#94A3B8` | A class no entry point's chain arrives at. Grey on purpose — it is a question, not a verdict |

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
