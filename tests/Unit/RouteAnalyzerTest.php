<?php

use Acme\Pkg\AcmeVendorStub;
use App\Http\Controllers\DashController;
use App\Http\Controllers\MultiController;
use App\Http\Controllers\UserController;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Router;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Http\Controllers\BrainController;

it('extracts basic routes from api.php', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));

    expect($routes)
        ->toBeArray()
        ->each->toBeInstanceOf(RouteDefinition::class);
});

it('finds the POST /login route', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $login = findRoute($routes, fn ($r) => str_contains($r->uri, 'login'));

    expect($login)->toBeInstanceOf(RouteDefinition::class)
        ->method->toBe('POST')
        ->controller->toBe('App\Http\Controllers\AuthController')
        ->action->toBe('login');
});

it('extracts middleware from groups', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $ordersRoute = findRoute($routes, fn ($r) => $r->uri === '/orders' && $r->method === 'GET');

    expect($ordersRoute)->toBeInstanceOf(RouteDefinition::class)
        ->middlewares->toBeArray()->toContain('auth:sanctum');
});

it('applies prefix from nested group', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $adminRoute = findRoute($routes, fn ($r) => str_contains($r->uri, 'admin'));

    expect($adminRoute)->toBeInstanceOf(RouteDefinition::class)
        ->uri->toBe('/admin/orders/{id}')
        ->middlewares->toBeArray()->toContain('role:admin');
});

it('finds 13 routes total', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));

    expect($routes)
        ->toBeArray()
        ->toHaveCount(13);
});

it('captures middleware chained after the HTTP method call', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('laravel-project'));
    $brandsRoute = findRoute($routes, fn ($r) => $r->uri === '/brands' && $r->method === 'GET');

    expect($brandsRoute)->toBeInstanceOf(RouteDefinition::class)
        ->middlewares->toBeArray()->toContain('ability:view-maintenance-requests,monitor-maintenance,create-transfer');
});

