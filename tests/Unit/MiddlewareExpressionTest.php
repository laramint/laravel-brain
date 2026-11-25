<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;

// =============================================================================
// A middleware a route file builds instead of writing out. `Authenticate::using('api')`
// is the framework's own way to write `auth:api`, and reading nothing for it classified
// every route behind it as public.
// =============================================================================

/**
 * @return string[] the middleware of the one route the given expression guards
 */
function builtMiddlewareOf(string $expression, string $template = 'Route::middleware(%s)->group(function () { Route::post("records", "RecordController@store"); });'): array
{
    $root = routeProject([
        'routes/web.php' => "<?php\n\nuse App\\Http\\Middleware\\EnsureLogin;\nuse Illuminate\\Routing\\Middleware\\ThrottleRequests;\nuse Illuminate\\Routing\\Middleware\\ValidateSignature;\nuse Illuminate\\Support\\Facades\\Route;\n\n".sprintf($template, $expression)."\n",
    ]);

    try {
        return findRoute((new RouteAnalyzer)->analyze($root), fn ($route) => $route->uri === '/records')->middlewares;
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
}

it('reads the middleware a framework factory returns', function (string $expression, string $middleware) {
    expect(builtMiddlewareOf($expression))->toBe([$middleware]);
})->with([
    ['EnsureLogin::using("api")', 'App\Http\Middleware\EnsureLogin:api'],
    ['EnsureLogin::using("api", "web")', 'App\Http\Middleware\EnsureLogin:api,web'],
    ['ThrottleRequests::with(10, 1)', 'Illuminate\Routing\Middleware\ThrottleRequests:10,1'],
    ['ValidateSignature::relative()', 'Illuminate\Routing\Middleware\ValidateSignature:relative'],
    ['ValidateSignature::absolute()', 'Illuminate\Routing\Middleware\ValidateSignature'],
    ['\App\Http\Middleware\EnsureLogin::using("api")', 'App\Http\Middleware\EnsureLogin:api'],
    // A parameter that is no literal leaves the name.
    ['EnsureLogin::using(config("auth.guard"))', 'App\Http\Middleware\EnsureLogin'],
    ['EnsureLogin::using(...$guards)', 'App\Http\Middleware\EnsureLogin'],
    ['ThrottleRequests::with(decayMinutes: 5)', 'Illuminate\Routing\Middleware\ThrottleRequests'],
]);

it('reads a class name with parameters joined on', function (string $expression, string $middleware) {
    expect(builtMiddlewareOf($expression))->toBe([$middleware]);
})->with([
    ['EnsureLogin::class.":api"', 'App\Http\Middleware\EnsureLogin:api'],
    ['EnsureLogin::class.":"."api"', 'App\Http\Middleware\EnsureLogin:api'],
    ['EnsureLogin::class.":".$guard', 'App\Http\Middleware\EnsureLogin'],
    ['"auth:".$guard', 'auth'],
]);

it('reads nothing for an expression that may be anything', function (string $expression) {
    expect(builtMiddlewareOf($expression))->toBe([]);
})->with([
    'another static method' => ['EnsureLogin::defaults()'],
    'a first-class callable' => ['EnsureLogin::using(...)'],
    'a class in a variable' => ['$class::using("api")'],
    'a name with no end' => ['"auth".$suffix'],
    'a name that starts unread' => ['$prefix.":api"'],
]);

it('reads a built middleware wherever a route takes one', function (string $template) {
    expect(builtMiddlewareOf('EnsureLogin::using("api")', $template))->toContain('App\Http\Middleware\EnsureLogin:api');
})->with([
    'in a list' => ['Route::middleware(["web", %s])->group(function () { Route::post("records", "RecordController@store"); });'],
    'on the route' => ['Route::post("records", "RecordController@store")->middleware(%s);'],
    'on the route, in a list' => ['Route::post("records", "RecordController@store")->middleware([%s]);'],
    'in group options' => ['Route::group(["middleware" => [%s]], function () { Route::post("records", "RecordController@store"); });'],
]);

it('reads a built middleware a provider puts on a routes file', function () {
    $root = routeProject([
        'routes/api.php' => '<?php

use Illuminate\Support\Facades\Route;

Route::post("records", "RecordController@store");
',
        'app/Providers/RouteServiceProvider.php' => '<?php

namespace App\Providers;

use App\Http\Middleware\EnsureLogin;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider
{
    public function boot(): void
    {
        Route::middleware(["api", EnsureLogin::using("api")])->group(base_path("routes/api.php"));
    }
}
',
    ]);

    $route = findRoute((new RouteAnalyzer)->analyze($root), fn ($route) => $route->uri === '/records');

    expect($route->middlewares)->toBe(['api', 'App\Http\Middleware\EnsureLogin:api']);

    exec('rm -rf '.escapeshellarg($root));
});

it('classifies a route behind a built auth middleware as authed', function () {
    $root = routeProject([
        'routes/web.php' => '<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::middleware(["api", Authenticate::using("api")])->group(function () {
    Route::post("records", "RecordController@store");
});
',
    ]);

    $analysis = (new SecurityAnalyzer)->analyze((new RouteAnalyzer)->analyze($root), new MiddlewareRegistry([], [], []), [], $root);

    expect($analysis['route::POST::/records']['exposure'])->toBe('authed')
        ->and(issueTypes($analysis, 'route::POST::/records'))->not->toContain('PUBLIC_WRITE');

    exec('rm -rf '.escapeshellarg($root));
});
