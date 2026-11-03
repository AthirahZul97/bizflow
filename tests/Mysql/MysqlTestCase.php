<?php

namespace Tests\Mysql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Base class for the opt-in MySQL tests of the commercial layer.
 *
 * These tests need real connections and committed data (SQLite in memory and RefreshDatabase
 * cannot show locking), so they run only against a SCRATCH database, and only when
 * BIZFLOW_MYSQL_SCRATCH names one. They are never part of the default `php artisan test` run
 * (phpunit.xml lists only tests/Unit and tests/Feature) and never touch the `bizflow` database:
 * every guard below refuses otherwise, and the scratch database is rebuilt with migrate:fresh.
 *
 * The target (host, port, database, credentials) comes only from the dedicated scratch variables
 * in the process environment; see ScratchTarget for the rules. Local runs use a dedicated scratch
 * instance on port 3308; GitHub Actions uses its service container on 3306.
 *
 * Run (local, PowerShell; the password is typed into the shell session, never stored in a file):
 *   $env:BIZFLOW_MYSQL_SCRATCH='bizflow_stage18_scratch'; $env:BIZFLOW_MYSQL_SCRATCH_HOST='127.0.0.1'
 *   $env:BIZFLOW_MYSQL_SCRATCH_PORT='3308'; $env:BIZFLOW_MYSQL_SCRATCH_USERNAME='...'; $env:BIZFLOW_MYSQL_SCRATCH_PASSWORD='...'
 *   php artisan test tests/Mysql
 */
abstract class MysqlTestCase extends TestCase
{
    protected string $scratch = '';

    private ?ScratchTarget $target = null;

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Validated before any connection is opened; nothing below runs if it is refused.
        try {
            $target = ScratchTarget::fromEnvironment(
                ScratchTarget::processEnvironment(),
                (string) config('database.connections.mysql.database'),
            );
        } catch (InvalidArgumentException $e) {
            $this->fail('Refusing to run the MySQL tests: '.$e->getMessage());
        }

        if ($target === null) {
            $this->markTestSkipped('Set BIZFLOW_MYSQL_SCRATCH (and the other BIZFLOW_MYSQL_SCRATCH_* variables) to run the MySQL tests.');
        }

        $this->target = $target;
        $this->scratch = $target->database;

