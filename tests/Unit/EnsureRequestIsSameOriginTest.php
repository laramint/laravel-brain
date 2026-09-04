<?php

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaraMint\LaravelBrain\Http\Middleware\EnsureRequestIsSameOrigin;

/** Runs the real middleware against a real Request, without booting an application. */
function passesSameOrigin(string $uri, array $headers = []): bool
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $request = Request::create($uri, 'POST', server: $server);
    $calledNext = false;

    (new EnsureRequestIsSameOrigin)->handle($request, function ($req) use (&$calledNext) {
        $calledNext = true;

        return new JsonResponse([]);
    });

    return $calledNext;
}

it('passes a same-origin request carrying a matching Origin header', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'http://localhost:8000',
    ]))->toBeTrue();
});

it('passes a same-origin request when only Referer is present', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Referer' => 'http://localhost:8000/_laravel-brain/',
    ]))->toBeTrue();
});

it('prefers Origin over Referer when both are present', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'http://localhost:8000',
        'Referer' => 'https://evil.example/attack.html',
    ]))->toBeTrue();
});

it('blocks a cross-site Origin — the forged <form> POST this exists to stop', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'https://evil.example',
    ]))->toBeFalse();
});

it('blocks a request with neither Origin nor Referer', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan'))->toBeFalse();
});

it('blocks a matching host on a different scheme or port', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'http://localhost:9000',
    ]))->toBeFalse();

    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'https://localhost:8000',
    ]))->toBeFalse();
});

it('blocks a garbage Origin header that fails to parse', function () {
    expect(passesSameOrigin('http://localhost:8000/_laravel-brain/api/scan', [
        'Origin' => 'not-a-url',
    ]))->toBeFalse();
});
