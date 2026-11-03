<?php

namespace Tests\Feature;

use App\Http\Controllers\HealthCheckController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * The health endpoint is public, so it says {"status":"ok"} or {"status":"unavailable"} and nothing
 * else, and it keeps answering when the things a normal page depends on (database-backed sessions
 * and cache) are exactly what has failed.
 */
class HealthCheckTest extends TestCase
{
    private function breakTheDatabase(): void
    {
        config(['database.connections.sqlite.database' => storage_path('framework/testing/missing-dir/missing.sqlite')]);
        DB::purge('sqlite');
    }

    private function assertUnavailable(TestResponse $response): void
    {
        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);

        $body = $response->getContent();
        foreach (['missing', 'SQLSTATE', 'Exception', 'sqlite', 'vendor', 'Stack trace', 'secret', base_path()] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_health_reports_ok_when_the_database_is_reachable(): void
    {
        $this->getJson(route('health'))->assertOk()->assertExactJson(['status' => 'ok']);

        $this->assertStringContainsString('no-store', $this->get(route('health'))->headers->get('Cache-Control'));
    }

    public function test_health_reveals_nothing_about_the_environment(): void
    {
        $body = $this->get(route('health'))->assertOk()->getContent();

        foreach ([app()->environment(), app()->version(), PHP_VERSION, 'sqlite', ':memory:', config('app.name'), 'Laravel'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->assertSame('{"status":"ok"}', $body);
    }

    public function test_health_starts_no_session_and_sets_no_cookie(): void
    {
        foreach ([$this->get(route('health')), $this->withCookie(config('session.cookie'), 'abc')->get(route('health'))] as $response) {
            $response->assertOk();
            $this->assertSame([], $response->headers->getCookies(), 'no session or CSRF cookie');
        }

        $this->breakTheDatabase();
        $this->assertSame([], $this->get(route('health'))->headers->getCookies());
    }

    public function test_health_reports_unavailable_without_the_reason_when_the_database_is_unreachable(): void
    {
        config(['app.debug' => true]);
        $this->breakTheDatabase();
        Log::spy();

        $this->assertUnavailable($this->get(route('health')));

        // The operator still learns what failed, from the log, without the connection details.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => array_keys($context) === ['exception'],
        );
    }

    public function test_health_still_answers_when_database_backed_sessions_and_cache_are_down(): void
    {
        // Production's settings: sessions and cache live in the database, and the database is down.
        // A page in the web group would fail while starting its session; the probe must not.
        config(['app.debug' => true, 'session.driver' => 'database', 'cache.default' => 'database']);
        $this->breakTheDatabase();

        $this->assertUnavailable($this->get(route('health')));
        $this->head(route('health'))->assertStatus(503);
    }

    public function test_health_answers_ok_with_database_backed_sessions_and_cache_when_the_database_is_up(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);

        $this->get(route('health'))->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_an_unexpected_failure_anywhere_in_the_request_is_the_same_503_even_with_debug_on(): void
    {
        config(['app.debug' => true]);
        $this->app->bind(HealthCheckController::class, fn () => new class extends HealthCheckController
        {
            public function __invoke(): JsonResponse
            {
                throw new RuntimeException('secret '.base_path('app/Secret.php').' SELECT * FROM users');
            }
        });

        $this->assertUnavailable($this->get(route('health')));
    }

    public function test_a_broken_limiter_store_never_breaks_the_probe(): void
    {
        config(['bizflow.security.health_limiter_store' => 'no-such-store']);

        $this->get(route('health'))->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_health_is_limited_with_its_own_store_and_the_same_two_answers(): void
    {
        // Break the default store: the limiter must not use it.
        config(['cache.default' => 'database']);
        $this->breakTheDatabase();

        for ($i = 0; $i < 60; $i++) {
            $this->assertSame(503, $this->get(route('health'))->getStatusCode());
        }

        $response = $this->get(route('health'));

        $this->assertUnavailable($response);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_only_the_documented_methods_and_the_liveness_route_behave_normally(): void
    {
        $this->post(route('health'))->assertStatus(405);
        $this->get('/up')->assertOk();
    }

    public function test_the_unused_local_disk_serving_routes_do_not_exist(): void
    {
        $this->get('/storage/anything.txt')->assertNotFound();
        $this->put('/storage/anything.txt', ['x' => 'y'])->assertNotFound();
        $this->assertFalse(config('filesystems.disks.local.serve'));
    }

    public function test_the_public_pages_no_longer_link_to_a_status_page(): void
    {
        $this->get('/')->assertOk()->assertDontSee('System status')->assertDontSee('Check system status');
    }
}
