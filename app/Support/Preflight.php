<?php

namespace App\Support;

use Dotenv\Dotenv;

/**
 * The deployment checks behind "php artisan bizflow:preflight": is this environment safe to put
 * in front of people as a demo or as production?
 *
 * Pure on purpose: evaluate() takes a plain array of settings and returns results, so the same rules
 * run against the live configuration (the command) and against the example env files (a test).
 * A result carries a check name and a fixed message, never a configured value, so a log of the
 * output cannot leak a secret.
 */
final class Preflight
{
    public const PROFILES = ['local', 'demo', 'production'];

    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    /**
     * @param  array<string, mixed>  $s  see settingsFromConfig()
     * @return list<array{level: string, check: string, message: string}>
     */
    public static function evaluate(string $profile, array $s): array
    {
        $results = [];
        $public = $profile !== 'local';
        $add = function (string $check, bool $ok, string $level, string $message) use (&$results): void {
            $results[] = ['level' => $ok ? self::PASS : $level, 'check' => $check, 'message' => $message];
        };

        $add('APP_KEY', (bool) $s['app_key_set'], self::FAIL, 'An application key is set (encrypts cookies and signs links).');

        if (! $public) {
            return $results;
        }

        $add('APP_ENV', $s['app_env'] === 'production', self::FAIL, 'APP_ENV is "production" (several safeguards key off it).');
        $add('APP_DEBUG', $s['app_debug'] === false, self::FAIL, 'APP_DEBUG is off (debug pages expose code, paths and configuration).');

        $scheme = parse_url((string) $s['app_url'], PHP_URL_SCHEME);
        $host = (string) parse_url((string) $s['app_url'], PHP_URL_HOST);
        $local = in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true);
        $add('APP_URL', $scheme === 'https' && ! $local, self::FAIL, 'APP_URL is an https address for the public host.');

        $add('SESSION_SECURE_COOKIE', $s['session_secure'] === true, self::FAIL, 'Session cookies are marked Secure.');
        $add('SESSION_DRIVER', ! in_array($s['session_driver'], ['array', 'cookie'], true) && ($profile === 'demo' || $s['session_driver'] !== 'file'),
            $profile === 'demo' ? self::WARN : self::FAIL,
            'Sessions are stored in a shared store (database), not in files, the cookie or memory.');

        $transports = (array) config("bizflow.mail.{$profile}_transports", []);
        $add('MAIL_MAILER', in_array($s['mail_transport'], $transports, true), self::FAIL,
            $profile === 'demo'
                ? 'Mail uses a transport that cannot deliver (log or array).'
                : 'Mail uses an approved transport (smtp, ses, postmark or resend).');

        $add('OCR_DRIVER', $profile === 'demo'
            ? in_array($s['ocr_driver'], ['fake', 'none'], true)
            : $s['ocr_driver'] === 'none', self::FAIL,
            $profile === 'demo'
                ? 'Receipt OCR is the labelled demo provider or switched off.'
                : 'Receipt OCR is switched off (the demo provider invents data and no real provider is integrated).');

        $add('BILLING_PROVIDER', $s['billing_provider'] === 'manual', self::FAIL, 'No payment provider is configured.');

        $add('BIZFLOW_DEMO_MODE', $s['demo'] === ($profile === 'demo'), self::FAIL,
            $profile === 'demo' ? 'Demo mode is on.' : 'Demo mode is off.');

        if ($profile === 'production') {
            $add('BIZFLOW_REGISTRATION', $s['registration'] !== 'open' || $s['require_verified_email'], self::WARN,
                'Open registration comes with required email verification.');
            $add('HSTS_MAX_AGE', (int) $s['hsts_max_age'] > 0, self::WARN, 'HSTS is enabled once HTTPS is confirmed.');
            $add('QUEUE_CONNECTION', $s['queue_connection'] !== 'sync', self::FAIL, 'Queued work runs on a worker, not inside the request.');
        } else {
            $add('QUEUE_CONNECTION', $s['queue_connection'] !== 'sync', self::WARN, 'Queued work runs on a worker, not inside the request.');
        }

