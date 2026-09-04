<?php

declare(strict_types=1);

/**
 * Deterministic synthetic Laravel application generator for the benchmark suite.
 *
 * The tree is shaped to stress the real scan hotspots rather than to be pretty:
 *   - many files (redundant cross-analyzer re-parsing shows up here)
 *   - a shared downstream service/repository/model layer reachable from many
 *     entry points (shared-subtree re-walks in the method tracer show up here)
 *   - deep chains: entry point -> service -> service -> repository -> model
 *   - entry classes (jobs, listeners, commands, middleware, Livewire
 *     components, observers) with several methods each, including private
 *     helpers and call-free getters
 *   - two application facades (unscaled), so the facade scan is not idle: one
 *     ordinary `extends Facade`, and one child that never names Facade, reaching
 *     the base through an app-level parent. A controller calls each.
 *   - a framework facade import on almost every class (`Log`, `Cache`, `DB`, …),
 *     which is the shape the prefilter has to skip
 *
 * Output is byte-identical for a given scale — there is no randomness — so both
 * arms of a comparison can scan the same generated tree.
 *
 * Usage: php generate-corpus.php <outDir> [scale]
 *   scale 1.0 is ~400 PHP files under app/; 3.0 is ~1,200.
 */
$outDir = $argv[1] ?? (__DIR__.'/fixtures/app');
$scale = (float) ($argv[2] ?? 1.0);

$n = static fn (int $base): int => max(1, (int) round($base * $scale));

$counts = [
    'controllers' => $n(40),
    'services' => $n(60),
    'repositories' => $n(20),
    'models' => $n(30),
    'jobs' => $n(60),
    'listeners' => $n(22),
    'commands' => $n(34),
    'middleware' => $n(34),
    'livewire' => $n(80),
    'observers' => $n(3),
    'events' => $n(12),
];

// ─── filesystem helpers ──────────────────────────────────────────────────────
function rmrf(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

function put(string $path, string $contents): void
{
    $dir = dirname($path);
    if (! is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, $contents);
}

/**
 * Most files in a Laravel application import a framework facade. The facade
 * prefilter has to skip `Facades\` so those imports are not treated as
 * definitions. Without this line the generated tree cannot catch a regression
 * of that skip: almost nothing under app/ mentioned Facade at all.
 */
function frameworkFacadeImport(int $i): string
{
    $names = ['Log', 'Cache', 'DB', 'Auth', 'Session', 'Storage'];

    return 'use Illuminate\\Support\\Facades\\'.$names[$i % count($names)].';';
}

rmrf($outDir);
mkdir($outDir, 0755, true);

$pad = static fn (int $i): string => str_pad((string) $i, 3, '0', STR_PAD_LEFT);

// ─── pick a shared downstream target deterministically ───────────────────────
$svc = static fn (int $i): string => 'Service'.str_pad((string) ($i % max(1, $GLOBALS['counts']['services'])), 3, '0', STR_PAD_LEFT);
$repo = static fn (int $i): string => 'Repository'.str_pad((string) ($i % max(1, $GLOBALS['counts']['repositories'])), 3, '0', STR_PAD_LEFT);
$model = static fn (int $i): string => 'Model'.str_pad((string) ($i % max(1, $GLOBALS['counts']['models'])), 3, '0', STR_PAD_LEFT);
$job = static fn (int $i): string => 'Job'.str_pad((string) ($i % max(1, $GLOBALS['counts']['jobs'])), 3, '0', STR_PAD_LEFT);
$event = static fn (int $i): string => 'Event'.str_pad((string) ($i % max(1, $GLOBALS['counts']['events'])), 3, '0', STR_PAD_LEFT);

// A few call-heavy body lines that reference shared downstream nodes.
$bodyCalls = function (int $seed, int $lines = 4) use ($model, $job, $event): string {
    $out = [];
    for ($k = 0; $k < $lines; $k++) {
        $t = ($seed + $k * 7);
        switch ($t % 5) {
            case 0: $out[] = "        \$this->svcA->handle{$k}(\$id);";
                break;
            case 1: $out[] = "        \$this->repo->fetch{$k}(\$id);";
                break;
            case 2: $out[] = "        \\App\\Models\\{$model($t)}::query()->where('id', \$id)->first();";
                break;
            case 3: $out[] = "        \\App\\Jobs\\{$job($t)}::dispatch(\$id);";
                break;
            case 4: $out[] = "        event(new \\App\\Events\\{$event($t)}(\$id));";
                break;
        }
    }

    return implode("\n", $out);
};

// ─── models ──────────────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['models']; $i++) {
    $name = 'Model'.$pad($i);
    $rel1 = 'Model'.$pad(($i + 1) % $counts['models']);
    $rel2 = 'Model'.$pad(($i + 2) % $counts['models']);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Models/$name.php", <<<PHP
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
$fw

class $name extends Model
{
    protected \$fillable = ['id', 'name', 'status', 'amount'];

    public function related()
    {
        return \$this->hasMany($rel1::class);
    }

    public function owner()
    {
        return \$this->belongsTo($rel2::class);
    }

    public function scopeActive(\$query)
    {
        return \$query->where('status', 'active');
    }
}
PHP);
}

