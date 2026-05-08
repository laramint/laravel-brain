<?php

use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;

$fixtureProject = __DIR__.'/../fixtures/laravel-project';

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

it('extracts basic routes from api.php', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    expect($routes)->not->toBeEmpty();
});

it('finds the POST /login route', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $login = findRoute($routes, fn ($r) => str_contains($r->uri, 'login'));

    expect($login)->not->toBeNull();
    expect($login->method)->toBe('POST');
    expect($login->controller)->toContain('AuthController');
    expect($login->action)->toBe('login');
});

it('extracts middleware from groups', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $ordersRoute = findRoute($routes, fn ($r) => $r->uri === '/orders' && $r->method === 'GET');

    expect($ordersRoute)->not->toBeNull();
    expect($ordersRoute->middlewares)->toContain('auth:sanctum');
});

it('applies prefix from nested group', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $adminRoute = findRoute($routes, fn ($r) => str_contains($r->uri, 'admin'));

    expect($adminRoute)->not->toBeNull();
    expect($adminRoute->uri)->toContain('/admin/');
    expect($adminRoute->middlewares)->toContain('role:admin');
});

it('finds 18 routes total', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    expect(count($routes))->toBe(18);
});

it('captures middleware chained after the HTTP method call', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);
    $brandsRoute = findRoute($routes, fn ($r) => $r->uri === '/brands' && $r->method === 'GET');

    expect($brandsRoute)->not->toBeNull();
    expect($brandsRoute->middlewares)->toContain('ability:view-maintenance-requests,monitor-maintenance,create-transfer');
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

it('captures uriPrefix from nested Route::prefix() groups', function () use ($fixtureProject) {
    $routes = (new RouteAnalyzer)->analyze($fixtureProject);

    $health = findRoute($routes, fn ($r) => $r->uri === '/health');
    expect($health)->not->toBeNull()->and($health->uriPrefix)->toBe('/');

    $status = findRoute($routes, fn ($r) => $r->uri === '/api/status');
    expect($status)->not->toBeNull()->and($status->uriPrefix)->toBe('/api');

    $users = findRoute($routes, fn ($r) => $r->uri === '/api/v1/users');
    expect($users)->not->toBeNull()->and($users->uriPrefix)->toBe('/api/v1/users');

    $users = findRoute($routes, fn ($r) => $r->uri === '/api/v1/users/{id}');
    expect($users)->not->toBeNull()->and($users->uriPrefix)->toBe('/api/v1/users');

    $settings = findRoute($routes, fn ($r) => $r->uri === '/api/v1/admin/settings');
    expect($settings)->not->toBeNull()->and($settings->uriPrefix)->toBe('/api/v1/admin');
});

it('parses Route::livewire() as a GET route with component as controller', function () {
    $tmp = sys_get_temp_dir().'/lb-route-analyzer-'.uniqid('', true);
    mkdir($tmp.'/routes/web', 0777, true);
    file_put_contents(
        $tmp.'/routes/web/livewire.php',
        <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Livewire\Dashboard;

Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
Route::livewire('/profile', 'App\Http\Livewire\Profile');
PHP
    );

    try {
        $routes = (new RouteAnalyzer(['routes/*/*.php']))->analyze($tmp);

        expect($routes)->toHaveCount(2);

        $dashboard = findRoute($routes, fn ($r) => $r->uri === '/dashboard');
        expect($dashboard)->not->toBeNull();
        expect($dashboard->method)->toBe('GET');
        expect($dashboard->controller)->toContain('Dashboard');
        expect($dashboard->action)->toBe('render');

        $profile = findRoute($routes, fn ($r) => $r->uri === '/profile');
        expect($profile)->not->toBeNull();
        expect($profile->method)->toBe('GET');
        expect($profile->controller)->toBe('App\Http\Livewire\Profile');
    } finally {
        routeAnalyzerTestDeleteTree($tmp);
    }
});
