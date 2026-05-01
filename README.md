<p align="center">
  <img src="art/banner.png" alt="Laravel Brain" />
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
</p>

---

## What is LaraMint\LaravelBrain?

LaraMint\LaravelBrain is a developer tool that analyzes your Laravel codebase and renders an interactive node graph of your application's architecture. It traces every route through its controller, services, repositories, models, jobs, events, Artisan commands, scheduled tasks, and broadcast channels — giving you a bird's-eye view of the entire application without reading a single line of code.

## Features

- **Full lifecycle tracing** — Follows every route from HTTP verb → controller → service → repository → model → events/jobs
- **Artisan command discovery** — Maps class-based commands, closure commands from `routes/console.php`, and Kernel-registered commands
- **Scheduler tracing** — Visualizes scheduled tasks (`command`, `job`, `call`) with their frequency
- **Broadcast channel mapping** — Discovers class-based and closure channels from `routes/channels.php`
- **DB query tracing** — Surfaces Eloquent and raw queries per method
- **Fat-class detection** — Flags controllers and services with more than 300 lines or 10 methods
- **Interactive graph** — Dark/light theme, accent-colored nodes, and interactive edges
- **Per-route tabs** — Each route gets its own isolated subgraph tab
- **Middleware mapping** — Shows which middleware guards each route
- **Model relationships** — Displays `hasMany`, `belongsTo`, and other Eloquent relations
- **Method flowcharts** — See internal flow as a step-by-step diagram with a large modal popup view
- **Source viewer** — Read the actual source file inline or in a focused popup
- **Export** — Export any graph as PNG or Mermaid diagram
- **Multiple layouts** — Hierarchical (dagre), force-directed (cose-bilkent), breadth-first, circle, grid
- **Watch mode** — Auto-rescans on PHP file changes

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

### Watch mode

Re-scan automatically whenever a PHP file changes:

```bash
php artisan brain:scan --watch
php artisan brain:scan --watch --interval=5   # poll every 5 seconds (default: 3)
```

### Open the viewer

Navigate to `/_laravel-brain` in your browser while your Laravel app is running (e.g. via `php artisan serve`).

The viewer is served entirely through your existing Laravel routes — no separate server process needed.

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
        └─ GraphBuilder       → assembles nodes + edges, flags fat classes
                │
                └─ Writes JSON → storage/app/laravel-brain/

GET /_laravel-brain
        │
        └─ BrainController → serves the React SPA + graph JSON via Laravel routes
```

### Route discovery

LaravelBrain recursively scans your entire `routes/` directory — not just `web.php` and `api.php`. Any PHP file under `routes/**` is analyzed, including versioned files like `routes/v1/users.php` or module-specific files like `routes/modules/admin.php`.

For **modular, DDD, or package-style projects** where routes are registered from service providers outside the `routes/` directory, publish the config and add glob patterns:

```bash
php artisan vendor:publish --tag=laravel-brain-config
```

```php
// config/laravel-brain.php
return [
    'route_paths' => [
        'app/Modules/*/routes/*.php',
        // 'app/Domain/*/routes/*.php',
    ],
];
```

Patterns are relative to the project root and merged with the standard `routes/` scan. Overlapping paths are deduplicated automatically.

### Call chain tracing

From each controller action, the tracer follows:
- Direct method calls to injected services/repositories
- Static calls (`MyService::method()`)
- Job dispatches (`dispatch(new SendEmail(...))`)
- Event dispatches (`event(new OrderPlaced(...))`)

This produces the full edge list used to build the graph.

## Graph Node Types

| Node | Accent Color | Represents |
|------|-------------|------------|
| Route | Green | HTTP endpoint (`GET /users`) |
| Middleware | Orange | Middleware applied to route |
| Controller | Blue | Controller class |
| Action | Light Blue | Controller method |
| Service | Purple | Service or helper class |
| Repository | Purple (subtype) | Repository class |
| Model | Red | Eloquent model |
| Event | Yellow | Laravel event |
| Job | Slate | Queued job |
| Command | Teal | Artisan command |
| Schedule | Teal | Scheduled task entry |
| Channel | Indigo | Broadcast channel |

## Viewer Shortcuts

| Action | How |
|--------|-----|
| Zoom | Scroll wheel |
| Pan | Click + drag on canvas |
| Inspect node | Click any node |
| View source | Click a node → Source tab in sidebar |
| Source Popup | Click ⤢ in source section to open focused view |
| View flowchart | Click a class node → Flow tab |
| Flowchart Popup | Click ⤢ in flow section to open large view |
| Filter by type | Filter panel on the left |
| Fit all nodes | Toolbar → Fit button |
| Export PNG | Toolbar → PNG button |
| Export Mermaid | Toolbar → Mermaid button |
| Toggle theme | Toolbar → ☀️ / 🌙 button |

## Routes Registered

The package registers the following routes in your application (all under the `/_laravel-brain` prefix):

```
GET /_laravel-brain              → Interactive graph viewer (SPA)
GET /_laravel-brain/api/source   → Returns PHP source file content
GET /_laravel-brain/assets/*     → Serves frontend static assets
GET /_laravel-brain/.graph-*.json → Serves graph data written by the scan
```

## Output Files

After running `brain:scan`, the following files are written to `storage/app/laravel-brain/`:

```
.graph-manifest.json   — Tab manifest (list of all route tabs)
.graph-all.json        — Full combined graph (all routes)
.graph-{tab-id}.json   — Per-route subgraph (one per route)
```

These files are regenerated on every scan and are safe to gitignore:

```gitignore
storage/app/laravel-brain/
```

## Security

The `/_laravel-brain` routes are only registered in the `local` environment by default. Since it's a `require-dev` dependency, it will not be present in production builds (`composer install --no-dev`).

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