// ─── events ──────────────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['events']; $i++) {
    $name = 'Event'.$pad($i);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Events/$name.php", <<<PHP
<?php

namespace App\Events;

$fw

class $name
{
    public function __construct(public int \$id) {}
}
PHP);
}

// ─── repositories (call models) ──────────────────────────────────────────────
for ($i = 0; $i < $counts['repositories']; $i++) {
    $name = 'Repository'.$pad($i);
    $m = $model($i);
    $body = [];
    for ($k = 0; $k < 5; $k++) {
        $body[] = <<<M
    public function fetch$k(int \$id)
    {
        return \\App\\Models\\{$model($i + $k)}::query()->where('id', \$id)->first();
    }
M;
    }
    $body = implode("\n\n", $body);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Repositories/$name.php", <<<PHP
<?php

namespace App\Repositories;

$fw

class $name
{
$body
}
PHP);
}

// ─── services (call other services, repos, models, jobs, events) ─────────────
for ($i = 0; $i < $counts['services']; $i++) {
    $name = 'Service'.$pad($i);
    $svcDep = $svc($i + 1);
    $repoDep = $repo($i);
    $methods = [];
    for ($k = 0; $k < 5; $k++) {
        $calls = $bodyCalls($i * 3 + $k, 4);
        $methods[] = <<<M
    public function handle$k(int \$id): void
    {
$calls
    }
M;
    }
    // one call-free getter (pure overhead to trace)
    $methods[] = <<<M
    public function label(): string
    {
        return '$name';
    }
M;
    $methods = implode("\n\n", $methods);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Services/$name.php", <<<PHP
<?php

namespace App\Services;

use App\Services\\$svcDep;
use App\Repositories\\$repoDep;
$fw

class $name
{
    public function __construct(
        private \\App\\Services\\$svcDep \$svcA,
        private \\App\\Repositories\\$repoDep \$repo,
    ) {}

$methods
}
PHP);
}