it('expands Route::resource with distinct URIs and tab groups per action', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/blog.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::resource('blog', \App\Http\Controllers\BlogController::class);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        expect($routes)->toHaveCount(8);

        $updateRoutes = array_values(array_filter($routes, fn ($r) => $r->action === 'update'));
        expect($updateRoutes)->toHaveCount(2);
        $updateMethods = array_map(fn ($r) => $r->method, $updateRoutes);
        sort($updateMethods);
        expect($updateMethods)->toBe(['PATCH', 'PUT']);

        $index = findRoute($routes, fn ($r) => $r->action === 'index' && $r->method === 'GET');
        expect($index->uri)->toBe('/blog');
        expect($index->tabGroup)->toBe('GET /blog');

        $create = findRoute($routes, fn ($r) => $r->action === 'create' && $r->method === 'GET');
        expect($create->uri)->toBe('/blog/create');
        expect($create->tabGroup)->toBe('GET /blog/create');

        $show = findRoute($routes, fn ($r) => $r->action === 'show');
        expect($show->uri)->toBe('/blog/{blog}');

        $tabGroups = array_map(fn ($r) => $r->tabGroup, $routes);
        expect(count($tabGroups))->toBe(count(array_unique($tabGroups)));
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('expands Route::apiResource without create or edit routes', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/posts.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;

Route::apiResource('posts', \App\Http\Controllers\PostController::class);

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        expect($routes)->toHaveCount(6);
        expect(findRoute($routes, fn ($r) => $r->action === 'create'))->toBeNull();
        expect(findRoute($routes, fn ($r) => $r->action === 'edit'))->toBeNull();

        $show = findRoute($routes, fn ($r) => $r->action === 'show');
        expect($show->uri)->toBe('/posts/{post}');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('expands Route::match into one route per HTTP verb', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/gateway.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('gateway')->middleware(['throttle:api'])->group(function () {
    Route::match(['get', 'post'], '/token', [\App\Http\Controllers\AuthController::class, 'token'])
        ->middleware('log.requests');
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);
        expect($routes)->toHaveCount(2);

        $get = findRoute($routes, fn ($r) => $r->method === 'GET');
        $post = findRoute($routes, fn ($r) => $r->method === 'POST');

        expect($get)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/gateway/token')
            ->controller->toBe('App\Http\Controllers\AuthController')
            ->action->toBe('token');
        expect($get->middlewares)->toContain('throttle:api')->toContain('log.requests');

        expect($post)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/gateway/token')
            ->controller->toBe('App\Http\Controllers\AuthController')
            ->action->toBe('token');
        expect($post->middlewares)->toContain('throttle:api')->toContain('log.requests');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('auto-discover mode pulls routes from the live router', function () {
    $router = makeAutoDiscoverRouter();

    $router->get('/users/{id}', [UserController::class, 'show']);
    $router->post('/login', AutoDiscoverInvokableStub::class); // invokable
    $router->get('/ping', function () {
        return 'pong';
    });
    $router->middleware(['auth:sanctum'])->group(function ($router) {
        $router->get('/dashboard', [DashController::class, 'index'])->name('dashboard');
    });
    $router->match(['GET', 'POST', 'HEAD'], '/multi', [MultiController::class, 'handle']);

    $routes = (new RouteAnalyzer([], autoDiscover: true))->analyze('/unused');

    expect($routes)->toBeArray()->each->toBeInstanceOf(RouteDefinition::class);

    $show = findRoute($routes, fn ($r) => $r->uri === '/users/{id}' && $r->method === 'GET');
    expect($show->controller)->toBe('App\Http\Controllers\UserController')
        ->and($show->action)->toBe('show')
        ->and($show->tabGroup)->toBe('GET /users/{id}');

    // Each route lands in its own tab subgraph (matches AST-mode behaviour)
    $tabGroups = array_map(fn ($r) => $r->tabGroup, $routes);
    expect(count($tabGroups))->toBe(count(array_unique($tabGroups)));

    $login = findRoute($routes, fn ($r) => $r->uri === '/login' && $r->method === 'POST');
    expect($login->controller)->toBe(AutoDiscoverInvokableStub::class)
        ->and($login->action)->toBe('__invoke');

    $closure = findRoute($routes, fn ($r) => $r->uri === '/ping');
    expect($closure->controller)->toBe('')
        ->and($closure->action)->toBe('closure')
        ->and($closure->closureNode)->not->toBeNull()
        ->and($closure->closureUseMap)->toBeArray();

    $dash = findRoute($routes, fn ($r) => $r->uri === '/dashboard');
    expect($dash->middlewares)->toContain('auth:sanctum')
        ->and($dash->name)->toBe('dashboard');

    // HEAD is filtered; GET+POST remain (one RouteDefinition per non-HEAD verb)
    $multi = array_values(array_filter($routes, fn ($r) => $r->uri === '/multi'));
    $methods = array_map(fn ($r) => $r->method, $multi);
    sort($methods);
    expect($methods)->toBe(['GET', 'POST']);

    Container::setInstance(null);
});

it('auto-discover mode excludes routes whose controller lives under vendor/', function () {
    $router = makeAutoDiscoverRouter();

    // App controller (this test file is NOT under vendor/)
    $router->get('/app-route', [UserController::class, 'show']);

    // Fake "vendor" controller: a stub class whose ReflectionClass file lives
    // inside a vendor/-shaped temp directory we put on the autoloader manually.
    $tmpVendor = sys_get_temp_dir().'/lb-vendor-'.uniqid('', true);
    mkdir($tmpVendor.'/vendor/acme/pkg/src', 0777, true);
    $stubFile = $tmpVendor.'/vendor/acme/pkg/src/AcmeVendorStub.php';
    file_put_contents($stubFile, <<<'PHP'
<?php
namespace Acme\Pkg;
class AcmeVendorStub
{
    public function __invoke() {}
}
PHP);
    require $stubFile;

    $router->get('/vendor-route', AcmeVendorStub::class);

    try {
        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: true))
            ->analyze($tmpVendor);

        $uris = array_map(fn ($r) => $r->uri, $routes);
        expect($uris)->toContain('/app-route')
            ->and($uris)->not->toContain('/vendor-route');

        // Disabling the filter brings the vendor route back.
        $all = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))
            ->analyze($tmpVendor);
        expect(array_map(fn ($r) => $r->uri, $all))->toContain('/vendor-route');
    } finally {
        routeAnalyzerTestDeleteTree($tmpVendor);
        Container::setInstance(null);
    }
});

it('auto-discover mode always drops the package\'s own _laravel-brain routes', function () {
    $router = makeAutoDiscoverRouter();

    $router->get('/app-route', [UserController::class, 'show']);
    $router->get('/_laravel-brain/api/source', [BrainController::class, 'source']);

    try {
        // Even with excludeVendor disabled, brain's own routes must be skipped.
        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))
            ->analyze('/unused');

        $uris = array_map(fn ($r) => $r->uri, $routes);
        expect($uris)->toContain('/app-route')
            ->and($uris)->not->toContain('/_laravel-brain/api/source');
    } finally {
        Container::setInstance(null);
    }
});

