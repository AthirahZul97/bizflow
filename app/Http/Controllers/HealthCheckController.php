<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Readiness for a load balancer or uptime monitor: can the application reach its database?
 *
 * The answer is public, so it is only ever {"status":"ok"} (200) or {"status":"unavailable"} (503).
 * It never says which environment, framework or PHP version, which database or driver, or why a
 * connection failed (the operator has "php artisan bizflow:preflight" and the logs), and APP_DEBUG
 * changes nothing here.
 *
 * It is registered outside the web middleware group (see bootstrap/app.php): no session is started,
 * no cookie is read or set and no CSRF or cache-backed throttle runs, so a database-backed session
 * or cache store that is down cannot turn the probe into an error page. Anything unexpected that
 * still escapes is rendered as the same 503 by the exception handler. The framework's own /up route
 * stays as the plain liveness check.
 */
class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            $status = 'ok';
        } catch (Throwable $e) {
            $this->noteFailure($e);

            $status = 'unavailable';
        }

        return response()
            ->json(['status' => $status], $status === 'ok' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The exception class only: a driver message can carry the host, user or database name. If even
     * logging fails, the probe still answers.
     */
    private function noteFailure(Throwable $e): void
    {
        try {
            Log::warning('Health check: the database is unavailable.', ['exception' => $e::class]);
        } catch (Throwable) {
            //
        }
    }
}