// ─── controllers (constructor inject services, methods call them) ────────────
$routeLines = ['web' => [], 'api' => []];
for ($i = 0; $i < $counts['controllers']; $i++) {
    $name = 'Controller'.$pad($i);
    $svcDep = $svc($i);
    $svcDep2 = $svc($i + 2);
    $methods = [];
    $actions = ['index', 'show', 'store', 'update'];
    foreach ($actions as $ai => $action) {
        $calls = $bodyCalls($i * 5 + $ai, 4);
        if ($i === 0 && $action === 'index') {
            $calls .= "\n        \\App\\Facades\\Catalog::handle0(\$id);";
        } elseif ($i === 1 && $action === 'index') {
            $calls .= "\n        \\App\\Support\\Reporting::handle0(\$id);";
        }
        $methods[] = <<<M
    public function $action(int \$id)
    {
$calls
        return response()->json(['ok' => true]);
    }
M;
        $verb = ['index' => 'get', 'show' => 'get', 'store' => 'post', 'update' => 'put'][$action];
        $bucket = $i % 2 === 0 ? 'web' : 'api';
        $routeLines[$bucket][] = "Route::$verb('/$name/$action/{id}', [\\App\\Http\\Controllers\\$name::class, '$action']);";
    }
    $methods = implode("\n\n", $methods);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Http/Controllers/$name.php", <<<PHP
<?php

namespace App\Http\Controllers;

use App\Services\\$svcDep;
use App\Services\\$svcDep2;
$fw

class $name
{
    public function __construct(
        private \\App\\Services\\$svcDep \$svcA,
        private \\App\\Services\\$svcDep2 \$svcB,
    ) {}

$methods
}
PHP);
}

// ─── jobs (handle + private helpers, call shared services) ───────────────────
for ($i = 0; $i < $counts['jobs']; $i++) {
    $name = 'Job'.$pad($i);
    $svcDep = $svc($i);
    $calls = $bodyCalls($i * 11, 4);
    $calls2 = $bodyCalls($i * 11 + 3, 3);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Jobs/$name.php", <<<PHP
<?php

namespace App\Jobs;

use App\Services\\$svcDep;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
$fw

class $name implements ShouldQueue
{
    use Queueable;

    public function __construct(private int \$id) {}

    public function handle(\\App\\Services\\$svcDep \$svcA): void
    {
$calls
        \$this->finalize();
    }

    private function finalize(): void
    {
$calls2
    }

    public function tags(): array
    {
        return ['$name'];
    }
}
PHP);
}

// ─── listeners ───────────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['listeners']; $i++) {
    $name = 'Listener'.$pad($i);
    $svcDep = $svc($i + 4);
    $calls = $bodyCalls($i * 13, 4);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Listeners/$name.php", <<<PHP
<?php

namespace App\Listeners;

use App\Services\\$svcDep;
$fw

class $name
{
    public function __construct(private \\App\\Services\\$svcDep \$svcA) {}

    public function handle(\$event): void
    {
$calls
    }
}
PHP);
}

// ─── console commands ─────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['commands']; $i++) {
    $name = 'Command'.$pad($i);
    $svcDep = $svc($i + 6);
    $calls = $bodyCalls($i * 17, 5);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Console/Commands/$name.php", <<<PHP
<?php

namespace App\Console\Commands;

use App\Services\\$svcDep;
use Illuminate\Console\Command;
$fw

class $name extends Command
{
    protected \$signature = 'app:cmd$i {id}';

    protected \$description = 'Command $i';

    public function handle(\\App\\Services\\$svcDep \$svcA): int
    {
        \$id = (int) \$this->argument('id');
$calls
        return self::SUCCESS;
    }
}
PHP);
}

// ─── middleware ────────────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['middleware']; $i++) {
    $name = 'Middleware'.$pad($i);
    $svcDep = $svc($i + 8);
    $calls = $bodyCalls($i * 19, 3);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Http/Middleware/$name.php", <<<PHP
<?php

namespace App\Http\Middleware;

use App\Services\\$svcDep;
use Closure;
$fw

class $name
{
    public function __construct(private \\App\\Services\\$svcDep \$svcA) {}

    public function handle(\$request, Closure \$next)
    {
        \$id = (int) \$request->input('id');
$calls
        return \$next(\$request);
    }
}
PHP);
}

// ─── livewire components ────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['livewire']; $i++) {
    $name = 'Component'.$pad($i);
    $svcDep = $svc($i + 10);
    $methods = [];
    for ($k = 0; $k < 5; $k++) {
        $calls = $bodyCalls($i * 23 + $k, 3);
        $methods[] = <<<M
    public function action$k(int \$id): void
    {
$calls
    }
M;
    }
    $methods[] = <<<M
    public function getTitleProperty(): string
    {
        return '$name';
    }
