<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A modest per-IP limit on /health, kept off everything /health must not depend on.
 *
 * The probe answers "is the database up?", so its limiter cannot live in the database (the default
 * cache store in production) or it would fail exactly when the answer matters. It uses its own store
 * (a local file store by default: HEALTH_LIMITER_STORE), and if that store itself fails the request is
 * answered anyway: a broken counter must never turn a healthy application into a failed probe.
 *
 * A request over the limit gets the endpoint's one failure answer, 503 {"status":"unavailable"}
 * (with Retry-After), so the endpoint still has only the two documented bodies.
 */
class LimitHealthChecks
{
    private const MAX_PER_MINUTE = 60;

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $limiter = new RateLimiter(Cache::store((string) config('bizflow.security.health_limiter_store', 'file')));
            $key = 'health:'.sha1((string) $request->ip());

            if ($limiter->tooManyAttempts($key, self::MAX_PER_MINUTE)) {
                return response()
                    ->json(['status' => 'unavailable'], 503)
                    ->header('Cache-Control', 'no-store')
                    ->header('Retry-After', (string) max(1, $limiter->availableIn($key)));
            }

            $limiter->hit($key, 60);
        } catch (Throwable) {
            // Fall through: answer the probe.
        }

        return $next($request);
    }
}
