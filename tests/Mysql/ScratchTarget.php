<?php

namespace Tests\Mysql;

use Closure;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The one place that decides which MySQL server and database the opt-in MySQL tests (and their
 * worker processes) may use. It is validated BEFORE any connection is opened, needs neither the
 * framework nor a database, and is used by MysqlTestCase and worker.php alike so that both apply
 * identical rules.
 *
 * Opt-in: BIZFLOW_MYSQL_SCRATCH names the database. If it is empty nothing is run (the caller
 * skips). The connection is built ONLY from dedicated variables, never from the application's
 * DB_HOST / DB_PORT / DB_USERNAME / DB_PASSWORD:
 *
 *   BIZFLOW_MYSQL_SCRATCH, BIZFLOW_MYSQL_SCRATCH_HOST, _PORT, _USERNAME, _PASSWORD
 *
 * Set them in the process environment of the shell that runs the tests; never in .env or in a
 * tracked file.
 *
 * Mode selection (fail closed). One variable is never enough to select CI mode:
 *
 *   Local:           no GitHub Actions marker is present at all. Host 127.0.0.1 only, port 3308
 *                    only, database `bizflow_stage18_scratch` or `bizflow_phase2d_scratch` only.
 *   GitHub Actions:  every runner marker is present and consistent (GITHUB_ACTIONS=true, a numeric
 *                    GITHUB_RUN_ID, GITHUB_WORKFLOW, GITHUB_EVENT_NAME, GITHUB_REPOSITORY,
 *                    GITHUB_WORKSPACE, RUNNER_TEMP, RUNNER_OS=Linux) AND this PHP really is
 *                    running on Linux (PHP_OS_FAMILY, which an environment variable cannot
 *                    change). Host 127.0.0.1 only, port 3306 only, database `bizflow_ci_scratch`
 *                    only. The existing job configures its disposable service container through
 *                    DB_*, so when none of the four dedicated connection variables is set, CI
 *                    reads the connection from DB_* (all four or none; never mixed). Local runs
 *                    have no such fallback.
 *   Anything else:   if any GitHub Actions marker is present but the set is incomplete or
 *                    inconsistent (a local shell with GITHUB_ACTIONS=true, a Windows machine
 *                    claiming RUNNER_OS=Linux, ...), the target is refused; it is never treated
 *                    as local mode either.
 *   Never:           ports 3307 and 3323 (XeerSoft) anywhere, and 3306 locally (the work MySQL).
 *
 * `bizflow`, `bizflow_rehearsal` and any other name are refused. After connecting, the server
 * itself is asked for its database and port (assertServerIdentity) before anything destructive
 * may run.
 *
 * What the identity checks prove, and what they do not. The validated host/port/name are checked
 * BEFORE connecting, and `select database(), @@port` AFTER connecting. Together they show that the
 * connection landed on a schema with the approved name on a server that reports the approved
 * port, using only the dedicated variables. They do NOT prove that the server is an isolated
 * scratch instance: any server that listens on that loopback port and holds a schema of that name
 * would satisfy them, and a lookalike service, a port forward, or a second instance configured to
 * report the same port is indistinguishable here. Isolation comes from how the instance is
 * provisioned (its own data directory, loopback-only binding, an account with privileges on the
 * scratch schema alone) and must be verified there; the checks here only stop mistakes.
 *
 * Failure messages never contain the password.
 */
final class ScratchTarget
{
    /** Databases a local run may use (dedicated instance on LOCAL_PORT). */
    public const LOCAL_DATABASES = [
        'bizflow_stage18_scratch',
        'bizflow_phase2d_scratch',
    ];

    /** The only database a GitHub Actions run may use (the job's service container). */
    public const CI_DATABASES = ['bizflow_ci_scratch'];

    public const APPROVED_DATABASES = [...self::LOCAL_DATABASES, ...self::CI_DATABASES];

    /** Present at all => this is (or pretends to be) a runner: local mode is not available. */
    private const RUNNER_TRIGGER_MARKERS = [
        'GITHUB_ACTIONS', 'GITHUB_RUN_ID', 'GITHUB_WORKFLOW', 'GITHUB_EVENT_NAME', 'RUNNER_OS', 'RUNNER_TEMP',
    ];

