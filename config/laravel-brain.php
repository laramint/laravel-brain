<?php

declare(strict_types=1);

return [

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
