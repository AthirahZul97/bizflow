<?php

namespace Tests\Unit\Mysql;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Mysql\ScratchTarget;

/**
 * The scratch-target guard rules. These extend PHPUnit's own TestCase on purpose: the framework
 * is never booted, so nothing here can open a database connection. They take the environment (and
 * the operating system family) as arguments, so the real process environment, the real machine
 * and .env are never read.
 */
class ScratchTargetTest extends TestCase
{
    private const PASSWORD = 'Sup3r-S3cret-Value';

    /** What a real runner reports, and what the tests tell the validator this PHP is running on. */
    private const LINUX = 'Linux';

    private const WINDOWS = 'Windows';

    /**
     * A valid local environment.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function local(array $overrides = []): array
    {
        return array_merge([
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_stage18_scratch',
            'BIZFLOW_MYSQL_SCRATCH_HOST' => '127.0.0.1',
            'BIZFLOW_MYSQL_SCRATCH_PORT' => '3308',
            'BIZFLOW_MYSQL_SCRATCH_USERNAME' => 'scratch_user',
            'BIZFLOW_MYSQL_SCRATCH_PASSWORD' => self::PASSWORD,
        ], $overrides);
    }

    /**
     * The default variables GitHub sets on every Actions runner step.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function runner(array $overrides = []): array
    {
        return array_merge([
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_RUN_ID' => '37594006206',
            'GITHUB_WORKFLOW' => 'CI',
            'GITHUB_EVENT_NAME' => 'pull_request',
            'GITHUB_REPOSITORY' => 'owner/repo',
            'GITHUB_WORKSPACE' => '/home/runner/work/repo/repo',
            'RUNNER_OS' => 'Linux',
            'RUNNER_TEMP' => '/home/runner/work/_temp',
        ], $overrides);
    }

    /**
     * A valid GitHub Actions environment, configured the way the existing CI job is (DB_*).
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function ci(array $overrides = []): array
    {
        return array_merge($this->runner(), [
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_ci_scratch',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => self::PASSWORD,
            'DB_DATABASE' => 'bizflow_ci',
        ], $overrides);
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function assertRefused(array $environment, ?string $applicationDatabase = null, string $os = self::LINUX): string
    {
        try {
            ScratchTarget::fromEnvironment($environment, $applicationDatabase, $os);
        } catch (InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage(), 'a refusal never contains the password');

            return $e->getMessage();
        }

        $this->fail('the target should have been refused');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function localNames(): array
    {
        return ['stage 18' => ['bizflow_stage18_scratch'], 'phase 2d' => ['bizflow_phase2d_scratch']];
    }

    // ---- accepted ---------------------------------------------------------------------------

    #[DataProvider('localNames')]
    public function test_each_local_name_is_accepted_locally_on_3308(string $name): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(['BIZFLOW_MYSQL_SCRATCH' => $name]), null, self::WINDOWS);

        $this->assertNotNull($target);
        $this->assertSame($name, $target->database);
        $this->assertSame(3308, $target->port);
        $this->assertSame('127.0.0.1', $target->host);
        $this->assertFalse($target->ci);
    }

    public function test_the_ci_name_is_accepted_in_github_actions_on_3306(): void
    {
        $target = ScratchTarget::fromEnvironment($this->ci(), 'bizflow_ci', self::LINUX);

        $this->assertNotNull($target);
        $this->assertSame('bizflow_ci_scratch', $target->database);
        $this->assertSame(3306, $target->port);
        $this->assertTrue($target->ci);
    }

    public function test_the_existing_ci_job_configuration_is_accepted_unchanged(): void
    {
        // The variables the ci.yml mysql job sets, plus the default variables GitHub adds to every
        // step. No dedicated scratch connection variables.
        $target = ScratchTarget::fromEnvironment([
            'GITHUB_ACTIONS' => 'true',
            'GITHUB_RUN_ID' => '1234567890',
            'GITHUB_WORKFLOW' => 'CI',
            'GITHUB_EVENT_NAME' => 'pull_request',
            'GITHUB_REPOSITORY' => 'AthirahZul97/bizflow',
            'GITHUB_WORKSPACE' => '/home/runner/work/bizflow/bizflow',
            'RUNNER_OS' => 'Linux',
            'RUNNER_TEMP' => '/home/runner/work/_temp',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => 'ci_root_pw',
            'DB_DATABASE' => 'bizflow_ci',
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_ci_scratch',
        ], 'bizflow_ci', self::LINUX);

        $this->assertSame('127.0.0.1:3306/bizflow_ci_scratch', $target->describe());
    }

    // ---- mode selection -----------------------------------------------------------------------

    public function test_github_actions_true_alone_does_not_enter_ci_mode(): void
    {
        // The one-variable bypass: a local shell with GITHUB_ACTIONS=true and the work MySQL's port.
        $this->assertRefused([
            'GITHUB_ACTIONS' => 'true',
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_ci_scratch',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_USERNAME' => 'root', 'DB_PASSWORD' => self::PASSWORD,
        ], null, self::WINDOWS);

        $message = $this->assertRefused([
            'GITHUB_ACTIONS' => 'true',
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_stage18_scratch',
            'BIZFLOW_MYSQL_SCRATCH_HOST' => '127.0.0.1', 'BIZFLOW_MYSQL_SCRATCH_PORT' => '3308',
            'BIZFLOW_MYSQL_SCRATCH_USERNAME' => 'u', 'BIZFLOW_MYSQL_SCRATCH_PASSWORD' => self::PASSWORD,
        ], null, self::WINDOWS);
        $this->assertStringContainsString('GitHub Actions markers', $message);
    }

    #[DataProvider('runnerMarkers')]
    public function test_every_missing_runner_marker_refuses_ci_mode(string $marker): void
    {
        $environment = $this->ci();
        unset($environment[$marker]);

        $this->assertStringContainsString('GitHub Actions markers', $this->assertRefused($environment));
        $this->assertStringContainsString('GitHub Actions markers', $this->assertRefused($this->ci([$marker => ''])));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function runnerMarkers(): array
    {
        $markers = ['GITHUB_ACTIONS', 'GITHUB_RUN_ID', 'GITHUB_WORKFLOW', 'GITHUB_EVENT_NAME', 'GITHUB_REPOSITORY', 'GITHUB_WORKSPACE', 'RUNNER_OS', 'RUNNER_TEMP'];

        return array_combine($markers, array_map(fn (string $marker) => [$marker], $markers));
    }

    #[DataProvider('triggerMarkers')]
    public function test_any_single_runner_marker_in_a_local_run_is_refused_not_treated_as_local(string $marker, string $value): void
    {
        $this->assertStringContainsString('GitHub Actions markers', $this->assertRefused($this->local([$marker => $value]), null, self::WINDOWS));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function triggerMarkers(): array
    {
        return [
            'GITHUB_ACTIONS' => ['GITHUB_ACTIONS', 'true'],
            'GITHUB_ACTIONS false' => ['GITHUB_ACTIONS', 'false'],
            'GITHUB_RUN_ID' => ['GITHUB_RUN_ID', '1'],
            'GITHUB_WORKFLOW' => ['GITHUB_WORKFLOW', 'CI'],
            'GITHUB_EVENT_NAME' => ['GITHUB_EVENT_NAME', 'push'],
            'RUNNER_OS' => ['RUNNER_OS', 'Linux'],
            'RUNNER_TEMP' => ['RUNNER_TEMP', '/tmp'],
        ];
    }

    public function test_a_complete_but_forged_runner_picture_is_refused_on_a_machine_that_is_not_linux(): void
    {
        // Every variable forged, as a local Windows shell could: PHP_OS_FAMILY cannot be forged.
        $message = $this->assertRefused($this->ci(), 'bizflow_ci', self::WINDOWS);
        $this->assertStringContainsString('GitHub Actions markers', $message);

        $this->assertRefused($this->ci(), 'bizflow_ci', 'Darwin');
    }

    public function test_inconsistent_runner_values_are_refused(): void
    {
        foreach ([
            ['GITHUB_ACTIONS' => '1'], ['GITHUB_ACTIONS' => 'TRUE'], ['GITHUB_ACTIONS' => 'false'], ['GITHUB_ACTIONS' => 'yes'],
            ['GITHUB_RUN_ID' => 'abc'], ['GITHUB_RUN_ID' => '0'], ['GITHUB_RUN_ID' => '12 34'], ['GITHUB_RUN_ID' => "1234\n"],
            ['RUNNER_OS' => 'Windows'], ['RUNNER_OS' => 'linux'], ['RUNNER_OS' => 'macOS'],
        ] as $override) {
            $this->assertRefused($this->ci($override));
        }
    }

    public function test_the_ci_name_is_not_accepted_locally_and_local_names_are_not_accepted_in_ci(): void
    {
        $this->assertStringContainsString('locally', $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH' => 'bizflow_ci_scratch']), null, self::WINDOWS));

        foreach (['bizflow_stage18_scratch', 'bizflow_phase2d_scratch'] as $name) {
            $this->assertStringContainsString('GitHub Actions', $this->assertRefused($this->ci(['BIZFLOW_MYSQL_SCRATCH' => $name])));
        }
    }

    // ---- opt-in ------------------------------------------------------------------------------

    public function test_an_unset_or_empty_opt_in_means_scratch_mode_is_not_requested(): void
    {
        $this->assertNull(ScratchTarget::fromEnvironment([]));
        $this->assertNull(ScratchTarget::fromEnvironment(['BIZFLOW_MYSQL_SCRATCH' => '']));
        // Connection variables alone do not opt in.
        $this->assertNull(ScratchTarget::fromEnvironment(array_diff_key($this->local(), ['BIZFLOW_MYSQL_SCRATCH' => 1])));
        $this->assertNull(ScratchTarget::fromEnvironment(['GITHUB_ACTIONS' => 'true', 'DB_PORT' => '3306']));
        // A real CI environment without the opt-in also just skips.
        $this->assertNull(ScratchTarget::fromEnvironment(array_diff_key($this->ci(), ['BIZFLOW_MYSQL_SCRATCH' => 1]), null, self::LINUX));
    }

    // ---- ports -------------------------------------------------------------------------------

    public function test_local_3306_is_rejected(): void
    {
        $message = $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH_PORT' => '3306']));

        $this->assertStringContainsString('3306', $message);
    }

    #[DataProvider('forbiddenPorts')]
    public function test_the_xeersoft_ports_are_rejected_locally_and_in_ci(string $port): void
    {
        $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH_PORT' => $port]));
        $this->assertRefused($this->ci(['DB_PORT' => $port]));
        $this->assertRefused($this->ci(['BIZFLOW_MYSQL_SCRATCH_HOST' => '127.0.0.1', 'BIZFLOW_MYSQL_SCRATCH_PORT' => $port, 'BIZFLOW_MYSQL_SCRATCH_USERNAME' => 'u', 'BIZFLOW_MYSQL_SCRATCH_PASSWORD' => self::PASSWORD]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenPorts(): array
    {
        return ['3307' => ['3307'], '3323' => ['3323']];
    }

    #[DataProvider('otherPorts')]
    public function test_any_other_local_port_is_rejected(string $port): void
    {
        $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH_PORT' => $port]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function otherPorts(): array
    {
        return [
            '3309' => ['3309'], '3310' => ['3310'], '3' => ['3'], 'zero' => ['0'],
            'leading zero' => ['03308'], 'trailing text' => ['3308abc'], 'padded' => [' 3308'],
            'decimal' => ['3308.0'], 'hex' => ['0xCEC'], 'too large' => ['99999'], 'negative' => ['-3308'],
            'trailing newline' => ["3308\n"],
        ];
    }

    public function test_a_trailing_newline_is_not_accepted_on_the_ci_port_either(): void
    {
        $this->assertRefused($this->ci(['DB_PORT' => "3306\n"]));
    }

    public function test_ci_accepts_only_3306(): void
    {
        foreach (['3308', '3309', '33060'] as $port) {
            $this->assertRefused($this->ci(['DB_PORT' => $port]));
        }
    }

    // ---- hosts -------------------------------------------------------------------------------

    #[DataProvider('remoteHosts')]
    public function test_a_non_loopback_host_is_rejected(string $host): void
    {
        $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH_HOST' => $host]));
        $this->assertRefused($this->ci(['DB_HOST' => $host]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function remoteHosts(): array
    {
        return [
            'lan' => ['192.168.1.20'], 'private' => ['10.0.0.5'], 'name' => ['db.example.com'],
            'any address' => ['0.0.0.0'], 'ipv6 loopback' => ['::1'], 'other loopback' => ['127.0.0.2'],
            'padded' => ['127.0.0.1 '], 'upper case' => ['LOCALHOST'], 'with port' => ['127.0.0.1:3308'],
            'localhost (can select a unix socket)' => ['localhost'], 'trailing newline' => ["127.0.0.1\n"],
        ];
    }

    // ---- names -------------------------------------------------------------------------------

    #[DataProvider('refusedNames')]
    public function test_other_database_names_are_rejected(string $name): void
    {
        $this->assertRefused($this->local(['BIZFLOW_MYSQL_SCRATCH' => $name]), null, self::WINDOWS);
        $this->assertRefused($this->ci(['BIZFLOW_MYSQL_SCRATCH' => $name]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedNames(): array
    {
        return [
            'the application database' => ['bizflow'],
            'the rehearsal database' => ['bizflow_rehearsal'],
            'an unapproved scratch-looking name' => ['bizflow_other_scratch'],
            'a bare scratch' => ['scratch'],
            'a prefix only' => ['bizflow_scratch'],
            'a case variant' => ['BIZFLOW_STAGE18_SCRATCH'],
            'no scratch at all' => ['orders'],
            'an injection attempt' => ['bizflow_stage18_scratch`; drop database bizflow; --'],
            'a name with a space' => ['bizflow_stage18_scratch '],
            'a name with a trailing newline' => ["bizflow_stage18_scratch\n"],
            'a system schema' => ['mysql'],
        ];
    }

    public function test_the_application_database_is_never_acceptable(): void
    {
        $message = $this->assertRefused($this->local(), 'bizflow_stage18_scratch', self::WINDOWS);

        $this->assertStringContainsString('application database', $message);
        $this->assertNotNull(ScratchTarget::fromEnvironment($this->local(), 'bizflow', self::WINDOWS), 'a different application database is fine');
    }

    // ---- missing configuration ----------------------------------------------------------------

    #[DataProvider('connectionVariables')]
    public function test_a_missing_local_setting_is_rejected_by_name_only(string $variable): void
    {
        $environment = $this->local();
        unset($environment[$variable]);

        $message = $this->assertRefused($environment, null, self::WINDOWS);
        $this->assertStringContainsString($variable, $message);

        $this->assertRefused($this->local([$variable => '']), null, self::WINDOWS);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function connectionVariables(): array
    {
        return [
            'host' => ['BIZFLOW_MYSQL_SCRATCH_HOST'],
            'port' => ['BIZFLOW_MYSQL_SCRATCH_PORT'],
            'username' => ['BIZFLOW_MYSQL_SCRATCH_USERNAME'],
            'password' => ['BIZFLOW_MYSQL_SCRATCH_PASSWORD'],
        ];
    }

    public function test_opting_in_without_any_connection_settings_locally_is_rejected(): void
    {
        $message = $this->assertRefused(['BIZFLOW_MYSQL_SCRATCH' => 'bizflow_stage18_scratch'], null, self::WINDOWS);

        foreach (['HOST', 'PORT', 'USERNAME', 'PASSWORD'] as $field) {
            $this->assertStringContainsString('BIZFLOW_MYSQL_SCRATCH_'.$field, $message);
        }
    }

    public function test_ci_settings_are_all_from_db_or_all_dedicated_never_mixed(): void
    {
        // Some dedicated variables set: the rest may not silently come from DB_*.
        $message = $this->assertRefused($this->ci([
            'BIZFLOW_MYSQL_SCRATCH_HOST' => '127.0.0.1',
            'BIZFLOW_MYSQL_SCRATCH_PORT' => '3306',
        ]));
        $this->assertStringContainsString('BIZFLOW_MYSQL_SCRATCH_USERNAME', $message);

        // Missing DB_* in CI.
        $environment = $this->ci();
        unset($environment['DB_PASSWORD']);
        $this->assertStringContainsString('DB_PASSWORD', $this->assertRefused($environment));
    }

    // ---- application DB_* never feeds a local target ------------------------------------------

    public function test_application_settings_cannot_stand_in_for_the_local_scratch_target(): void
    {
        // Only DB_* (a valid-looking application connection): not enough locally.
        $this->assertRefused([
            'BIZFLOW_MYSQL_SCRATCH' => 'bizflow_stage18_scratch',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3308',
            'DB_USERNAME' => 'bizflow_user',
            'DB_PASSWORD' => self::PASSWORD,
        ], null, self::WINDOWS);
    }

    public function test_application_settings_do_not_override_the_dedicated_scratch_target(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local([
            'DB_HOST' => 'db.example.com',
            'DB_PORT' => '3306',
            'DB_USERNAME' => 'bizflow_user',
            'DB_PASSWORD' => 'application-password',
            'DB_DATABASE' => 'bizflow',
        ]), null, self::WINDOWS);

        $config = $target->connectionConfig();
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame('3308', $config['port']);
        $this->assertSame('scratch_user', $config['username']);
        $this->assertSame(self::PASSWORD, $config['password']);
        $this->assertSame('bizflow_stage18_scratch', $config['database']);
        $this->assertNull($config['url']);
    }

    // ---- the server must be the validated one --------------------------------------------------

    public function test_the_connected_server_must_report_the_validated_database_and_port(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);

        $target->assertServerIdentity('bizflow_stage18_scratch', 3308);
        $target->assertServerIdentity('bizflow_stage18_scratch', '3308');

        foreach ([
            ['bizflow', 3308],
            [null, 3308],
            ['', 3308],
            ['bizflow_stage18_scratch', 3306],
            ['bizflow_stage18_scratch', 3307],
            ['bizflow_stage18_scratch', null],
            ['bizflow_phase2d_scratch', 3308],
        ] as [$database, $port]) {
            try {
                $target->assertServerIdentity($database, $port);
                $this->fail('a mismatching server must be refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
    }

    // ---- the worker receives, and re-validates, the same target --------------------------------

    public function test_the_local_worker_environment_round_trips_to_the_same_target(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);
        $overrides = $target->environmentOverrides();

        $this->assertSame('mysql', $overrides['DB_CONNECTION']);
        $this->assertSame('', $overrides['DB_URL']);
        foreach (['HOST' => '127.0.0.1', 'PORT' => '3308', 'USERNAME' => 'scratch_user', 'PASSWORD' => self::PASSWORD] as $field => $value) {
            $this->assertSame($value, $overrides['DB_'.$field]);
            $this->assertSame($value, $overrides['BIZFLOW_MYSQL_SCRATCH_'.$field]);
        }
        $this->assertSame('bizflow_stage18_scratch', $overrides['DB_DATABASE']);

        // A worker whose inherited environment carries the application's settings still ends up
        // on the same target, because the overrides replace them.
        $worker = ScratchTarget::fromEnvironment(array_merge(['DB_HOST' => 'elsewhere', 'DB_PORT' => '3306', 'DB_DATABASE' => 'bizflow'], $overrides), null, self::WINDOWS);
        $this->assertSame($target->describe(), $worker->describe());
        $this->assertSame($target->password(), $worker->password());
    }

    public function test_the_ci_worker_environment_round_trips_to_the_same_target(): void
    {
        $target = ScratchTarget::fromEnvironment($this->ci(), 'bizflow_ci', self::LINUX);

        // A CI worker inherits the runner markers (the parent passes getenv() on) plus the overrides.
        $worker = ScratchTarget::fromEnvironment(array_merge($this->ci(), $target->environmentOverrides()), null, self::LINUX);

        $this->assertTrue($worker->ci);
        $this->assertSame($target->describe(), $worker->describe());
    }

    public function test_a_worker_without_a_valid_target_refuses_rather_than_falling_back(): void
    {
        // What a worker would see if the overrides were lost: the application's own settings.
        $this->assertNull(ScratchTarget::fromEnvironment(['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'bizflow']));
        $this->assertRefused(['BIZFLOW_MYSQL_SCRATCH' => 'bizflow', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306']);
    }

    // ---- secrets -----------------------------------------------------------------------------

    public function test_the_password_is_not_in_any_printable_form_of_the_target(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);

        $this->assertStringNotContainsString(self::PASSWORD, $target->describe());
        $this->assertStringNotContainsString(self::PASSWORD, $target->dsn());
        $this->assertStringNotContainsString(self::PASSWORD, print_r($target, true));
        $this->assertStringNotContainsString(self::PASSWORD, var_export(get_object_vars($target), true));
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($target->__debugInfo()));
        $this->assertStringNotContainsString('scratch_user', $target->describe());
    }

    // ---- sockets can never redirect the target (V1) --------------------------------------------

    public function test_a_socket_from_the_application_or_environment_cannot_override_the_scratch_target(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(['DB_SOCKET' => '/var/run/mysqld/mysqld.sock']), null, self::WINDOWS);

        $this->assertSame('', $target->connectionConfig()['unix_socket']);
        $this->assertSame('', $target->environmentOverrides()['DB_SOCKET']);

        // MysqlTestCase and worker.php merge this over the application's own connection settings,
        // which may carry a socket and a URL: neither survives, and host and port are the target's.
        $merged = array_merge(
            ['driver' => 'mysql', 'unix_socket' => '/tmp/mysql.sock', 'url' => 'mysql://app@elsewhere/bizflow', 'host' => 'db', 'port' => '3306'],
            $target->connectionConfig(),
        );
        $this->assertSame('', $merged['unix_socket']);
        $this->assertNull($merged['url']);
        $this->assertSame('127.0.0.1', $merged['host']);
        $this->assertSame('3308', $merged['port']);

        // The worker's inherited environment may carry a socket; the overrides replace it.
        $workerEnvironment = array_merge(['DB_SOCKET' => '/tmp/inherited.sock'], $target->environmentOverrides());
        $this->assertSame('', $workerEnvironment['DB_SOCKET']);
    }

    // ---- the lock session is built from, and checked against, the target (V2, V3) -------------

    /**
     * @param  array<int, mixed>  $received
     */
    private function lockConnector(?PDO $returns, array &$received = [], ?\Throwable $throws = null): \Closure
    {
        return function (string $dsn, string $username, string $password, array $options) use ($returns, $throws, &$received): PDO {
            $received[] = [$dsn, $username, $password, $options];

            if ($throws !== null) {
                throw $throws;
            }

            return $returns;
        };
    }

