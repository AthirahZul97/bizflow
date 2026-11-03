<?php

namespace Tests\Feature\Security;

use App\Support\Preflight;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "bizflow:preflight": the demo and production rules, the example env files that must satisfy
 * them, and the promise that the output carries no configured value.
 */
class PreflightTest extends TestCase
{
    private function example(string $name): array
    {
        return Preflight::settingsFromEnvFile(file_get_contents(base_path($name)));
    }

    /**
     * @return list<string> the checks that did not pass, as "LEVEL check"
     */
    private function problems(string $profile, array $settings, ?string $level = null): array
    {
        return array_values(array_map(
            fn (array $r) => $r['level'].' '.$r['check'],
            array_filter(
                Preflight::evaluate($profile, $settings),
                fn (array $r) => $r['level'] !== Preflight::PASS && ($level === null || $r['level'] === $level),
            ),
        ));
    }

    // ---- the example files stay honest ------------------------------------------------------------

    public function test_the_demo_example_passes_the_demo_profile(): void
    {
        $this->assertSame([], $this->problems('demo', $this->example('.env.demo.example'), Preflight::FAIL));
    }

    public function test_the_production_example_passes_the_production_profile(): void
    {
        $this->assertSame([], $this->problems('production', $this->example('.env.production.example'), Preflight::FAIL));
    }

    public function test_each_example_fails_the_other_profile(): void
    {
        $this->assertContains('FAIL BIZFLOW_DEMO_MODE', $this->problems('production', $this->example('.env.demo.example')));
        $this->assertContains('FAIL MAIL_MAILER', $this->problems('production', $this->example('.env.demo.example')));
        $this->assertContains('FAIL BIZFLOW_DEMO_MODE', $this->problems('demo', $this->example('.env.production.example')));
        $this->assertContains('FAIL MAIL_MAILER', $this->problems('demo', $this->example('.env.production.example')));
    }

    public function test_the_examples_name_every_setting_the_checks_read_and_carry_no_secrets(): void
    {
        foreach (['.env.demo.example', '.env.production.example'] as $file) {
            $contents = file_get_contents(base_path($file));

            foreach (['APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'BIZFLOW_DEMO_MODE', 'BIZFLOW_REGISTRATION', 'TRUSTED_PROXIES', 'HSTS_MAX_AGE', 'SESSION_DRIVER', 'SESSION_SECURE_COOKIE', 'MAIL_MAILER', 'OCR_DRIVER', 'BILLING_PROVIDER', 'QUEUE_CONNECTION', 'LOG_LEVEL'] as $key) {
                $this->assertMatchesRegularExpression('/^'.$key.'=/m', $contents, "{$file} must set {$key}");
            }

            foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'DB_USERNAME', 'MAIL_USERNAME'] as $key) {
                $this->assertDoesNotMatchRegularExpression('/^'.$key.'=.+$/m', $contents, "{$file} must leave {$key} empty or out");
            }
        }

