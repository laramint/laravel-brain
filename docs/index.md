---
layout: home

hero:
  name: Laravel Brain
  text: Visualize your request lifecycle
  tagline: Understand how routes, controllers, services, models, jobs, events, commands, and channels connect — in seconds.
  image:
    src: /logo.png
    alt: Laravel Brain
  actions:
    - theme: brand
      text: Get Started
      link: /installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/LaraMint/laravel-brain

features:
  - icon: 🧭
    title: Full lifecycle tracing
    details: Follows every route from HTTP verb → controller → service → repository → model → events/jobs.
  - icon: 🤖
    title: AI context export
    details: A deterministic, token-budgeted Markdown/JSON snapshot of any node's call chain, source, and DB ops.
  - icon: 🔌
    title: MCP server
    details: Query the last-scanned graph directly from Claude Code, Cursor, or any Model Context Protocol client.
  - icon: 🧩
    title: Filament PHP support
    details: Discovers panels, resources, pages, widgets, and relation managers automatically.
  - icon: ⚡
    title: Route stress testing
    details: Run concurrent HTTP load against any route node and watch live timing percentiles.
  - icon: 👀
    title: Watch mode
    details: Auto-rescans on file changes, tracing only what changed and merging it into the existing graph.
---

## What is Laravel Brain?

Laravel Brain is a zero-config developer tool that analyzes your Laravel codebase and renders an interactive node graph of your application's architecture. It traces every route through its controller, services, repositories, models, jobs, events, Artisan commands, scheduled tasks, broadcast channels, and Filament panels — giving you a bird's-eye view of the entire application without reading a single line of code.

The scan writes JSON graph files to `storage/app/laravel-brain/`. The viewer is served at `/_laravel-brain` entirely through your existing Laravel routes — no separate server process needed.

![Laravel Brain interactive graph viewer](/example.png)

::: tip
Laravel Brain is a dev-only tool, installed with `--dev`. Its routes, commands, and MCP server only ever register when `app()->environment('local')` — it never ships to production.
:::

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
- **AI rules generation** — Generate ready-to-use context files for AI coding assistants (Claude Code, Cursor, Windsurf, GitHub Copilot, JetBrains Junie, Aider, AGENTS.md, OpenAI Codex) directly from the UI (**Export → Generate AI Rules**) or via `brain:generate-rules`. Each file is populated with your project's real architecture, routes, packages, and code-health data

## Requirements

- PHP 8.0+
- Laravel 9, 10, 11, 12, or 13
- Composer

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

Laravel Brain is open-sourced software released under the **MIT license**.