    public function test_the_lock_session_connects_with_exactly_the_validated_target_and_returns_a_verified_connection(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(['DB_HOST' => 'elsewhere', 'DB_PORT' => '3306', 'DB_DATABASE' => 'bizflow', 'DB_SOCKET' => '/tmp/x.sock']), null, self::WINDOWS);
        $received = [];
        $server = new IdentityPdo('bizflow_stage18_scratch', 3308);

        $pdo = $target->openLockSession($this->lockConnector($server, $received));

        $this->assertSame($server, $pdo);
        $this->assertCount(1, $received, 'one attempt, no retry and no second target');
        $this->assertSame(
            ['mysql:host=127.0.0.1;port=3308;dbname=bizflow_stage18_scratch', 'scratch_user', self::PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]],
            $received[0],
        );
        $this->assertStringNotContainsString('socket', $received[0][0]);
    }

    /**
     * @return array<string, array{?string, ?int}>
     */
    public static function wrongServers(): array
    {
        return [
            'the application database on the right port' => ['bizflow', 3308],
            'the right database on the work port' => ['bizflow_stage18_scratch', 3306],
            'the right database on a xeersoft port' => ['bizflow_stage18_scratch', 3307],
            'the other approved database' => ['bizflow_phase2d_scratch', 3308],
            'no database selected' => [null, 3308],
            'no port reported' => ['bizflow_stage18_scratch', null],
        ];
    }

    #[DataProvider('wrongServers')]
    public function test_the_lock_session_is_refused_and_released_when_the_server_is_not_the_validated_one(?string $database, ?int $port): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);
        $reference = null;

        $connect = function () use ($database, $port, &$reference): PDO {
            $pdo = new IdentityPdo($database, $port);
            $reference = \WeakReference::create($pdo);

            return $pdo;
        };

        try {
            $target->openLockSession($connect);
            $this->fail('a wrong server must not yield a lock session');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('refusing the scratch lock session', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        unset($e);
        gc_collect_cycles();
        $this->assertNull($reference->get(), 'the refused connection is closed, not kept');
    }

    public function test_a_failing_identity_query_is_reported_without_the_driver_message(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);

        // SQLite has no database() function, so the identity query fails like an unreadable server would.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not verify the scratch lock session to 127\.0\.0\.1:3308\/bizflow_stage18_scratch \(identity: PDOException/');

        try {
            $target->openLockSession($this->lockConnector(new PDO('sqlite::memory:')));
        } catch (RuntimeException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('no such function', $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array<string, array{\Throwable, string}>
     */
    public static function connectFailures(): array
    {
        $withInfo = new PDOException('SQLSTATE[HY000] [1045] Access denied (using password: YES) '.self::PASSWORD);
        $withInfo->errorInfo = ['HY000', 1045, 'Access denied '.self::PASSWORD];

        return [
            'access denied, driver code 1045' => [$withInfo, 'PDOException, code 1045'],
            'refused, code only' => [new PDOException('connection refused '.self::PASSWORD, 2002), 'PDOException, code 2002'],
            'any other throwable' => [new \LogicException('internal '.self::PASSWORD), 'LogicException'],
        ];
    }

    #[DataProvider('connectFailures')]
    public function test_a_connection_failure_never_exposes_the_password_or_chains_the_original(\Throwable $failure, string $expected): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);

        try {
            $target->openLockSession($this->lockConnector(null, throws: $failure));
            $this->fail('a failed connection must throw');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(connect: '.$expected.')', $e->getMessage());
            $this->assertStringContainsString('127.0.0.1:3308/bizflow_stage18_scratch', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getTraceAsString());
            $this->assertStringNotContainsString(self::PASSWORD, (string) $e);
            $this->assertNull($e->getPrevious(), 'the original exception (whose trace arguments hold the password) is not chained');
        }
    }

    public function test_a_failed_lock_session_never_returns_a_connection_to_fall_back_on(): void
    {
        $target = ScratchTarget::fromEnvironment($this->local(), null, self::WINDOWS);
        $received = [];
        $result = 'untouched';

        try {
            $result = $target->openLockSession($this->lockConnector(null, $received, new PDOException('down')));
        } catch (RuntimeException) {
        }

        $this->assertSame('untouched', $result);
        $this->assertCount(1, $received, 'no second attempt against any other target');
    }

    public function test_invalid_configuration_is_refused_by_the_validator_alone(): void
    {
        // The framework is not booted in this class, so a validator that tried to use the
        // database would fail with a missing facade root instead of a clean refusal.
        $this->expectException(InvalidArgumentException::class);

        ScratchTarget::fromEnvironment($this->local(['BIZFLOW_MYSQL_SCRATCH_PORT' => '3306']), null, self::WINDOWS);
    }
}

/**
 * An in-memory SQLite handle that answers the server-identity query with fixed values. It is not a
 * MySQL connection and reaches nothing: it only lets the lock-session checks run offline.
 */
final class IdentityPdo extends PDO
{
    public function __construct(private readonly ?string $name, private readonly ?int $port)
    {
        parent::__construct('sqlite::memory:');
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $name = $this->name === null ? 'null' : "'".str_replace("'", "''", $this->name)."'";
        $port = $this->port === null ? 'null' : (string) $this->port;

        return parent::query("select {$name} as name, {$port} as port", PDO::FETCH_ASSOC);
    }
}
