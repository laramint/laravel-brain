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
    $prefix = config('laravel-brain.route_prefix', '_laravel-brain');
    $router->get("/{$prefix}/api/source", [BrainController::class, 'source']);

    try {
        // Even with excludeVendor disabled, brain's own routes must be skipped.
        $routes = (new RouteAnalyzer([], autoDiscover: true, excludeVendor: false))
            ->analyze('/unused');

        $uris = array_map(fn ($r) => $r->uri, $routes);
        expect($uris)->toContain('/app-route')
            ->and($uris)->not->toContain("/{$prefix}/api/source");
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