    /** All must be present for CI mode (GitHub sets these default variables on every runner step). */
    private const RUNNER_REQUIRED_MARKERS = [
        'GITHUB_ACTIONS', 'GITHUB_RUN_ID', 'GITHUB_WORKFLOW', 'GITHUB_EVENT_NAME', 'GITHUB_REPOSITORY', 'GITHUB_WORKSPACE', 'RUNNER_OS', 'RUNNER_TEMP',
    ];

    public const LOCAL_PORT = 3308;

    public const CI_PORT = 3306;

    /** Services that must never be used, in any environment. */
    public const FORBIDDEN_PORTS = [3307, 3323];

    /**
     * The only host accepted. `localhost` is deliberately not: PDO and the MySQL client libraries
     * turn it into the default Unix socket on non-Windows systems and ignore the port, which
     * could reach a different server than the one validated.
     */
    public const LOOPBACK_HOSTS = ['127.0.0.1'];

    /** `\z`, not `$`: `$` would also accept a trailing newline. */
    private const NAME_PATTERN = '/^[A-Za-z0-9_]*scratch[A-Za-z0-9_]*\z/';

    private const CONNECTION_VARIABLES = ['HOST', 'PORT', 'USERNAME', 'PASSWORD'];

    /** Held in a closure so a dumped or exported object does not show it. */
    private readonly Closure $password;

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        string $password,
        public readonly bool $ci,
    ) {
        $this->password = static fn (): string => $password;
    }

    /**
     * The current process environment (what the shell, or the parent test process, passed in).
     *
     * @return array<string, string>
     */
    public static function processEnvironment(): array
    {
        return array_filter(getenv(), 'is_string');
    }

    /**
     * Validate the environment and return the one permitted target, or null when scratch mode
     * is not requested (BIZFLOW_MYSQL_SCRATCH unset or empty): the caller must then skip.
     *
     * @param  array<string, string>  $environment
     * @param  string|null  $applicationDatabase  The application's configured database name, which is never acceptable.
     * @param  string|null  $osFamily  The operating system family of this PHP; defaults to PHP_OS_FAMILY. Only tests pass it.
     *
     * @throws InvalidArgumentException When scratch mode is requested but the target is not permitted.
     */
    public static function fromEnvironment(array $environment, ?string $applicationDatabase = null, ?string $osFamily = null): ?self
    {
        $database = self::value($environment, 'BIZFLOW_MYSQL_SCRATCH');

        if ($database === '') {
            return null;
        }

        if (preg_match(self::NAME_PATTERN, $database) !== 1) {
            throw new InvalidArgumentException('the scratch database name must contain "scratch" and use only letters, digits and underscores');
        }

        if (! in_array($database, self::APPROVED_DATABASES, true)) {
            throw new InvalidArgumentException('the scratch database name is not an approved name (approved: '.implode(', ', self::APPROVED_DATABASES).')');
        }

        if ($applicationDatabase !== null && $database === $applicationDatabase) {
            throw new InvalidArgumentException('refusing to use the application database');
        }

        $ci = self::runsOnGithubActions($environment, $osFamily ?? PHP_OS_FAMILY);

        if (! in_array($database, $ci ? self::CI_DATABASES : self::LOCAL_DATABASES, true)) {
            throw new InvalidArgumentException($ci
                ? 'in GitHub Actions the scratch database must be '.implode(', ', self::CI_DATABASES)
                : 'locally the scratch database must be one of: '.implode(', ', self::LOCAL_DATABASES));
        }

        [$host, $port, $username, $password] = self::connectionFields($environment, $ci);

        if (! in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw new InvalidArgumentException('the scratch host must be exactly 127.0.0.1 (localhost is not accepted: it can select a Unix socket and ignore the port)');
        }

        $portNumber = self::port($port, $ci);

        return new self($host, $portNumber, $database, $username, $password, $ci);
    }

    /**
     * The target that was actually reached must be the validated one. Call this on the first
     * query after connecting (select database(), @@port) and before anything destructive.
     *
     * @throws InvalidArgumentException
     */
    public function assertServerIdentity(mixed $actualDatabase, mixed $actualPort): void
    {
        if (! is_string($actualDatabase) || $actualDatabase !== $this->database) {
            throw new InvalidArgumentException('the connected database is not the validated scratch database');
        }

        if ((string) $actualPort !== (string) $this->port) {
            throw new InvalidArgumentException("the connected server reports port {$actualPort}, not the validated port {$this->port}");
        }
    }

    /**
     * Laravel connection settings for the `mysql` connection, built only from this target.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        return [
            'url' => null,
            // A configured socket makes Laravel ignore host and port entirely; never inherit one.
            'unix_socket' => '',
            'host' => $this->host,
            'port' => (string) $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => ($this->password)(),
        ];
    }

    public function dsn(): string
    {
        return "mysql:host={$this->host};port={$this->port};dbname={$this->database}";
    }

    /**
     * A raw PDO session of its own (the lock holder in the concurrency tests), built only from
     * this target and checked before it is returned: the server must report the validated
     * database and port, or the connection is closed and nothing is returned. There is no
     * fallback to any other connection.
     *
     * A failure never carries the original exception: a PDO constructor failure keeps the
     * password in its stack-trace arguments (zend.exception_ignore_args is often off), so only a
     * generic message with the failure stage, the exception class and a numeric/alphanumeric
     * error code is thrown. $connect is a seam for offline tests.
     *
     * @param  (Closure(string, string, string, array<int, mixed>): PDO)|null  $connect
     *
     * @throws RuntimeException
     */
    public function openLockSession(?Closure $connect = null): PDO
    {
        $connect ??= static fn (string $dsn, string $username, string $password, array $options): PDO => new PDO($dsn, $username, $password, $options);

        try {
            $pdo = $connect($this->dsn(), $this->username, $this->password(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            throw new RuntimeException("could not open the scratch lock session to {$this->describe()} (connect: ".self::safeFailure($e).')');
        }

        try {
            $statement = $pdo->query('select database() as name, @@port as port');
            $server = $statement instanceof PDOStatement ? $statement->fetch(PDO::FETCH_ASSOC) : false;

            if (! is_array($server)) {
                throw new InvalidArgumentException('the lock session could not read the server identity');
            }

            $this->assertServerIdentity($server['name'] ?? null, $server['port'] ?? null);
        } catch (InvalidArgumentException $e) {
            $pdo = null;

            throw new RuntimeException('refusing the scratch lock session: '.$e->getMessage());
        } catch (Throwable $e) {
            $pdo = null;

            throw new RuntimeException("could not verify the scratch lock session to {$this->describe()} (identity: ".self::safeFailure($e).')');
        }

        return $pdo;
    }

    /**
     * The exception class and a sanitised error code; never the message or the trace.
     */
    private static function safeFailure(Throwable $e): string
    {
        $code = $e instanceof PDOException ? ($e->errorInfo[1] ?? $e->getCode()) : $e->getCode();
        $code = preg_replace('/[^A-Za-z0-9]/', '', (string) $code);

        return $e::class.($code !== '' && $code !== '0' ? ", code {$code}" : '');
    }

    public function password(): string
    {
        return ($this->password)();
    }

    /**
     * Environment for a worker process: the dedicated variables (so the worker validates the
     * same target independently) and the DB_* the worker's application will use. DB_URL and
     * DB_SOCKET are blanked so neither a URL nor a socket path can redirect the connection.
     *
     * @return array<string, string>
     */
    public function environmentOverrides(): array
    {
        return [
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_SOCKET' => '',
            'DB_HOST' => $this->host,
            'DB_PORT' => (string) $this->port,
            'DB_DATABASE' => $this->database,
            'DB_USERNAME' => $this->username,
            'DB_PASSWORD' => ($this->password)(),
            'BIZFLOW_MYSQL_SCRATCH' => $this->database,
            'BIZFLOW_MYSQL_SCRATCH_HOST' => $this->host,
            'BIZFLOW_MYSQL_SCRATCH_PORT' => (string) $this->port,
            'BIZFLOW_MYSQL_SCRATCH_USERNAME' => $this->username,
            'BIZFLOW_MYSQL_SCRATCH_PASSWORD' => ($this->password)(),
        ];
    }

    /**
     * A description that is safe to print: no username, no password.
     */
    public function describe(): string
    {
        return "{$this->host}:{$this->port}/{$this->database}";
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['target' => $this->describe(), 'ci' => $this->ci];
    }

    /**
     * Decide the mode, failing closed: CI only when the whole runner picture is present and
     * consistent; local only when no runner marker exists at all; otherwise refuse.
     *
     * @param  array<string, string>  $environment
     */
    private static function runsOnGithubActions(array $environment, string $osFamily): bool
    {
        $present = array_filter(
            self::RUNNER_TRIGGER_MARKERS,
            fn (string $marker) => self::value($environment, $marker) !== '',
        );

        if ($present === []) {
            return false;
        }

        $missing = array_filter(
            self::RUNNER_REQUIRED_MARKERS,
            fn (string $marker) => self::value($environment, $marker) === '',
        );

        $consistent = $missing === []
            && self::value($environment, 'GITHUB_ACTIONS') === 'true'
            && preg_match('/^[1-9][0-9]*\z/', self::value($environment, 'GITHUB_RUN_ID')) === 1
            && self::value($environment, 'RUNNER_OS') === 'Linux'
            && $osFamily === 'Linux';

        if (! $consistent) {
            throw new InvalidArgumentException('GitHub Actions markers are present but incomplete or inconsistent with this machine; refusing to guess whether this is CI or a local run');
        }

        return true;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{0: string, 1: string, 2: string, 3: string} host, port, username, password
     */
    private static function connectionFields(array $environment, bool $ci): array
    {
        $dedicated = [];
        foreach (self::CONNECTION_VARIABLES as $field) {
            $dedicated[$field] = self::value($environment, 'BIZFLOW_MYSQL_SCRATCH_'.$field);
        }

        if (array_filter($dedicated, fn (string $value) => $value !== '') !== []) {
            $source = 'BIZFLOW_MYSQL_SCRATCH_';
            $fields = $dedicated;
        } elseif ($ci) {
            // The existing CI job configures its disposable service container through DB_*.
            $source = 'DB_';
            $fields = [];
            foreach (self::CONNECTION_VARIABLES as $field) {
                $fields[$field] = self::value($environment, 'DB_'.$field);
            }
        } else {
            $source = 'BIZFLOW_MYSQL_SCRATCH_';
            $fields = $dedicated;
        }

        $missing = [];
        foreach ($fields as $field => $value) {
            if ($value === '') {
                $missing[] = $source.$field;
            }
        }

        if ($missing !== []) {
            throw new InvalidArgumentException('missing scratch connection settings: '.implode(', ', $missing));
        }

        return [$fields['HOST'], $fields['PORT'], $fields['USERNAME'], $fields['PASSWORD']];
    }

    private static function port(string $port, bool $ci): int
    {
        if (preg_match('/^[1-9][0-9]{0,4}\z/', $port) !== 1 || (int) $port > 65535) {
            throw new InvalidArgumentException('the scratch port must be a plain port number');
        }

        $number = (int) $port;

        if (in_array($number, self::FORBIDDEN_PORTS, true)) {
            throw new InvalidArgumentException("port {$number} belongs to another MySQL service and is never permitted");
        }

        if ($ci) {
            if ($number !== self::CI_PORT) {
                throw new InvalidArgumentException('in GitHub Actions the scratch port must be '.self::CI_PORT);
            }

            return $number;
        }

        if ($number === self::CI_PORT) {
            throw new InvalidArgumentException('port '.self::CI_PORT.' is the work MySQL instance and is not permitted locally; use '.self::LOCAL_PORT);
        }

        if ($number !== self::LOCAL_PORT) {
            throw new InvalidArgumentException('locally the scratch port must be '.self::LOCAL_PORT);
        }

        return $number;
    }

    /**
     * @param  array<string, string>  $environment
     */
    private static function value(array $environment, string $key): string
    {
        $value = $environment[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
