<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a state-changing brain request whose Origin (or, lacking that, Referer) is not this
 * app's own. The brain routes carry no session and issue no CSRF token — see
 * BrainController::stressTest() — so Laravel's usual VerifyCsrfToken has nothing to check
 * here; this stands in for it. Every modern browser attaches Origin to a cross-site POST,
 * whether fired by fetch or by a plain <form>, so a missing or mismatched one is what a
 * forged request from another page looks like — a same-origin request from Brain's own
 * viewer always carries a matching one.
 */
class EnsureRequestIsSameOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $sourceOrigin = $source ? self::originOf($source) : null;

        if ($sourceOrigin === null || $sourceOrigin !== self::originOf($request->getSchemeAndHttpHost())) {
            return new JsonResponse(['error' => 'Cross-origin request blocked.'], 403);
        }

        return $next($request);
    }

    private static function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! $scheme || ! $host) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        return strtolower($scheme).'://'.strtolower($host).($port ? ':'.$port : '');
    }
}
