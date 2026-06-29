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

    // -------------------------------------------------------------------------
    // Route Prefix
    // -------------------------------------------------------------------------
    // The URL prefix for all Laravel Brain routes.
    // Override via the LARAVEL_BRAIN_ROUTE_PREFIX env variable.
    //
    'route_prefix' => env('LARAVEL_BRAIN_ROUTE_PREFIX', '_laravel-brain'),

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
    'listeners' => [
        'paths' => [
            'app/Listeners',
        ],
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

];
