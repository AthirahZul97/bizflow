<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\ExpenseReceipts\HandlesReceipts;
use Tests\TestCase;

/**
 * Baseline browser protections, trusted proxies and the receipt download's own headers.
 */
class SecurityHeadersTest extends TestCase
{
    use HandlesReceipts;
    use RefreshDatabase;

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

    public function test_pages_carry_the_baseline_headers(): void
    {
        $response = $this->get('/')->assertOk();

        foreach ($this->baseline() as $name => $value) {
            $response->assertHeader($name, $value);
        }

        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        foreach ($this->baseline() as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_error_pages_and_unmatched_urls_carry_them_too(): void
    {
        Route::middleware('web')->get('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('boom'));

        $responses = [
            404 => $this->get('/no-such-page'),
            419 => $this->get('/_test/expired'),
            500 => $this->get('/_test/boom'),
        ];

        foreach ($responses as $status => $response) {
            $this->assertSame($status, $response->getStatusCode());

            foreach ($this->baseline() as $name => $value) {
                $this->assertSame($value, $response->headers->get($name), "{$name} on a {$response->getStatusCode()} response");
            }
        }
    }

    public function test_the_csp_is_report_only_and_never_enforced(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertNull($response->headers->get('Content-Security-Policy'), 'nothing is enforced until it has been checked in real browsers');

        $policy = $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertNotNull($policy);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "style-src 'self' 'unsafe-inline'"] as $directive) {
            $this->assertStringContainsString($directive, $policy);
        }
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString('frame-ancestors', $policy, 'not valid in a report-only policy; X-Frame-Options does the job');
    }

    public function test_scripts_may_only_come_from_this_origin_and_pages_are_identical_between_requests(): void
    {
        $first = $this->get('/login');
        $second = $this->get('/login');

        $this->assertStringContainsString("script-src 'self';", $first->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringNotContainsString('nonce', $first->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertSame($first->getContent(), $second->getContent(), 'no per-request value is embedded in the markup');
    }

    public function test_hsts_is_sent_only_on_a_verified_https_request(): void
    {
        config(['bizflow.security.hsts_max_age' => 300]);

        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=300');
    }

    public function test_hsts_is_off_by_default(): void
    {
        $this->assertSame(0, config('bizflow.security.hsts_max_age'));

        $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_forged_forwarded_proto_does_not_earn_hsts_from_an_untrusted_peer(): void
    {
        config(['bizflow.security.hsts_max_age' => 300]);

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get('http://localhost/')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_a_trusted_proxy_forwarding_https_does_earn_it(): void
    {
        config(['bizflow.security.hsts_max_age' => 300, 'trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get('http://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=300');
    }

    public function test_the_receipt_download_keeps_its_own_stricter_headers(): void
    {
        $this->setUpReceipts();
        $owner = User::factory()->create();
        $receipt = $this->uploadFor($owner, $this->upload('r.png', $this->png()));

        $response = $this->actingAs($owner)->get(route('expense-receipts.file', $receipt))->assertOk();

        $this->assertSame("default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        // ...and the baseline still applies to what the file response did not set.
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame($this->png(), $response->streamedContent());
    }

    public function test_only_the_demo_asks_search_engines_to_stay_away(): void
    {
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');

        config(['bizflow.demo' => true]);

        $this->get('/')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/no-such-page')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('Disallow: /', file_get_contents(public_path('robots.txt')));
    }

    public function test_no_page_view_needs_an_inline_script_or_event_handler(): void
    {
        $offenders = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            // Email and PDF views are not browser pages, so no CSP applies to them.
            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/emails/') || str_ends_with($path, 'invoices/pdf.blade.php')) {
                continue;
            }

            if (preg_match('/<script\b(?![^>]*\bsrc=)|\son(?:submit|click|change|load|input|focus|blur|error|keyup|keydown)\s*=|javascript:/i', file_get_contents($path))) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'inline script and event-handler attributes would be blocked by the CSP');
    }

    public function test_the_probe_route_helper_sees_the_request_the_application_sees(): void
    {
        Route::middleware('web')->get('/_test/who', fn (Request $request) => response()->json([
            'ip' => $request->ip(), 'secure' => $request->isSecure(),
        ]));

        $this->get('/_test/who')->assertExactJson(['ip' => '127.0.0.1', 'secure' => false]);
    }
}