        config([
            'database.default' => 'mysql',
            'database.connections.mysql' => array_merge(config('database.connections.mysql'), $target->connectionConfig()),
        ]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        $this->assertConnectedToTarget();

        if (! self::$migrated) {
            // Scratch only (asserted above): drop everything and apply every migration.
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'mysql']);
            self::$migrated = true;
        }

        $this->resetData();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * Ask the server itself which database and port this connection reached and require the
     * validated target. Called before every destructive operation.
     */
    private function assertConnectedToTarget(): void
    {
        $this->assertNotNull($this->target, 'no validated scratch target');

        $server = DB::connection('mysql')->selectOne('select database() as name, @@port as port');

        try {
            $this->target->assertServerIdentity($server->name ?? null, $server->port ?? null);
        } catch (InvalidArgumentException $e) {
            $this->fail('Refusing to continue: '.$e->getMessage());
        }
    }

    /**
     * Drop everything and apply every migration again. For a test that changed the schema or the
     * seeded plans (a rollback, a plan migration) and must leave the scratch database as it found it.
     * Scratch only: the connected database and port are verified first.
     */
    protected function rebuildScratchSchema(): void
    {
        $this->assertConnectedToTarget();
        $this->assertStringContainsString('scratch', (string) DB::selectOne('select database() as name')->name);

        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'mysql']);
    }

    /**
     * Empty every table except the migration log and the three seeded plans.
     */
    protected function resetData(): void
    {
        $this->assertConnectedToTarget();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tables = DB::select('SHOW TABLES');

        foreach ($tables as $row) {
            $table = array_values((array) $row)[0];

            if (in_array($table, ['migrations'], true)) {
                continue;
            }

            if ($table === 'plans') {
                DB::table('plans')->whereNotIn('code', ['legacy', 'trial', 'free'])->delete();

                continue;
            }

            DB::table($table)->truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * A PDO connection of its own: a second session that can hold locks while workers wait.
     *
     * It is built only from the validated target and verified (database and server port) before
     * it is returned; a failure is reported without credentials and never falls back to the
     * application connection. See ScratchTarget::openLockSession().
     */
    protected function lockSession(): PDO
    {
        $this->assertNotNull($this->target, 'no validated scratch target');

        try {
            return $this->target->openLockSession();
        } catch (RuntimeException $e) {
            $this->fail('Refusing to continue: '.$e->getMessage());
        }
    }

    /**
     * Start a worker process (tests/Mysql/worker.php) without waiting for it.
     *
     * @param  array<string, mixed>  $args
     * @return array{process: resource, pipes: array<int, resource>}
     */
    protected function startWorker(array $args): array
    {
        $this->assertNotNull($this->target, 'no validated scratch target');

        // The worker gets the validated host, port, database and credentials explicitly (never
        // the application's DB_*), validates them again and verifies what it connected to.
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ], $this->target->environmentOverrides());

        $process = proc_open(
            self::workerCommand($args, php_ini_loaded_file()),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process, 'could not start a worker');

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * The command line that starts a worker: this PHP binary, with the same php.ini only when one
     * was loaded (CI images often load none, and php_ini_loaded_file() is then false).
     *
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    public static function workerCommand(array $args, string|false|null $ini): array
    {
        $command = [PHP_BINARY];

        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }

        return [...$command, base_path('tests/Mysql/worker.php'), json_encode($args)];
    }

    /**
     * Wait for a worker and return its JSON outcome.
     *
     * The pipes and process are closed (once) before anything is asserted, so a failed assertion
     * can never leave them for a second close. $exitCode is the worker's exit code when the caller
     * already read it from proc_get_status(), which reports it only once; proc_close() then
     * returns -1. When null the exit code is not checked.
     *
     * @param  array{process: resource, pipes: array<int, resource>}  $worker
     * @return array<string, mixed>
     */
    protected function finishWorker(array $worker, ?int $exitCode = null): array
    {
        $output = '';
        $errors = '';

        try {
            $output = (string) stream_get_contents($worker['pipes'][1]);
            $errors = (string) stream_get_contents($worker['pipes'][2]);
        } finally {
            $this->closeWorker($worker);
        }

        $decoded = json_decode(trim($output), true);
        $this->assertIsArray($decoded, "worker printed no JSON. exit code: {$exitCode} stdout: {$output} stderr: {$errors}");

        if ($exitCode !== null) {
            $this->assertSame(0, $exitCode, "worker exited with a failure code. stdout: {$output} stderr: {$errors}");
        }

        return $decoded;
    }

    /**
     * Close a worker's pipes and process. Safe to call again: a closed resource is no longer a
     * resource, so nothing is closed twice.
     *
     * @param  array{process: resource, pipes: array<int, resource>}  $worker
     */
    private function closeWorker(array $worker): void
    {
        foreach ([1, 2] as $pipe) {
            if (is_resource($worker['pipes'][$pipe])) {
                fclose($worker['pipes'][$pipe]);
            }
        }

        if (is_resource($worker['process'])) {
            proc_close($worker['process']);
        }
    }

    /**
     * Hold the business row lock in another session while the given workers start and queue up
     * behind it, then release it. Optionally run work inside the held transaction first (the
     * "other request" that is about to commit).
     *
     * @param  list<array<string, mixed>>  $workers  Worker argument arrays.
     * @param  (callable(PDO): void)|null  $beforeRelease
     * @return list<array<string, mixed>> The outcomes, in the order given.
     */
    protected function raceBehindBusinessLock(int $businessId, array $workers, ?callable $beforeRelease = null, int $holdMilliseconds = 2500): array
    {
        return $this->raceBehindRowLock('businesses', $businessId, $workers, $beforeRelease, $holdMilliseconds);
    }

    /**
     * The same race, held behind any one row's lock (for work that serializes on a row other
     * than the business, such as a receipt being confirmed). $table is always a constant
     * written in a test, never input.
     *
     * @param  list<array<string, mixed>>  $workers  Worker argument arrays.
     * @param  (callable(PDO): void)|null  $beforeRelease
     * @return list<array<string, mixed>> The outcomes, in the order given.
     */
    protected function raceBehindRowLock(string $table, int $id, array $workers, ?callable $beforeRelease = null, int $holdMilliseconds = 2500): array
    {
        $pdo = $this->lockSession();
        $pdo->beginTransaction();
        $pdo->query("select id from {$table} where id = {$id} for update")->fetchAll();

        $started = array_map(fn (array $args) => $this->startWorker($args), $workers);

        // Long enough for every worker to boot and block on the lock, short enough to keep tests quick.
        usleep($holdMilliseconds * 1000);

        if ($beforeRelease !== null) {
            $beforeRelease($pdo);
        }

        $pdo->commit();

        return array_map(fn (array $worker) => $this->finishWorker($worker), $started);
    }

    /**
     * Prove that work on one business never waits for another business's row lock.
     *
     * The business row is locked in a separate session and STAYS locked until the worker has
     * exited; only then is it released. A worker that finished (exit code 0, valid JSON) while the
     * lock was held cannot have waited for it, so no duration is measured or compared. A worker
     * still running at the deadline fails the test. The lock is always released and the worker never
     * left behind; a cleanup error never replaces the test's own failure.
     *
     * @param  array<string, mixed>  $worker  Worker arguments (for a different business).
     * @return array<string, mixed> The worker's outcome.
     */
    protected function runBesideBusinessLock(int $lockedBusinessId, array $worker, int $deadlineSeconds = 20): array
    {
        $pdo = $this->lockSession();
        $started = null;
        $failure = null;
        $cleanupError = null;

        try {
            $pdo->beginTransaction();
            $rows = $pdo->query("select id from businesses where id = {$lockedBusinessId} for update")->fetchAll(PDO::FETCH_COLUMN);

            if (count($rows) !== 1 || (int) $rows[0] !== $lockedBusinessId) {
                $this->fail("the lock session did not lock business {$lockedBusinessId}: nothing would be held");
            }

            $started = $this->startWorker($worker);
            $deadline = microtime(true) + $deadlineSeconds;

            // proc_get_status() reports the exit code only on the first call after the exit, so
            // the call that sees "not running" is the one whose code is kept.
            while (($status = proc_get_status($started['process']))['running']) {
                if (microtime(true) >= $deadline) {
                    $this->fail("the worker was still running after {$deadlineSeconds}s while the other business's lock was held: it is waiting for that lock");
                }

                usleep(50_000);
            }

            // The worker has exited and the lock is still held by $pdo.
            return $this->finishWorker($started, (int) $status['exitcode']);
        } catch (Throwable $e) {
            $failure = $e;

            throw $e;
        } finally {
            try {
                if ($started !== null && is_resource($started['process'])) {
                    // Stop a still-running worker first. If that fails, release the lock before
                    // closing: proc_close() waits for the process, which may be waiting for the lock.
                    $terminated = false;

                    try {
                        $terminated = proc_terminate($started['process']);
                    } catch (Throwable $e) {
                        $cleanupError ??= $e;
                    }

                    if (! $terminated) {
                        $this->rollBackLockSession($pdo, $cleanupError);
                    }

                    try {
                        $this->closeWorker($started);
                    } catch (Throwable $e) {
                        $cleanupError ??= $e;
                    }
                }
            } finally {
                $this->rollBackLockSession($pdo, $cleanupError);
            }

            // A cleanup problem is only reported when the test itself did not fail.
            if ($failure === null && $cleanupError !== null) {
                throw $cleanupError;
            }
        }
    }

    private function rollBackLockSession(PDO $pdo, ?Throwable &$cleanupError): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            $cleanupError ??= $e;
        }
    }
}