M;
    $methods = implode("\n\n", $methods);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Livewire/$name.php", <<<PHP
<?php

namespace App\Livewire;

use App\Services\\$svcDep;
use Livewire\Component;
$fw

class $name extends Component
{
    public function __construct(private \\App\\Services\\$svcDep \$svcA) {}

$methods

    public function render()
    {
        return view('livewire.$name');
    }
}
PHP);
}

// ─── observers ─────────────────────────────────────────────────────────────────
for ($i = 0; $i < $counts['observers']; $i++) {
    $name = 'Observer'.$pad($i);
    $svcDep = $svc($i + 12);
    $calls = $bodyCalls($i * 29, 3);
    $fw = frameworkFacadeImport($i);
    put("$outDir/app/Observers/$name.php", <<<PHP
<?php

namespace App\Observers;

use App\Services\\$svcDep;
$fw

class $name
{
    public function __construct(private \\App\\Services\\$svcDep \$svcA) {}

    public function created(\$model): void
    {
$calls
    }

    public function updated(\$model): void
    {
$calls
    }
}
PHP);
}

// ─── application facades (unscaled) ───────────────────────────────────────────
// Two concrete facades, three files. The count is fixed so a larger scale does
// not invent more of them; one of each shape is enough for the facade scan to
// run its second pass and for a missed resolution to drop a graph edge.
put("$outDir/app/Facades/Catalog.php", <<<'PHP'
<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class Catalog extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \App\Services\Service000::class;
    }
}
PHP);

put("$outDir/app/Support/Facades/Base.php", <<<'PHP'
<?php

namespace App\Support\Facades;

use Illuminate\Support\Facades\Facade;

abstract class Base extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \App\Services\Service001::class;
    }
}
PHP);

put("$outDir/app/Support/Reporting.php", <<<'PHP'
<?php

namespace App\Support;

use App\Support\Facades\Base;

class Reporting extends Base
{
}
PHP);

// ─── routes ────────────────────────────────────────────────────────────────────
$webRoutes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".implode("\n", $routeLines['web'])."\n";
$apiRoutes = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n".implode("\n", $routeLines['api'])."\n";
put("$outDir/routes/web.php", $webRoutes);
put("$outDir/routes/api.php", $apiRoutes);

// ─── composer.json (psr-4) ──────────────────────────────────────────────────────
put("$outDir/composer.json", <<<'JSON'
{
    "name": "bench/app",
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
JSON);

// ─── vendor/composer/autoload_psr4.php ──────────────────────────────────────────
// Real apps always ship this (Composer-generated); GraphBuilder::buildFullPsr4Map reads
// it to resolve FQCN -> file. Without it, GraphBuilder falls back to a full recursive
// directory walk per class — a fixture-only artifact that must not skew the benchmark.
// buildFullPsr4Map pre-sets $baseDir = projectRoot before requiring this file.
put("$outDir/vendor/composer/autoload_psr4.php", <<<'PHP'
<?php

// @generated fixture — mirrors Composer's autoload_psr4.php shape
return array(
    'App\\' => array($baseDir . '/app'),
);
PHP);

// ─── report ──────────────────────────────────────────────────────────────────
$total = array_sum($counts);
$files = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($outDir.'/app', FilesystemIterator::SKIP_DOTS)));
fwrite(STDERR, "Generated corpus at $outDir (scale $scale)\n");
foreach ($counts as $k => $v) {
    fwrite(STDERR, sprintf("  %-14s %d\n", $k, $v));
}
fwrite(STDERR, "  facades        2 (plus 1 abstract base, unscaled)\n");
fwrite(STDERR, "  TOTAL classes ≈ $total, php files under app/ = $files\n");