it('resolves bare action strings inside Route::controller() groups', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/memes.php',
        <<<'PHP'
<?php

use App\Http\Controllers\MemeController;
use Illuminate\Support\Facades\Route;

Route::controller(MemeController::class)->group(function () {
    Route::get('/divorce-child-custody-memes', 'index')->name('index.memes');
    Route::get('/divorce-child-custody-memes/{id}', 'showMemeById')->whereNumber('id')->name('show.meme-by-id');
    Route::get('/divorce-child-custody-memes/{slug}', 'show')->where('slug', '(.*)?')->name('show.meme-by-slug');
});

Route::get('/divorce-child-custody-memes/1/', function () {
    return redirect()->route('index.memes');
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $index = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes' && $r->method === 'GET');
        expect($index)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('index');

        $byId = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/{id}');
        expect($byId)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('showMemeById');

        $bySlug = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/{slug}');
        expect($bySlug)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('App\Http\Controllers\MemeController')
            ->action->toBe('show');

        $closure = findRoute($routes, fn ($r) => $r->uri === '/divorce-child-custody-memes/1/');
        expect($closure)->toBeInstanceOf(RouteDefinition::class)
            ->controller->toBe('Closure');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

it('applies prefix and middleware from a RouteServiceProvider chain-form group', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $delete = findRoute($routes, fn ($r) => $r->uri === '/api/customer/address/{id}' && $r->method === 'DELETE');

    expect($delete)->toBeInstanceOf(RouteDefinition::class)
        ->controller->toBe('App\Http\Controllers\AddressController')
        ->action->toBe('destroy')
        ->middlewares->toBeArray()->toContain('api');
});

it('applies prefix and middleware from a RouteServiceProvider static-form group', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $delete = findRoute($routes, fn ($r) => $r->uri === '/api/restaurant/category/{id}' && $r->method === 'DELETE');

    expect($delete)->toBeInstanceOf(RouteDefinition::class)
        ->controller->toBe('App\Http\Controllers\CategoryController')
        ->action->toBe('destroy')
        ->middlewares->toBeArray()->toContain('api')->toContain('auth:sanctum');
});

it("collapses trailing slash when Route::get('/') is inside a prefix group", function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $perm = findRoute($routes, fn ($r) => $r->controller === 'App\Http\Controllers\PermissionController');

    expect($perm)->toBeInstanceOf(RouteDefinition::class)
        ->uri->toBe('/api/admin/permission')
        ->method->toBe('GET')
        ->middlewares->toBeArray()->toContain('api');
});

it('composes a provider-applied prefix with an inner Route::prefix group', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $posts = findRoute($routes, fn ($r) => $r->uri === '/api/v1/posts' && $r->method === 'GET');

    expect($posts)->toBeInstanceOf(RouteDefinition::class)
        ->controller->toBe('App\Http\Controllers\PostController')
        ->action->toBe('index')
        ->middlewares->toBeArray()->toContain('api');
});

it('follows Route::group(base_path(...)) inside another routes file', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $general = findRoute($routes, fn ($r) => $r->uri === '/settings/general' && $r->method === 'GET');

    expect($general)->toBeInstanceOf(RouteDefinition::class)
        ->controller->toBe('App\Http\Controllers\SettingsController')
        ->action->toBe('general')
        ->middlewares->toBeArray()->toContain('web');
});

it('does not double-emit a Route::group(base_path(...)) target file', function () {
    $routes = (new RouteAnalyzer)->analyze(fixture('grouped-routes-project'));
    $settings = array_filter($routes, fn ($r) => $r->action === 'general');

    expect($settings)->toHaveCount(1);
});