        $this->assertMatchesRegularExpression('/^\.env$/m', file_get_contents(base_path('.gitignore')), 'the real .env is never committed');
    }

    // ---- the rejections the demo and production profiles exist for ---------------------------------

    /**
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function rejections(): array
    {
        return [
            'debug on, demo' => ['demo', ['app_debug' => true], 'FAIL APP_DEBUG'],
            'debug on, production' => ['production', ['app_debug' => true], 'FAIL APP_DEBUG'],
            'not production env, demo' => ['demo', ['app_env' => 'local'], 'FAIL APP_ENV'],
            'http url, demo' => ['demo', ['app_url' => 'http://demo.example.com'], 'FAIL APP_URL'],
            'localhost url, production' => ['production', ['app_url' => 'https://localhost'], 'FAIL APP_URL'],
            'empty url, demo' => ['demo', ['app_url' => ''], 'FAIL APP_URL'],
            'cookie not secure, demo' => ['demo', ['session_secure' => false], 'FAIL SESSION_SECURE_COOKIE'],
            'cookie secure unset, production' => ['production', ['session_secure' => null], 'FAIL SESSION_SECURE_COOKIE'],
            'file sessions, production' => ['production', ['session_driver' => 'file'], 'FAIL SESSION_DRIVER'],
            'array sessions, demo' => ['demo', ['session_driver' => 'array'], 'WARN SESSION_DRIVER'],
            'smtp, demo' => ['demo', ['mail_transport' => 'smtp'], 'FAIL MAIL_MAILER'],
            'ses, demo' => ['demo', ['mail_transport' => 'ses'], 'FAIL MAIL_MAILER'],
            'failover, demo' => ['demo', ['mail_transport' => 'failover'], 'FAIL MAIL_MAILER'],
            'log mailer, production' => ['production', ['mail_transport' => 'log'], 'FAIL MAIL_MAILER'],
            'array mailer, production' => ['production', ['mail_transport' => 'array'], 'FAIL MAIL_MAILER'],
            'sendmail, production' => ['production', ['mail_transport' => 'sendmail'], 'FAIL MAIL_MAILER'],
            'unknown mailer, production' => ['production', ['mail_transport' => null], 'FAIL MAIL_MAILER'],
            'fake ocr, production' => ['production', ['ocr_driver' => 'fake'], 'FAIL OCR_DRIVER'],
            'unknown ocr, demo' => ['demo', ['ocr_driver' => 'textract'], 'FAIL OCR_DRIVER'],
            'payment provider, demo' => ['demo', ['billing_provider' => 'stripe'], 'FAIL BILLING_PROVIDER'],
            'demo flag off, demo profile' => ['demo', ['demo' => false], 'FAIL BIZFLOW_DEMO_MODE'],
            'demo flag on, production profile' => ['production', ['demo' => true], 'FAIL BIZFLOW_DEMO_MODE'],
            'trust all, production' => ['production', ['proxies_allow_all' => true], 'FAIL TRUSTED_PROXIES'],
            'trust all, demo' => ['demo', ['proxies_allow_all' => true], 'FAIL TRUSTED_PROXIES'],
            'rejected proxy entry, demo' => ['demo', ['proxies_rejected' => ['REMOTE_ADDR']], 'FAIL TRUSTED_PROXIES (entries)'],
            'rejected proxy entry, production' => ['production', ['proxies_rejected' => ['0.0.0.0/0']], 'FAIL TRUSTED_PROXIES (entries)'],
            'sync queue, production' => ['production', ['queue_connection' => 'sync'], 'FAIL QUEUE_CONNECTION'],
            'no hsts, production' => ['production', ['hsts_max_age' => 0], 'WARN HSTS_MAX_AGE'],
            'debug logging, demo' => ['demo', ['log_level' => 'debug'], 'WARN LOG_LEVEL'],
            'missing key, demo' => ['demo', ['app_key_set' => false], 'FAIL APP_KEY'],
            'missing key, local' => ['local', ['app_key_set' => false], 'FAIL APP_KEY'],
        ];
    }

    #[DataProvider('rejections')]
    public function test_the_unsafe_setting_is_reported(string $profile, array $change, string $expected): void
    {
        $base = $this->example($profile === 'production' ? '.env.production.example' : '.env.demo.example');

        $this->assertContains($expected, $this->problems($profile, array_merge($base, $change)));
    }

    public function test_the_local_profile_only_insists_on_a_key(): void
    {
        $settings = array_merge($this->example('.env.demo.example'), ['app_debug' => true, 'app_env' => 'local', 'app_url' => 'http://localhost']);

        $this->assertSame([], $this->problems('local', $settings));
    }

    // ---- the command ---------------------------------------------------------------------------------

    private function demoConfig(array $overrides = []): void
    {
        config(array_merge([
            'app.key' => 'base64:'.base64_encode(str_repeat('K', 32)),
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://demo.example.com',
            'session.secure' => true,
            'session.driver' => 'database',
            'mail.default' => 'log',
            'ocr.driver' => 'fake',
            'billing.provider' => 'manual',
            'bizflow.demo' => true,
            'bizflow.registration' => 'closed',
            'queue.default' => 'database',
            'logging.channels.single.level' => 'warning',
            'trustedproxy.proxies' => ['203.0.113.10'],
        ], $overrides));
    }

    public function test_the_command_passes_a_correct_demo_configuration(): void
    {
        $this->demoConfig();

        $this->artisan('bizflow:preflight')
            ->expectsOutputToContain('Profile: demo')
            ->expectsOutputToContain('No failures')
            ->assertExitCode(0);
    }

    public function test_the_command_fails_and_says_so_for_an_unsafe_configuration(): void
    {
        $this->demoConfig(['app.debug' => true, 'mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);

        $this->artisan('bizflow:preflight --profile=demo')
            ->expectsOutputToContain('[FAIL] APP_DEBUG')
            ->expectsOutputToContain('[FAIL] MAIL_MAILER')
            ->expectsOutputToContain('Do not deploy.')
            ->assertExitCode(1);
    }

    public function test_the_command_detects_its_profile_and_rejects_an_unknown_one(): void
    {
        $this->artisan('bizflow:preflight')->expectsOutputToContain('Profile: local')->assertExitCode(0);

        $this->artisan('bizflow:preflight --profile=staging')->expectsOutputToContain('Unknown profile')->assertExitCode(2);

        $this->demoConfig(['bizflow.demo' => false]);
        $this->app['env'] = 'production';
        $this->artisan('bizflow:preflight')->expectsOutputToContain('Profile: production');
    }

    public function test_the_output_never_contains_a_configured_value(): void
    {
        $secrets = [
            'APP_KEY' => 'base64:SUPER-SECRET-APP-KEY-VALUE-0123456789=',
            'DB' => 'database-password-s3cret',
            'MAIL' => 'smtp-password-s3cret',
            'HOST' => 'private-host-name.internal',
        ];
        $this->demoConfig([
            'app.key' => $secrets['APP_KEY'],
            'app.debug' => true,
            'app.url' => 'http://'.$secrets['HOST'],
            'database.connections.mysql.password' => $secrets['DB'],
            'mail.mailers.smtp.password' => $secrets['MAIL'],
            'mail.mailers.smtp.host' => $secrets['HOST'],
            'trustedproxy.proxies' => ['198.51.100.77'],
        ]);

        Artisan::call('bizflow:preflight', ['--profile' => 'demo']);
        $output = Artisan::output();
        Artisan::call('bizflow:preflight', ['--profile' => 'production']);
        $output .= Artisan::output();

        $this->assertNotSame('', $output);
        foreach ([...array_values($secrets), '198.51.100.77', 'SUPER-SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_the_command_reads_configuration_only_and_never_touches_the_database(): void
    {
        $this->demoConfig();
        DB::enableQueryLog();

        $this->artisan('bizflow:preflight')->assertExitCode(0);

        $this->assertSame([], DB::getQueryLog());
    }
}
