# Usage

Scan your project, open the interactive viewer, and pull AI-ready context straight from the scanned graph.

## Scan your project

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

### Memory limit

The scanner defaults to **1024M**. On larger codebases you can raise the limit with `--memory-limit`:

```bash
php artisan brain:scan --memory-limit=1G
php artisan brain:scan --memory-limit=2G
php artisan brain:scan --memory-limit=2048M

# Unlimited (use with caution)
php artisan brain:scan --memory-limit=-1
```

Accepted formats: `<number>M` (megabytes), `<number>G` (gigabytes), or `-1` (unlimited). The minimum allowed value is `1024M`.

## Open the viewer

Navigate to `/_laravel-brain` in your browser while your Laravel app is running (e.g. via `php artisan serve`).

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

## Export AI context for a node

Click the **🤖** button in the node sidebar to copy a structured Markdown context block to your clipboard, ready to paste into Claude, ChatGPT, or any LLM.

The context is **deterministic**: same scan + same node = identical output every time. It uses BFS up to depth 3 from the selected node and enforces a token budget (default 6,000 tokens), truncating only source snippets once structural metadata is fully included.

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

## Generate AI assistant rules files

Populate context files for your AI coding tools directly from the scan data.

**From the UI:** Toolbar → **Export** → **Generate AI Rules** → select targets → **Generate**.

**From the terminal:**

```bash
# Generate all targets at once
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
| `codex` | `CODEX.md` | OpenAI Codex CLI & IDE extension |

Each generated file contains your project's tech stack, architecture counts, top routes, complexity hotspots, detected code smells, and full package lists. Re-run after every scan to keep the files current.

## MCP Server

Everything above exports a static snapshot. The MCP server makes the last scan queryable — Claude Code, Cursor, and any other [Model Context Protocol](https://modelcontextprotocol.io) client can ask the graph questions directly instead of re-exporting each time.

It's an optional, auto-detected add-on — install [Laravel's own MCP package](https://github.com/laravel/mcp) and Brain wires itself up automatically:

```bash
composer require --dev laravel/mcp
```

::: warning
Requires Laravel 11+ (`laravel/mcp` needs `symfony/process ^7.4.5|^8.0.5`, which conflicts with the `symfony/process ^6.x` that Laravel 9/10 themselves require — PHP version isn't the limiter here). Nothing else in Brain needs this — it's the only feature gated on it, and skipping this package leaves everything else untouched.
:::

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

## Watch mode

Re-scan automatically whenever a PHP file changes:

```bash
php artisan brain:scan --watch
php artisan brain:scan --watch --interval=5   # poll every 5 seconds (default: 3)
```

Each rescan traces only the controllers declared in the changed files and merges the result into the previous graph, rather than rebuilding it from scratch — the output is identical to a full scan, just faster. A full rescan still runs automatically whenever a file is added or deleted, or when anything outside `app/` changes (routes, config).

## Route stress testing

From a selected **route** node, run concurrent HTTP load against that endpoint (via [`laramint/laravel-stress`](https://github.com/LaraMint/laravel-stress), installed automatically as a dependency): configure request count, concurrency, headers, body, and timeout; see timing percentiles (min/avg/p50/p95/p99/max), throughput, and status distribution in the sidebar. While a run is active, the graph highlights the route and animates packets along the request path.

Target URLs are validated server-side against an allowlist of development hosts: `localhost`, `127.0.0.1`, `*.test`, `*.local`, `*.ddev.site`, single-label Docker service names (e.g. `nginx`, `app`), private IPv4 ranges (`10.x`, `172.16–31.x`, `192.168.x`), and the host in `APP_URL`.

::: tip Docker?
The stress test subprocess runs **inside** the container — `localhost:8080` is the host-side mapped port and won't be reachable there. Change the **Base URL** field to the internal service address, e.g. `http://nginx` or `http://localhost:80`. Single-label hostnames (Docker service names with no dots) and private IPv4 ranges are automatically allowed by the host validator, so pointing the Base URL at your internal network address will work without any extra config.
:::