        $add('TRUSTED_PROXIES', ! $s['proxies_allow_all'], self::FAIL,
            'Not every connecting peer is trusted as a proxy (no wildcard, even with TRUSTED_PROXIES_ALLOW_ALL).');
        $add('TRUSTED_PROXIES (entries)', $s['proxies_rejected'] === [], self::FAIL,
            'Every TRUSTED_PROXIES entry is a plain IP or a CIDR range of plausible size (no *, REMOTE_ADDR, 0.0.0.0/0, ::/0 or similar).');
        $add('TRUSTED_PROXIES (list)', ! empty($s['proxies']) || $s['proxies_allow_all'], self::WARN,
            'The reverse proxies in front of the app are listed (leave empty only if the app is directly exposed).');

        $add('LOG_LEVEL', ! in_array($s['log_level'], ['debug', 'info'], true), self::WARN, 'The log level is warning or higher.');

        return $results;
    }

    /**
     * The settings of the running application.
     *
     * @return array<string, mixed>
     */
    public static function settingsFromConfig(): array
    {
        $proxies = config('trustedproxy.proxies');

        return [
            'app_key_set' => (string) config('app.key') !== '',
            'app_env' => (string) config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'app_url' => (string) config('app.url'),
            'session_driver' => (string) config('session.driver'),
            'session_secure' => config('session.secure') === null ? null : (bool) config('session.secure'),
            'mail_transport' => DemoMode::defaultTransport(),
            'ocr_driver' => (string) config('ocr.driver'),
            'billing_provider' => (string) config('billing.provider'),
            'demo' => (bool) config('bizflow.demo'),
            'registration' => (string) config('bizflow.registration'),
            'require_verified_email' => (bool) config('bizflow.require_verified_email'),
            'hsts_max_age' => (int) config('bizflow.security.hsts_max_age'),
            'queue_connection' => (string) config('queue.default'),
            'proxies' => is_array($proxies) ? $proxies : [],
            'proxies_rejected' => (array) config('trustedproxy.rejected', []),
            'proxies_allow_all' => $proxies === '*',
            'log_level' => (string) config('logging.channels.'.config('logging.default').'.level', 'debug'),
        ];
    }

    /**
     * The same settings, read from the text of an env file (an example file, in the tests), with the
     * defaults the application's config files apply. The key is reported as set: example files never
     * carry one.
     *
     * @return array<string, mixed>
     */
    public static function settingsFromEnvFile(string $contents): array
    {
        $env = Dotenv::parse($contents);
        $get = fn (string $key, mixed $default = null) => ($env[$key] ?? '') === '' ? $default : $env[$key];
        $bool = fn (string $key, bool $default = false): bool => filter_var($get($key, $default), FILTER_VALIDATE_BOOLEAN);

        $mailer = (string) $get('MAIL_MAILER', 'log');
        $proxies = TrustedProxies::parse((string) $get('TRUSTED_PROXIES', ''), $bool('TRUSTED_PROXIES_ALLOW_ALL'));
        $secure = $get('SESSION_SECURE_COOKIE');

        return [
            'app_key_set' => true,
            'app_env' => (string) $get('APP_ENV', 'production'),
            'app_debug' => $bool('APP_DEBUG', false),
            'app_url' => (string) $get('APP_URL', 'http://localhost'),
            'session_driver' => (string) $get('SESSION_DRIVER', 'database'),
            'session_secure' => $secure === null ? null : filter_var($secure, FILTER_VALIDATE_BOOLEAN),
            'mail_transport' => config("mail.mailers.{$mailer}.transport"),
            'ocr_driver' => (string) $get('OCR_DRIVER', 'fake'),
            'billing_provider' => (string) $get('BILLING_PROVIDER', 'manual'),
            'demo' => $bool('BIZFLOW_DEMO_MODE'),
            'registration' => (string) $get('BIZFLOW_REGISTRATION', 'open'),
            'require_verified_email' => $bool('BIZFLOW_REQUIRE_VERIFIED_EMAIL'),
            'hsts_max_age' => (int) $get('HSTS_MAX_AGE', 0),
            'queue_connection' => (string) $get('QUEUE_CONNECTION', 'database'),
            'proxies' => is_array($proxies['proxies']) ? $proxies['proxies'] : [],
            'proxies_rejected' => $proxies['rejected'],
            'proxies_allow_all' => $proxies['allow_all'],
            'log_level' => (string) $get('LOG_LEVEL', 'debug'),
        ];
    }
}
