<?php

namespace Tests\Feature\Security;

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Responses produced before the application's own middleware runs (an oversized POST, a maintenance
 * page, a rejected method) must carry the same headers, because SecurityHeaders is the outermost layer.
 *
 * The boundary: only a response Laravel renders is covered. A page the web server answers on its
 * own, or the pre-rendered maintenance page served before the framework boots, is not (see
 * docs/deployment.md).
 */
class EarlyResponseHeadersTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function baseline(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];
    }

    private function assertBaseline(TestResponse $response, string $what): void
    {
        foreach ($this->baseline() as $name => $value) {
            $this->assertSame([$value], $response->headers->all(strtolower($name)), "{$name} on {$what}");
        }

        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'), "CSP on {$what}");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function underMaintenance(array $data = []): void
    {
        $this->app->instance(MaintenanceMode::class, new class($data) implements MaintenanceMode
        {
            public function __construct(private array $data) {}

            public function activate(array $payload): void {}

            public function deactivate(): void {}

            public function active(): bool
            {
                return true;
            }

            public function data(): array
            {
                return $this->data;
            }
        });
    }

    /**
     * @param  array<string, string>  $server  extra server variables (call() does not apply withHeaders())
     */
    private function oversizedPost(string $uri = '/login', string $scheme = 'http', array $server = []): TestResponse
    {
        return $this->call('POST', "{$scheme}://localhost{$uri}", [], [], [], $server + [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 8),
        ]);
    }

    public function test_an_oversized_post_is_refused_with_the_headers(): void
    {
        $response = $this->oversizedPost();

        $response->assertStatus(413);
        $this->assertBaseline($response, 'a 413');
    }

    public function test_a_maintenance_response_has_the_headers(): void
    {
        $this->underMaintenance(['status' => 503, 'retry' => 60]);

        $response = $this->get('/');

        $response->assertStatus(503);
        $this->assertBaseline($response, 'the maintenance response');
    }

    public function test_a_rendered_maintenance_template_has_the_headers(): void
    {
        $this->underMaintenance(['template' => '<h1>Back soon</h1>', 'status' => 503]);

        $response = $this->get('/dashboard');

        $response->assertStatus(503)->assertSee('Back soon', false);
        $this->assertBaseline($response, 'the rendered maintenance page');
    }

    public function test_a_maintenance_redirect_has_the_headers(): void
    {
        $this->underMaintenance(['redirect' => '/elsewhere']);

        $response = $this->get('/');

        $response->assertRedirect('/elsewhere');
        $this->assertBaseline($response, 'the maintenance redirect');
    }

    public function test_other_framework_generated_errors_have_the_headers(): void
    {
        $this->assertBaseline($this->get('/no-such-page'), 'a 404');
        $this->assertBaseline($this->post('/'), 'a 405');
        $this->assertBaseline($this->get('/reset-password'), 'a 404 for a route that needs a token');

        Route::middleware(['web', 'throttle:1,1,early'])->get('/_test/limited', fn () => 'ok');
        $this->get('/_test/limited')->assertOk();
        $this->assertBaseline($this->get('/_test/limited'), 'a 429');
    }

    public function test_the_early_responses_keep_the_proxy_aware_hsts_rule(): void
    {
        config(['bizflow.security.hsts_max_age' => 300]);

        $this->assertNull($this->oversizedPost()->headers->get('Strict-Transport-Security'));
        $this->assertSame('max-age=300', $this->oversizedPost('/login', 'https')->headers->get('Strict-Transport-Security'));

        // A forged forwarded protocol from an unlisted peer earns nothing, even on an early response...
        $forwarded = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443'];
        $this->assertNull($this->oversizedPost('/login', 'http', $forwarded)->headers->get('Strict-Transport-Security'));

        // ...and a listed proxy does, because the decision is made after the proxy rules have run.
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->assertSame('max-age=300', $this->oversizedPost('/login', 'http', $forwarded)->headers->get('Strict-Transport-Security'));

        config(['trustedproxy.proxies' => []]);
        $this->underMaintenance();
        $this->assertSame('max-age=300', $this->get('https://localhost/')->headers->get('Strict-Transport-Security'));
        $this->assertNull($this->get('http://localhost/')->headers->get('Strict-Transport-Security'));
    }

    public function test_early_responses_keep_the_demo_noindex_header(): void
    {
        config(['bizflow.demo' => true]);

        $this->assertSame('noindex, nofollow', $this->oversizedPost()->headers->get('X-Robots-Tag'));

        $this->underMaintenance();
        $this->assertSame('noindex, nofollow', $this->get('/')->headers->get('X-Robots-Tag'));
    }

    public function test_a_stricter_header_a_response_already_has_is_never_replaced_or_duplicated(): void
    {
        Route::middleware('web')->get('/_test/strict', fn () => response('x', 200, [
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Content-Security-Policy-Report-Only' => "default-src 'none'",
            'Permissions-Policy' => 'camera=()',
        ]));

        $response = $this->get('/_test/strict');

        $this->assertSame(['SAMEORIGIN'], $response->headers->all('x-frame-options'));
        $this->assertSame(['no-referrer'], $response->headers->all('referrer-policy'));
        $this->assertSame(["default-src 'none'; sandbox"], $response->headers->all('content-security-policy'));
        $this->assertSame(["default-src 'none'"], $response->headers->all('content-security-policy-report-only'));
        $this->assertSame(['camera=()'], $response->headers->all('permissions-policy'));
        $this->assertSame(['nosniff'], $response->headers->all('x-content-type-options'), 'what the response lacked is added once');
    }
}
