<?php

namespace Tests\Mysql;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Base class for the opt-in MySQL tests of the commercial layer.
 *
 * These tests need real connections and committed data (SQLite in memory and RefreshDatabase
 * cannot show locking), so they run only against a SCRATCH database, and only when
 * BIZFLOW_MYSQL_SCRATCH names one. They are never part of the default `php artisan test` run
 * (phpunit.xml lists only tests/Unit and tests/Feature) and never touch the `bizflow` database:
 * every guard below refuses otherwise, and the scratch database is rebuilt with migrate:fresh.
 *
 * Run: BIZFLOW_MYSQL_SCRATCH=bizflow_phase2d_scratch php artisan test tests/Mysql
 */
abstract class MysqlTestCase extends TestCase
{
    protected string $scratch = '';

    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();

        $name = (string) (getenv('BIZFLOW_MYSQL_SCRATCH') ?: ($_ENV['BIZFLOW_MYSQL_SCRATCH'] ?? ''));

        if ($name === '') {
            $this->markTestSkipped('Set BIZFLOW_MYSQL_SCRATCH to a scratch database name to run the MySQL tests.');
        }

        $configured = (string) config('database.connections.mysql.database');
        $port = (string) config('database.connections.mysql.port');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_]*scratch[A-Za-z0-9_]*$/', $name, 'the scratch database name must contain "scratch"');
        $this->assertNotSame($configured, $name, 'refusing to use the application database');
        $this->assertSame('3306', $port, 'only the BizFlow MySQL instance (3306) may be used');

        $this->scratch = $name;
        config(['database.default' => 'mysql', 'database.connections.mysql.database' => $name]);
        DB::purge('mysql');
        DB::setDefaultConnection('mysql');

        $this->assertSame($name, DB::selectOne('select database() as name')->name);

        if (! self::$migrated) {
            // Scratch only (asserted above): drop everything and apply every migration.
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'mysql']);
            self::$migrated = true;
        }

        $this->resetData();
    }

    /**
     * Empty every table except the migration log and the three seeded plans.
     */
    protected function resetData(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (DB::select('SHOW TABLES') as $row) {
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
     */
    protected function lockSession(): PDO
    {
        $c = config('database.connections.mysql');
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$this->scratch}",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return $pdo;
    }

    /**
     * Start a worker process (tests/Mysql/worker.php) without waiting for it.
     *
     * @param  array<string, mixed>  $args
     * @return array{process: resource, pipes: array<int, resource>}
     */
    protected function startWorker(array $args): array
    {
        $env = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $this->scratch,
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
        ]);

        $process = proc_open(
            [PHP_BINARY, '-c', (string) php_ini_loaded_file(), base_path('tests/Mysql/worker.php'), json_encode($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
            $env,
        );

        $this->assertIsResource($process, 'could not start a worker');

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * Wait for a worker and return its JSON outcome.
     *
     * @param  array{process: resource, pipes: array<int, resource>}  $worker
     * @return array<string, mixed>
     */
    protected function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker['pipes'][1]);
        $errors = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        $decoded = json_decode(trim((string) $output), true);
        $this->assertIsArray($decoded, "worker printed no JSON. stdout: {$output} stderr: {$errors}");

        return $decoded;
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
        $pdo = $this->lockSession();
        $pdo->beginTransaction();
        $pdo->query("select id from businesses where id = {$businessId} for update")->fetchAll();

        $started = array_map(fn (array $args) => $this->startWorker($args), $workers);

        // Long enough for every worker to boot and block on the lock, short enough to keep tests quick.
        usleep($holdMilliseconds * 1000);

        if ($beforeRelease !== null) {
            $beforeRelease($pdo);
        }

        $pdo->commit();

        return array_map(fn (array $worker) => $this->finishWorker($worker), $started);
    }
}