it('follows require into a route file carrying parent group context, once', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/inc', 0777, true);
    file_put_contents(
        $tmp.'/routes/api.php',
        <<<'PHP'
<?php

use App\Http\Middleware\EnsureProjectUnlocked;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api', EnsureProjectUnlocked::class])
    ->prefix('api')
    ->group(function () {
        require __DIR__ . '/inc/notes.php';
    });

PHP
    );
    file_put_contents(
        $tmp.'/routes/inc/notes.php',
        <<<'PHP'
<?php

use App\Http\Controllers\ClientController;
use App\Http\Middleware\EnforcesModelRelations;
use Illuminate\Support\Facades\Route;

Route::middleware([EnforcesModelRelations::class])->group(function () {
    Route::get('clients/{client}/notes', [ClientController::class, 'indexNotes']);
    Route::post('clients/{client}/notes', [ClientController::class, 'storeNote']);
});

PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        $index = findRoute($routes, fn ($r) => $r->method === 'GET' && str_contains($r->uri, 'clients'));
        expect($index)->toBeInstanceOf(RouteDefinition::class)
            ->uri->toBe('/api/clients/{client}/notes')
            ->controller->toBe('App\Http\Controllers\ClientController')
            ->action->toBe('indexNotes');
        expect($index->middlewares)
            ->toContain('auth:sanctum')
            ->toContain('throttle:api')
            ->toContain('App\Http\Middleware\EnsureProjectUnlocked')
            ->toContain('App\Http\Middleware\EnforcesModelRelations');

        // notes.php must not also be parsed standalone (which would yield a
        // duplicate without the parent prefix/middleware).
        $noteRoutes = array_values(array_filter($routes, fn ($r) => str_contains($r->uri, 'clients')));
        expect($noteRoutes)->toHaveCount(2);
        foreach ($noteRoutes as $r) {
            expect($r->uri)->toStartWith('/api/');
        }
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});

// Helper Functions

class AutoDiscoverInvokableStub
{
    public function __invoke() {}
}

function makeAutoDiscoverRouter(): Router
{
    $container = new Container;
    Container::setInstance($container);

    $events = new Dispatcher($container);
    $router = new Router($events, $container);

    $container->instance('router', $router);
    $container->instance(Router::class, $router);

    return $router;
}

function findRoute(array $routes, callable $predicate): mixed
{
    foreach ($routes as $r) {
        if ($predicate($r)) {
            return $r;
        }
    }

    return null;
}

function routeAnalyzerTestDeleteTree(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $fileinfo) {
        $path = $fileinfo->getPathname();
        if ($fileinfo->isDir()) {
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

it('resolves a controller named in a namespaced routes file without an import', function () {
    // A routes file may declare a namespace, and a controller in that namespace then needs no
    // `use`. Resolving through the import map alone left the controller short, which keys every
    // route and call-chain edge that touches it on a name nothing else in the graph uses.
    $root = sys_get_temp_dir().'/brain-routens-'.uniqid();
    mkdir($root.'/routes', 0o777, true);
    mkdir($root.'/app/Http/Controllers/Dev', 0o777, true);

    file_put_contents($root.'/routes/web.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers\Dev;

        use Illuminate\Support\Facades\Route;

        Route::get('dev', DevOverviewController::class);
        PHP);
    file_put_contents($root.'/app/Http/Controllers/Dev/DevOverviewController.php', <<<'PHP'
        <?php

        namespace App\Http\Controllers\Dev;

        class DevOverviewController
        {
            public function __invoke() {}
        }
        PHP);

    $routes = (new RouteAnalyzer)->analyze($root);
    $dev = findRoute($routes, fn ($r) => str_contains($r->uri, 'dev'));

    expect($dev)->not->toBeNull()
        ->and($dev->controller)->toBe('App\Http\Controllers\Dev\DevOverviewController');

    exec('rm -rf '.escapeshellarg($root));
});

/**
 * A throwaway project with a routes file and, optionally, a provider that loads it.
 *
 * @param  array<string, string>  $files  path relative to the project root => contents
 */
function routeProject(array $files): string
{
    $root = sys_get_temp_dir().'/brain-routes-'.uniqid();
    foreach ($files as $path => $contents) {
        $full = $root.'/'.$path;
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0o777, true);
        }
        file_put_contents($full, $contents);
    }

    return $root;
}

it('reads a controller from an array action rather than taking the route name as one', function () {
    // ['as' => ..., 'uses' => ...] has two entries, so it matched the positional
    // [Controller::class, 'method'] branch and the route's own name became its controller.
    // The single-entry ['uses' => ...] matched nothing and the route was dropped outright.
    $root = routeProject([
        'routes/web.php' => '<?php

use Illuminate\Support\Facades\Route;

Route::get("b", ["as" => "b.name", "uses" => "BetaController@show"]);
Route::get("c", ["uses" => "GammaController@edit"]);
Route::get("e", ["uses" => "EpsilonController@go", "middleware" => "auth"]);
',
    ]);

    $routes = (new RouteAnalyzer)->analyze($root);
    $by = fn (string $uri) => findRoute($routes, fn ($r) => $r->uri === $uri);

    expect($by('/b'))->not->toBeNull()
        ->and($by('/b')->controller)->toBe('BetaController')
        ->and($by('/b')->action)->toBe('show')
        ->and($by('/c'))->not->toBeNull()
        ->and($by('/c')->controller)->toBe('GammaController')
        ->and($by('/c')->action)->toBe('edit')
        ->and($by('/e')->middlewares)->toContain('auth');

    exec('rm -rf '.escapeshellarg($root));
});

