<?php

declare(strict_types=1);

return [

    // -------------------------------------------------------------------------
    // Storage Driver
    // -------------------------------------------------------------------------
    // Where scan output (the graph) is persisted.
    //
    //   'file'      Write .graph-*.json files under storage/app/laravel-brain
    //               (default — zero setup, requires a writable storage/ dir).
    //   'database'  Store the graph in a database table. Run the migration
    //               first: php artisan migrate. Useful when storage/ is not
    //               writable or not shared between web/CLI processes.
    //
    // Override via the LARAVEL_BRAIN_DRIVER env variable.
    //
    'driver' => env('LARAVEL_BRAIN_DRIVER', 'file'),

    // Settings for the 'database' driver.
    //
    'database' => [

        // Table the graph is stored in (created by the package migration —
        // run `php artisan migrate`, or publish it with the
        // laravel-brain-migrations tag first).
        //
        'table' => env('LARAVEL_BRAIN_DB_TABLE', 'laravel_brain_graphs'),

        // Which database connection to use.
        //
        //   null            Use the app's default connection.
        //   '<name>'         Use an existing connection from config/database.php.
        //   'laravel-brain'  Use the self-contained connection defined below
        //                    (set the LARAVEL_BRAIN_DB_* env vars for it).
        //
        // The migration and all reads/writes honour this connection.
        //
        'connection' => env('LARAVEL_BRAIN_DB_CONNECTION'),

        // Self-contained connection definitions. Each entry here is registered
        // into Laravel's database.connections at boot, so you can keep the
        // brain graph in a dedicated database with its own credentials without
        // touching your app's config/database.php. Point 'connection' above at
        // one of these keys to activate it.
        //
        'connections' => [
            'laravel-brain' => [
                'driver' => env('LARAVEL_BRAIN_DB_DRIVER', 'mysql'),
                'host' => env('LARAVEL_BRAIN_DB_HOST', '127.0.0.1'),
                'port' => env('LARAVEL_BRAIN_DB_PORT', '3306'),
                'database' => env('LARAVEL_BRAIN_DB_DATABASE'),
                'username' => env('LARAVEL_BRAIN_DB_USERNAME'),
                'password' => env('LARAVEL_BRAIN_DB_PASSWORD', ''),
                'unix_socket' => env('LARAVEL_BRAIN_DB_SOCKET', ''),
                'charset' => env('LARAVEL_BRAIN_DB_CHARSET', 'utf8mb4'),
                'collation' => env('LARAVEL_BRAIN_DB_COLLATION', 'utf8mb4_unicode_ci'),
                'prefix' => '',
            ],
        ],
    ],

    // -------------------------------------------------------------------------
    // Memory Limit
    // -------------------------------------------------------------------------
    // Memory the scan runs with, applied before analysis starts. Accepts the PHP
    // notation (1024M, 2G) or -1 for no limit; the minimum accepted is 1024M.
    //
    // A scan holds the whole graph plus a parsed AST cache, so a large application
    // needs more than the default. When it does not fit, PHP kills the process — the
    // scan reports that and names this setting rather than exiting silently.
    //
    // `--memory-limit` on the command overrides this for a single run.
    //
    // Override via the LARAVEL_BRAIN_MEMORY_LIMIT env variable.
    //
    'memory_limit' => env('LARAVEL_BRAIN_MEMORY_LIMIT', '1024M'),

    // -------------------------------------------------------------------------
    // Auto-Discover Routes
    // -------------------------------------------------------------------------
    // When true, RouteAnalyzer skips AST parsing of route_paths and instead
    // pulls every registered route from the running app via Route::getRoutes().
    // This captures routes registered by packages/providers (Filament, Sanctum,
    // Livewire, Telescope, ...) that AST scanning cannot see.
    //
    // Trade-off: file/line for each route are not populated in this mode,
    // so the sidebar will not group routes by their declaring file. See the
    // README's "Auto-Discover Routes" section for details.
    //
    // Override via the LARAVEL_BRAIN_AUTO_DISCOVER_ROUTES env variable.
    //
    'auto_discover_routes' => env('LARAVEL_BRAIN_AUTO_DISCOVER_ROUTES', false),

    // When auto_discover_routes is on, skip any route whose handler (controller
    // class or closure) lives under the project's vendor/ directory. This hides
    // package-internal routes such as Telescope, Horizon, Ignition, Sanctum's
    // csrf-cookie, etc. Set to false to include them.
    //
    // Override via the LARAVEL_BRAIN_AUTO_DISCOVER_EXCLUDE_VENDOR env variable.
    //
    'auto_discover_exclude_vendor' => env('LARAVEL_BRAIN_AUTO_DISCOVER_EXCLUDE_VENDOR', true),

    // -------------------------------------------------------------------------
    // Security Analysis
    // -------------------------------------------------------------------------
    // Override / extend the SecurityAnalyzer heuristics that classify each
    // route's exposure and decide what counts as throttled or trusted.
    //
    // The analyzer always starts from a conservative built-in default list
    // and *adds* anything declared here on top — it never replaces the
    // defaults. Entries are matched as case-insensitive prefixes; a plain
    // name like 'auth.custom' matches 'auth.custom' and 'auth.custom:api'.
    //
    // When in doubt, leave everything empty. The defaults catch the
    // standard Laravel middleware (`auth`, `auth:sanctum`, `signed`,
    // `throttle`, etc.).
    //
    'security' => [

        // Extra middleware aliases or FQCN prefixes that Brain should treat
        // as authentication. Add your application's custom HMAC / api-key
        // / bearer-token middleware here so routes guarded by it stop
        // being reported as "public" / PUBLIC_WRITE.
        //
        // Examples:
        //   'auth.custom',
        //   'merchant.hmac',
        //   App\Http\Middleware\AuthenticateMerchant::class,
        //
        'auth_middleware' => [],

        // Extra middleware aliases or FQCN prefixes that Brain should treat
        // as rate-limiting. The default list already covers `throttle:` and
        // ThrottleRequests; add any custom throttle middleware here.
        //
        'throttle_middleware' => [],

        // Route names whose routes are trusted (PUBLIC_WRITE will be
        // suppressed even when no recognised auth middleware is present).
        //
        // Use this for endpoints whose authentication is enforced *outside*
        // of Laravel's middleware system — typically a webhook with HMAC
        // verification done in the controller, or an endpoint authenticated
        // by a token in the URL.
        //
        // Supports glob patterns (`*` and `?`), matched case-insensitively.
        //
        // Examples:
        //   'webhooks.*',
        //   'portal.*',
        //
        'trusted_route_names' => [],

        // Route URI globs whose routes are trusted, same semantics as
        // `trusted_route_names`. Matched against the route's URI without
        // a leading slash. Useful when routes are unnamed.
        //
        // Examples:
        //   'webhooks/*',
        //   'portal/*/cancel',
        //
        'trusted_route_uris' => [],
    ],

    // -------------------------------------------------------------------------
    // Route File Paths
    // -------------------------------------------------------------------------
    // Glob patterns (relative to project root) used to discover route files.
    // The leading fixed segments before the first wildcard become the base
    // directory that is scanned recursively for .php files.
    //
    // Pattern anatomy:  routes / * / *.php
    //                   ^fixed  ^dir ^file
    //
    // Common examples:
    //   'routes/web/home.php'       – single explicit file
    //   'app/routes/api.php'        – custom routes location
    //
    'route_paths' => [
        'routes/*/*.php',
    ],

    // -------------------------------------------------------------------------
    // Channel File Paths
    // -------------------------------------------------------------------------
    // Glob patterns used to find broadcast channel registration files.
    // Only files whose basename contains "channel" are parsed.
    //
    // Default: scan everything under routes/ (typically routes/channels.php).
    //
    'channel_paths' => [
        'routes/*/*.php',
    ],

    // -------------------------------------------------------------------------
    // Channel Registrars
    // -------------------------------------------------------------------------
    // Classes whose static channel() call registers a broadcast channel, on top of
    // the Broadcast facade, which is always recognised.
    //
    // Add your own wrapper here if channels are not registered through the facade
    // directly — a class that scopes every channel to a tenant, for example. Entries
    // are matched by short class name, so either spelling works:
    //
    //   'channel_registrars' => [
    //       'TenantChannel',
    //       App\Broadcasting\TenantChannel::class,
    //   ],
    //
    'channel_registrars' => [],

    // -------------------------------------------------------------------------
    // Job Dispatch
    // -------------------------------------------------------------------------
    // Brain resolves the built-in dispatch verbs (dispatch(), dispatch_sync(),
    // Job::dispatch(), Bus::dispatch/chain/batch, $this->dispatch). List any
    // custom global helper that wraps a queued job here so its dispatches are
    // followed too, e.g. a project's own dispatch_with_retries().
    //
    'dispatch' => [
        'helpers' => [],
    ],

    // -------------------------------------------------------------------------
    // Event Listeners
    // -------------------------------------------------------------------------
    // Event → listener edges are discovered from every registration form:
    //   - convention: a class under "paths" whose handle()/__invoke() type-hints
    //     the event in its first parameter;
    //   - attribute: a class or method marked #[AsEventListener] under "paths";
    //   - $listen / $subscribe: the maps declared in the providers under
    //     "provider_paths" (subscriber subscribe() methods are followed too).
    // So the graph shows what runs when an event dispatches, however it is wired.
    //
    /*
    |--------------------------------------------------------------------------
    | Event choreography
    |--------------------------------------------------------------------------
    |
    | An "Events" tab showing every event the application defines, what listens
    | to it, and what those listeners fire in turn. Discovery is by directory,
    | because an event nobody dispatches from a route is still an event — and an
    | event nobody listens to is the most interesting one on the list.
    |
    */
    'events' => [
        'enabled' => true,

        'paths' => [
            'app/Events',
        ],
    ],

    'listeners' => [
        'paths' => [
            'app/Listeners',
        ],
        'provider_paths' => [
            'app/Providers',
        ],
    ],

    // -------------------------------------------------------------------------
    // Model Observers
    // -------------------------------------------------------------------------
    // Model → observer edges are discovered from every registration form:
    //   - attribute: a model under "model_paths" marked #[ObservedBy(...)];
    //   - observe(): a Model::observe(Observer::class) call, whether made from a
    //     provider under "provider_paths" or from the model's own booted()
    //     via self::observe() / static::observe().
    // So the graph shows which observer runs on a model's lifecycle events,
    // however it is wired.
    //
    // -------------------------------------------------------------------------
    // Database Transactions
    // -------------------------------------------------------------------------
    // Whether call chains are read for the transaction spans they run inside, so
    // the canvas can draw a boundary around the work that commits or rolls back
    // together.
    //
    // What it costs: the detector walks every method body the tracer scans, and
    // it walks them whether or not the application opens a single transaction.
    // Measured on a synthetic corpus containing no `DB::transaction` at all:
    // +8.2% on a full 1,185-file scan, of which the lifecycle phase is +36%.
    // That is the price of being told there is nothing to draw; an application
    // that does use transactions pays it plus the real work.
    //
    // Turning it off skips the traversal rather than discarding its result, so
    // off costs nothing at all.
    //
    // Override via the LARAVEL_BRAIN_TRANSACTIONS_ENABLED env variable.
    //
    'transactions' => [
        'enabled' => env('LARAVEL_BRAIN_TRANSACTIONS_ENABLED', true),
    ],

    'observers' => [
        'model_paths' => [
            'app/Models',
        ],
        'provider_paths' => [
            'app/Providers',
        ],
    ],

    // -------------------------------------------------------------------------
    // Authorization Policies
    // -------------------------------------------------------------------------
    // Model → policy edges are resolved the way Laravel's Gate resolves a
    // policy, in precedence order:
    //   - explicit: an AuthServiceProvider::$policies map or a
    //     Gate::policy(Model::class, Policy::class) call in a provider under
    //     "provider_paths";
    //   - attribute: a model marked #[UsePolicy(Policy::class)];
    //   - convention: the guessed App\Policies\FooPolicy for App\Models\Foo,
    //     used only when that policy class exists.
    // So the graph shows which policy authorizes each model.
    //
    'policies' => [
        'provider_paths' => [
            'app/Providers',
        ],
    ],

    // -------------------------------------------------------------------------
    // Command Entry Points
    // -------------------------------------------------------------------------
    // Laravel commands are registered through three distinct entry points.
    // Each key accepts an array of glob patterns (relative to project root).
    //
    // console_route_paths  Closure-based commands via Artisan::command().
    //                      Only files whose basename contains "console" are parsed.
    //                      (typically routes/console.php)
    //
    // class_paths          Directories containing Command class files.
    //                      (typically app/Console/Commands/)
    //
    // kernel_paths         Path(s) to Console\Kernel.php for the $commands
    //                      property and the schedule() method.
    //
    'commands' => [
        'console_route_paths' => [
            'routes/*/*.php',
        ],
        'class_paths' => [
            'app/Console/Commands/*/*.php',
        ],
        'kernel_paths' => [
            'app/Console/Kernel.php',
        ],
    ],

    // -------------------------------------------------------------------------
    // Model Search Paths
    // -------------------------------------------------------------------------
    // Directories (relative to the project root) scanned to discover Eloquent
    // models for the "Model ERD" tab. Every *.php file under each directory is
    // parsed recursively; classes whose `extends` chain reaches Eloquent's
    // Model / Authenticatable / Pivot are treated as models.
    //
    // Leave the array empty to fall back to scanning every PSR-4 source root
    // declared in your composer.json (slower on large apps, but zero-config).
    //
    // Examples:
    //   'app/Models'
    //   'app/Domain/Billing/Models'
    //   'src/Models'
    //
    'models' => [
        'paths' => [
            'app/Models',
        ],
    ],

    // -------------------------------------------------------------------------
    // Filament Search Paths
    // -------------------------------------------------------------------------
    // Directories (relative to the project root) scanned for Filament panels and
    // for the resources, pages, widgets and relation managers they expose.
    //
    // An entry is used as-is when it is a directory and expanded as a glob pattern
    // otherwise, so a modular monolith that keeps no app/ directory can point these
    // at its packages:
    //
    //   'panel_paths' => ['app-modules/*/src/Filament'],
    //   'paths'       => ['app-modules/*/src/Filament'],
    //
    // panel_paths  Where panels are declared. A file named *PanelProvider.php is
    //              treated as a panel by convention (that is what Filament's own
    //              installer writes); any other file counts only when it actually
    //              builds a Panel::make() chain, so pointing this at a whole source
    //              tree does not turn every class into a panel.
    //
    // paths        Roots of the Filament class tree. Resources are recognised by a
    //              Resources/ path segment, pages by Pages/, widgets by Widgets/ and
    //              relation managers by RelationManagers/ — the layout Filament's
    //              generators produce.
    //
    'filament' => [
        'panel_paths' => [
            'app/Providers/Filament',
        ],
        'paths' => [
            'app/Filament',
        ],
    ],

    // -------------------------------------------------------------------------
    // Container Binding Search Paths
    // -------------------------------------------------------------------------
    // Directories holding service providers, scanned recursively for container
    // registrations: bind() / singleton() / scoped() (and their *If variants) plus
    // the $bindings property.
    //
    // An entry is used as-is when it is a directory and expanded as a glob pattern
    // otherwise, so an application whose providers live in packages can say:
    //
    //   'provider_paths' => ['app-modules/*/src'],
    //
    'container_bindings' => [
        'provider_paths' => [
            'app/Providers',
        ],
    ],

    // -------------------------------------------------------------------------
    // Facade Search Paths
    // -------------------------------------------------------------------------
    // Directories scanned for application-level facades — classes whose inheritance
    // chain reaches Illuminate\Support\Facades\Facade. The same directories back the
    // by-short-name lookup used to follow a facade's parent chain, so a base facade
    // must be inside them too.
    //
    // Glob patterns are expanded, as above:
    //
    //   'paths' => ['app-modules/*/src'],
    //
    'facades' => [
        'paths' => [
            'app',
        ],
    ],

    // -------------------------------------------------------------------------
    // Source Paths
    // -------------------------------------------------------------------------
    // Directories holding application classes. Two things use them.
    //
    // The first is the last-resort file lookup: when Composer's PSR-4 map cannot place
    // a class name, Brain falls back to searching these directories by file name. The
    // map handles the normal case, so this only fires for a class the map does not
    // cover — but when it fires against the wrong directories it finds nothing.
    //
    // The second is watch mode: a change confined to these directories can be handled
    // by a scoped rescan, while a change anywhere else (routes, config) forces a full
    // rebuild.
    //
    // Glob patterns are expanded, so an application whose code lives in packages says:
    //
    //   'source_paths' => ['app-modules/*/src'],
    //
    'source_paths' => [
        'app',
        'src',
    ],

    // -------------------------------------------------------------------------
    // Watch Paths
    // -------------------------------------------------------------------------
    // Directories polled by `brain:scan --watch` and hashed into the build fingerprint
    // that decides whether a rescan is needed at all. Anything that can change the
    // graph belongs here — application code, route files, configuration.
    //
    // Glob patterns are expanded:
    //
    //   'watch_paths' => ['app-modules', 'config'],
    //
    'watch_paths' => [
        'app',
        'routes',
        'config',
    ],

    // -------------------------------------------------------------------------
    // Blade View Paths
    // -------------------------------------------------------------------------
    // Directories scanned for Blade templates, used to link a view to the views it
    // includes or renders as a component, and to resolve a view name to its file.
    //
    // Glob patterns are expanded:
    //
    //   'views' => ['paths' => ['resources/views', 'app-modules/*/resources/views']],
    //
    'views' => [
        'paths' => [
            'resources/views',
        ],
    ],

    // -------------------------------------------------------------------------
    // Table Statistics
    // -------------------------------------------------------------------------
    // How much data each model's table actually holds, read from the live database
    // during a scan: total size on every driver Laravel supports, plus a row estimate
    // and the heap/index split where the driver can answer for them.
    //
    // This is the only part of a scan that touches a database. It is written to fail
    // quietly — no connection, no permission on the catalog, or a driver nobody
    // anticipated all end as missing numbers rather than a failed scan — so leaving it
    // on costs nothing where there is nothing to read. Turn it off to skip the queries
    // entirely, or name a connection when the models do not live on the default one:
    //
    //   'connection' => 'tenant',
    //
    'table_stats' => [
        'enabled' => env('LARAVEL_BRAIN_TABLE_STATS', true),
        'connection' => null,
    ],

    // -------------------------------------------------------------------------
    // Database Schema
    // -------------------------------------------------------------------------
    // The real shape of each model's table — columns, indexes and foreign keys —
    // read from the database catalogue during a scan, and used to flag a foreign key
    // that has no index to read it by.
    //
    // Read from the catalogue rather than from migrations on purpose: migrations say
    // what was intended, and any project of age has a schema that no longer matches
    // the sum of them. Everything goes through Laravel's own schema builder, so the
    // rows are identical on PostgreSQL, MySQL, MariaDB, SQLite and SQL Server.
    //
    // This is one of the parts of a scan that touches a database, and it fails quietly
    // — no connection, no permission, an unreadable table — so leaving it on costs
    // nothing where there is nothing to read. Turn it off for a purely static scan, or
    // name a connection when the models do not live on the default one:
    //
    //   'connection' => 'tenant',
    //
    'schema' => [
        'enabled' => env('LARAVEL_BRAIN_SCHEMA', true),
        'connection' => null,

        // Seconds to wait for the database to answer before giving up and reading no schema.
        //
        // A refused connection fails instantly, but a host that drops packets — a database
        // reached over a VPN that is not up — blocks for PDO's default of 30 seconds, on every
        // scan and on every poll in watch mode. This bounds that wait; the failure stays quiet,
        // it just stops being expensive. Set null to leave the driver's own default.
        'timeout' => env('LARAVEL_BRAIN_SCHEMA_TIMEOUT', 2),
    ],

    // -------------------------------------------------------------------------
    // Livewire Component Search Paths
    // -------------------------------------------------------------------------
    // Directories (relative to project root) that are searched when resolving
    // a Livewire component defined as a namespace::dot.notation string in routes.
    //
    // Example route:
    //   Route::livewire('create-password', 'pages::password.create')
    //
    // The namespace prefix ('pages') and dot path ('password.create') are each
    // converted to StudlyCase and looked up in every directory listed here.
    // For the example above, laravel-brain would search for:
    //   {dir}/Pages/Password/Create.php   (prefix + path)
    //   {dir}/Password/Create.php          (path only)
    //
    // Add any custom Livewire or page-component directories your project uses.
    //
    'livewire' => [
        'component_paths' => [
            'app/Http/Livewire',
            'app/Livewire',
            'app/View/Components',
        ],
    ],

    // -------------------------------------------------------------------------
    // Cache Operations
    // -------------------------------------------------------------------------
    // Detect calls to the `Cache` facade and the `cache()` helper while charting a
    // method, and show them on the node: the operation kind (read / write /
    // invalidate / lock), the key where it can be read from the source, and any
    // declared tags, store and TTL.
    //
    // On by default. Turning it off is off at the source — no statement is inspected
    // for a cache call at all — rather than a filter over results that were computed
    // anyway.
    //
    // What it costs. The work happens inside flow extraction, so measure it there
    // rather than against a whole scan. Over the benchmark suite's 1,185-file corpus
    // (5,571 methods, no cache calls in it) flow extraction goes from 11.5 ms to
    // 14.2 ms — the cost of looking and finding nothing. Over source saturated with
    // cache calls (1,000 methods, six calls each) it goes from 9.9 ms to 18.4 ms.
    // Against a full scan of that same 1,185-file corpus — 494 ms — neither delta is
    // measurable above the run-to-run noise.
    //
    // So turn it off for a reason other than speed:
    //   - the application does not use Laravel's cache, and the section would never
    //     have anything to say;
    //   - caching is wrapped in your own abstraction rather than the facade, so what
    //     Brain can see is a misleading fraction of what the application actually
    //     caches — a half-true panel is worse than no panel;
    //   - you are diffing two scans and want the graph to hold still across a change
    //     to this detection.
    //
    // Override via the LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED env variable.
    //
    'cache_operations' => [
        'enabled' => env('LARAVEL_BRAIN_CACHE_OPERATIONS_ENABLED', true),
    ],

    // -------------------------------------------------------------------------
    // MCP Server
    // -------------------------------------------------------------------------
    // Controls the "brain" MCP server, reachable via `php artisan mcp:start brain`
    // once the optional laravel/mcp package is installed (composer require --dev
    // laravel/mcp). Brain auto-detects the package; this only matters if you want
    // to disable the server without removing the dependency.
    //
    // Override via the LARAVEL_BRAIN_MCP_ENABLED env variable.
    //
    'mcp' => [
        'enabled' => env('LARAVEL_BRAIN_MCP_ENABLED', true),
    ],

];
