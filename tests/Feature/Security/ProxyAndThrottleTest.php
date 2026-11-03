<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Who the application believes the client is (trusted proxies) and the login limits built on it.
 */
class ProxyAndThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function probe(): void
    {
        Route::middleware('web')->get('/_test/who', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadTrustedProxyConfig(array $env): array
    {
        foreach ($env as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            return require base_path('config/trustedproxy.php');
        } finally {
            foreach (array_keys($env) as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }
    }

    // ---- trusted proxies ------------------------------------------------------------------------

    public function test_nobody_is_trusted_by_default_so_forwarded_headers_are_ignored(): void
    {
        $this->probe();

        $this->assertSame([], config('trustedproxy.proxies'));

        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example',
        ])->get('/_test/who')->assertExactJson(['ip' => '127.0.0.1', 'secure' => false, 'host' => 'localhost']);
    }

    public function test_a_listed_proxy_is_believed(): void
    {
        $this->probe();
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->get('/_test/who')
            ->assertJson(['ip' => '203.0.113.9', 'secure' => true]);
    }

    public function test_a_proxy_that_is_not_the_connecting_peer_is_not_believed(): void
    {
        $this->probe();
        config(['trustedproxy.proxies' => ['10.9.9.9']]);

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
            ->get('/_test/who')
            ->assertJson(['ip' => '127.0.0.1', 'secure' => false]);
    }

    public function test_only_the_listed_forwarded_headers_are_honoured(): void
    {
        $this->probe();
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        // The AWS ELB variant (and the prefix header) are not among the headers this app trusts.
        $this->withHeaders(['X-Forwarded-Prefix' => '/evil'])
            ->get('/_test/who')
            ->assertJson(['ip' => '127.0.0.1']);
    }

    public function test_a_wildcard_is_ignored_unless_it_is_allowed_explicitly(): void
    {
        $this->assertSame([], $this->loadTrustedProxyConfig(['TRUSTED_PROXIES' => '*'])['proxies']);
        $this->assertSame([], $this->loadTrustedProxyConfig(['TRUSTED_PROXIES' => '**'])['proxies']);
        $this->assertSame('*', $this->loadTrustedProxyConfig(['TRUSTED_PROXIES' => '*', 'TRUSTED_PROXIES_ALLOW_ALL' => 'true'])['proxies']);
        $this->assertSame(['10.0.0.1', '172.16.0.0/12'], $this->loadTrustedProxyConfig(['TRUSTED_PROXIES' => ' 10.0.0.1 , 172.16.0.0/12,*'])['proxies']);
    }

    public function test_only_the_host_of_app_url_is_trusted_as_the_request_host(): void
    {
        config(['app.url' => 'https://demo.example.com']);

        $hosts = (new TrustHosts($this->app))->hosts();

        $this->assertSame(['^demo\.example\.com$'], $hosts);
        $this->assertSame(1, preg_match('{'.$hosts[0].'}i', 'demo.example.com'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'evil.example.com'));
        $this->assertSame(0, preg_match('{'.$hosts[0].'}i', 'sub.demo.example.com'));
    }

    // ---- login throttling -----------------------------------------------------------------------

    private function failLogin(string $email): TestResponse
    {
        return $this->post(route('login'), ['email' => $email, 'password' => 'wrong-password']);
    }

    public function test_one_address_cannot_try_many_accounts_without_limit(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->failLogin("person{$i}@example.com")->assertSessionHasErrors('email');
        }

        $this->failLogin('person21@example.com')->assertStatus(429);
        $this->failLogin('someone-else@example.com')->assertStatus(429);
    }

    public function test_the_per_account_lockout_still_applies_before_the_address_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->failLogin('target@example.com')->assertSessionHasErrors('email');
        }

        $response = $this->failLogin('target@example.com');
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
    }

    public function test_a_spoofed_forwarded_for_from_an_untrusted_peer_does_not_reset_the_limit(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->withHeaders(['X-Forwarded-For' => "198.51.100.{$i}"])
                ->post(route('login'), ['email' => "p{$i}@example.com", 'password' => 'x']);
        }

        $this->withHeaders(['X-Forwarded-For' => '198.51.100.250'])
            ->post(route('login'), ['email' => 'p99@example.com', 'password' => 'x'])
            ->assertStatus(429);
    }

    public function test_behind_a_trusted_proxy_each_client_address_has_its_own_limit(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        for ($i = 1; $i <= 20; $i++) {
            $this->withHeaders(['X-Forwarded-For' => '203.0.113.1'])
                ->post(route('login'), ['email' => "p{$i}@example.com", 'password' => 'x']);
        }

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.1'])
            ->post(route('login'), ['email' => 'late@example.com', 'password' => 'x'])
            ->assertStatus(429);

        // A different visitor behind the same proxy is unaffected.
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.2'])
            ->post(route('login'), ['email' => 'late@example.com', 'password' => 'x'])
            ->assertSessionHasErrors('email');
    }

    public function test_a_successful_login_is_not_throttled_below_the_limit(): void
    {
        User::factory()->create(['email' => 'ok@example.com', 'password' => 'right-password-1']);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['email' => 'ok@example.com', 'password' => 'right-password-1'])
                ->assertSessionHasNoErrors()
                ->assertRedirect();
            $this->assertAuthenticated();
        }
    }

    public function test_ordinary_browsing_and_writing_is_never_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
            $this->actingAs($user)->get(route('customers.index'))->assertOk();
        }

        for ($i = 0; $i < 25; $i++) {
            $this->actingAs($user)->post(route('customers.store'), ['name' => "Customer {$i}"])->assertRedirect();
        }
    }

    public function test_the_sensitive_account_and_billing_writes_are_throttled(): void
    {
        $owner = User::factory()->create();

        foreach ([['put', 'business.profile.update', 30], ['post', 'billing.cancel', 10]] as [$method, $route, $limit]) {
            $statuses = [];
            for ($i = 0; $i <= $limit; $i++) {
                $statuses[] = $this->actingAs($owner)->{$method}(route($route), [])->getStatusCode();
            }

            $this->assertSame(429, end($statuses), "{$route} is limited to {$limit} a minute");
            $this->assertNotContains(429, array_slice($statuses, 0, $limit));
        }
    }
}