it('applies the namespace and middleware of a provider group that requires its routes in a closure', function () {
    // The registration scanner looked for a file path argument and skipped closures, so the
    // shape laravel/laravel shipped for years recorded nothing and the routes file was parsed
    // with no context — losing the group's namespace and middleware together. The namespace
    // itself is a property default, which the literal-string reader returned '' for.
    $root = routeProject([
        'routes/web.php' => '<?php

use Illuminate\Support\Facades\Route;

Route::get("a", "AlphaController@index");
',
        'app/Providers/RouteServiceProvider.php' => '<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;

class RouteServiceProvider
{
    protected $namespace = "App\Http\Controllers";

    protected function mapWebRoutes(): void
    {
        Route::group([
            "middleware" => "web",
            "namespace" => $this->namespace,
        ], static function (): void {
            require base_path("routes/web.php");
        });
    }
}
',
    ]);

    $route = findRoute((new RouteAnalyzer)->analyze($root), fn ($r) => $r->uri === '/a');

    expect($route)->not->toBeNull()
        ->and($route->controller)->toBe('App\Http\Controllers\AlphaController')
        ->and($route->middlewares)->toContain('web');

    exec('rm -rf '.escapeshellarg($root));
});

it('does not prepend a group namespace to a controller that already carries it', function () {
    // Controller::class is a fully-qualified string at runtime, so prefixing it again yields
    // App\Http\Controllers\App\Http\Controllers\Thing — a node nothing can join. The router
    // guards this the same way, by leaving a name that already starts with the group namespace
    // alone.
    $root = routeProject([
        'routes/web.php' => '<?php

use App\Http\Controllers\ThingController;
use Illuminate\Support\Facades\Route;

Route::group(["namespace" => "App\Http\Controllers"], static function (): void {
    Route::get("one", [ThingController::class, "index"]);
    Route::resource("two", ThingController::class);
});
',
        'app/Http/Controllers/ThingController.php' => '<?php

namespace App\Http\Controllers;

class ThingController {}
',
    ]);

    foreach ((new RouteAnalyzer)->analyze($root) as $route) {
        expect($route->controller)->toBe('App\Http\Controllers\ThingController');
    }

    exec('rm -rf '.escapeshellarg($root));
});

it('still qualifies a controller whose name merely begins with the namespace segment', function () {
    // The "already carries it" guard has to compare on a separator: `Admin` is a string prefix
    // of `AdminController` while being no namespace of it, and skipping there would leave the
    // controller unqualified.
    $root = routeProject([
        'routes/web.php' => '<?php

use Illuminate\Support\Facades\Route;

Route::group(["namespace" => "Admin"], static function (): void {
    Route::get("profile", "AdminController@profile");
});
',
    ]);

    $route = findRoute((new RouteAnalyzer)->analyze($root), fn ($r) => $r->uri === '/profile');

    expect($route)->not->toBeNull()
        ->and($route->controller)->toBe('Admin\AdminController');

    exec('rm -rf '.escapeshellarg($root));
});

it('keys a controller written with a leading backslash without one', function () {
    // A leading \ says the name is absolute, not that it is part of the name. Keeping it makes
    // a second controller node that can never join the one every other reference produces.
    $root = routeProject([
        'routes/web.php' => '<?php

use Illuminate\Support\Facades\Route;

Route::group(["namespace" => "App\Http\Controllers"], static function (): void {
    Route::get("s1", "\\\\App\\\\Http\\\\Controllers\\\\ZetaController@go");
    Route::get("s2", ["uses" => "\\\\App\\\\Http\\\\Controllers\\\\ZetaController@go"]);
});
',
    ]);

    foreach ((new RouteAnalyzer)->analyze($root) as $route) {
        expect($route->controller)->toBe('App\Http\Controllers\ZetaController')
            ->and($route->action)->toBe('go');
    }

    exec('rm -rf '.escapeshellarg($root));
});
