<?php

namespace Tests\Feature\Security;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * A client must never be able to choose its own IP address, host, port or scheme: only a proxy the
 * operator listed is believed, and nothing that merely looks like "everyone" can be listed.
 */
class TrustedProxyForgeryTest extends TestCase
{
    private function probe(): void
    {
        Route::middleware('web')->get('/_test/who', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
            'host' => $request->getHost(),
            'port' => $request->getPort(),
            'scheme' => $request->getScheme(),
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function forgedHeaders(): array
    {
        return [
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Prefix' => '/evil',
            'Forwarded' => 'for=203.0.113.9;host=evil.example;proto=https',
            'X-Real-IP' => '203.0.113.9',
            'Client-IP' => '203.0.113.9',
        ];
    }

    private function assertNothingForged(): void
    {
        $this->probe();

        $this->withHeaders($this->forgedHeaders())->get('http://localhost/_test/who')->assertExactJson([
            'ip' => '127.0.0.1', 'secure' => false, 'host' => 'localhost', 'port' => 80, 'scheme' => 'http',
        ]);
    }

    public function test_with_no_proxy_listed_no_forwarded_header_changes_anything(): void
    {
        $this->assertSame([], config('trustedproxy.proxies'));

        $this->assertNothingForged();
    }

    public function test_a_proxy_that_is_not_the_connecting_peer_is_not_believed_for_any_header(): void
    {
        config(['trustedproxy.proxies' => ['10.9.9.9']]);

        $this->assertNothingForged();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeSettings(): array
    {
        return [
            'wildcard' => ['*'],
            'remote addr' => ['REMOTE_ADDR'],
            'ipv4 everything' => ['0.0.0.0/0'],
            'ipv6 everything' => ['::/0'],
            'unspecified' => ['0.0.0.0'],
            'unspecified ipv6' => ['::'],
            'a huge range' => ['10.0.0.0/7'],
        ];
    }

    #[DataProvider('unsafeSettings')]
    public function test_an_unsafe_setting_ends_up_trusting_nobody(string $setting): void
    {
        $parsed = TrustedProxies::parse($setting);
        config(['trustedproxy.proxies' => $parsed['proxies'], 'trustedproxy.rejected' => $parsed['rejected']]);

        $this->assertNothingForged();
        $this->assertNotSame([], config('trustedproxy.rejected'), 'and it is reported');
    }

    #[DataProvider('unsafeSettings')]
    public function test_the_config_file_applies_the_same_rules_to_the_environment(string $setting): void
    {
        foreach (['TRUSTED_PROXIES' => $setting] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $config = require base_path('config/trustedproxy.php');
        } finally {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }

        $this->assertSame([], $config['proxies']);
        $this->assertFalse($config['allow_all']);
        $this->assertNotSame([], $config['rejected']);
    }

    public function test_the_wildcard_switch_alone_is_not_enough_and_a_wildcard_alone_is_not_enough(): void
    {
        foreach ([['*', 'false'], ['', 'true']] as [$proxies, $allowAll]) {
            foreach (['TRUSTED_PROXIES' => $proxies, 'TRUSTED_PROXIES_ALLOW_ALL' => $allowAll] as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $_SERVER[$key] = $value;
            }

            try {
                $config = require base_path('config/trustedproxy.php');
            } finally {
                foreach (['TRUSTED_PROXIES', 'TRUSTED_PROXIES_ALLOW_ALL'] as $key) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);
                }
            }

            $this->assertSame([], $config['proxies']);
            $this->assertFalse($config['allow_all']);
        }
    }

    public function test_a_listed_proxy_is_believed_only_for_the_headers_the_application_trusts(): void
    {
        $this->probe();
        config(['trustedproxy.proxies' => ['127.0.0.1']]);

        $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Port' => '8443',
            'X-Forwarded-Prefix' => '/evil',
            'Forwarded' => 'for=198.51.100.1;proto=http',
            'X-Real-IP' => '198.51.100.2',
        ])->get('http://localhost/_test/who')->assertJson([
            'ip' => '203.0.113.9', 'secure' => true, 'port' => 8443, 'scheme' => 'https',
        ]);
    }

    public function test_a_forged_host_is_refused_once_trusted_hosts_apply(): void
    {
        config(['app.url' => 'https://demo.example.com']);

        // TrustHosts does nothing in the local environment or under test, so apply what it computes
        // the way it would in production.
        Request::setTrustedHosts((new TrustHosts($this->app))->hosts());

        try {
            $this->assertSame('demo.example.com', Request::create('https://demo.example.com/')->getHost());

            $this->expectException(SuspiciousOperationException::class);
            Request::create('https://evil.example/', 'GET', [], [], [], ['HTTP_HOST' => 'evil.example'])->getHost();
        } finally {
            Request::setTrustedHosts([]);
        }
    }
}
