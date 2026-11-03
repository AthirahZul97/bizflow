<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Middleware\EnsureCurrentBusiness;
use App\Http\Middleware\EnsureEmailFlowsEnabled;
use App\Http\Middleware\EnsureEmailVerifiedWhenRequired;
use App\Http\Middleware\EnsureRegistrationOpen;
use App\Http\Middleware\EnsureSubscriptionWritable;
use App\Http\Middleware\LimitHealthChecks;
use App\Http\Middleware\RequirePasswordFingerprint;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Readiness for a load balancer. Deliberately NOT in the web group: no session, cookies or
            // CSRF, and a limiter that does not depend on the database or the default cache store, so
            // a failing database-backed session or cache cannot break the probe it is asking about.
            Route::get('/health', HealthCheckController::class)->middleware('health.limit')->name('health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'business' => EnsureCurrentBusiness::class,
            'subscription.writable' => EnsureSubscriptionWritable::class,
            'registration.open' => EnsureRegistrationOpen::class,
            'email.flows' => EnsureEmailFlowsEnabled::class,
            'verified.required' => EnsureEmailVerifiedWhenRequired::class,
            'health.limit' => LimitHealthChecks::class,
        ]);

        // Trust nobody by default. Which proxies to believe is config/trustedproxy.php (read per
        // request, so config:cache applies); only the forwarded headers a proxy sets are honoured,
        // not the AWS ELB or prefix variants this application does not use.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        // Absolute URLs (password reset links, redirects) are built from the request host, so only
        // the host of APP_URL is accepted. Laravel skips this in the local environment and in tests.
        $middleware->trustHosts(
            at: fn (): array => array_filter([
                ($host = parse_url((string) config('app.url'), PHP_URL_HOST)) ? '^'.preg_quote($host, '/').'$' : null,
            ]),
            subdomains: false,
        );

        // Signing out every other session when a password changes (a reset, or any later change):
        // each session remembers a keyed hash of the password it logged in with, and the next request
        // from a session whose hash no longer matches is logged out. It needs no particular session
        // driver, and it validates "remember me" cookies the same way. Fails closed: a signed-in
        // session with no hash at all (one that predates this) is logged out, not given one.
        $middleware->web(append: [RequirePasswordFingerprint::class]);

        // Global and OUTERMOST (prepended), so it wraps every other middleware: an early refusal such
        // as an oversized POST (413), a maintenance response (503) or a rejected request still gets the
        // headers, because those are rendered as responses that pass back through this layer. What it
        // cannot cover is anything produced before Laravel runs (see docs/deployment.md).
        $middleware->prepend(SecurityHeaders::class);

        // Decide read-only (and, where used, email verification) before route-model binding, so a
        // forged record ID and a missing one are refused identically.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureSubscriptionWritable::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureEmailVerifiedWhenRequired::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // /health has exactly two answers. A server-side failure anywhere in the request (not a 4xx
        // such as a wrong method) is rendered as the second, whatever APP_DEBUG says, and never as an
        // exception page that could show a path, a query or a connection detail.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('health') && ! ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500)) {
                return response()->json(['status' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
            }
        });
    })->create();
