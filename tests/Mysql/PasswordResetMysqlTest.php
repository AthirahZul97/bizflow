<?php

namespace Tests\Mysql;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PDO;
use Throwable;

/**
 * MySQL 8 behaviour of password reset that SQLite cannot show: two submissions of the same
 * single-use link, running as separate processes with separate connections, cannot both succeed.
 *
 * Opt-in against a scratch database; see MysqlTestCase (and ScratchTarget for what it will refuse).
 * Each submission is a real HTTP request handled by tests/Mysql/worker.php, so what is exercised is
 * NewPasswordController itself: its transaction, the lock on the account's row, and the broker's
 * token check and deletion.
 *
 * How the race is made deterministic: a third session holds the account's row lock. Both workers are
 * started and must BLOCK behind it; the test waits until InnoDB itself reports both transactions in
 * LOCK WAIT on the users table (bounded by a deadline, never a sleep), and only then releases the
 * lock. So both requests are provably in flight at the same moment, before either has checked the
 * token, and the database alone decides which goes first. Seeing both wait is also the evidence that
 * the controller really does lock the row: without the lock they would not have waited.
 */
class PasswordResetMysqlTest extends MysqlTestCase
{
    private const WAIT_FOR_LOCK_WAITERS_SECONDS = 30;

    private const WAIT_FOR_EXIT_SECONDS = 30;

    private function resetArguments(User $user, string $token, string $password): array
    {
        return ['action' => 'reset_password', 'token' => $token, 'email' => $user->email, 'password' => $password];
    }

    /**
     * Transactions InnoDB reports as waiting for a lock, with the statement they are stuck on.
     *
     * @return list<string>
     */
    private function lockWaiters(PDO $pdo): array
    {
        return $pdo->query("select coalesce(trx_query, '') from information_schema.innodb_trx where trx_state = 'LOCK WAIT'")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param  array{process: resource, pipes: array<int, resource>}  $worker
     */
    private function stop(array $worker): void
    {
        try {
            if (is_resource($worker['process'])) {
                @proc_terminate($worker['process']);
            }
        } catch (Throwable) {
            //
        }

        foreach ([1, 2] as $pipe) {
            if (is_resource($worker['pipes'][$pipe] ?? null)) {
                @fclose($worker['pipes'][$pipe]);
            }
        }

        if (is_resource($worker['process'])) {
            @proc_close($worker['process']);
        }
    }

    public function test_two_concurrent_submissions_of_one_reset_link_cannot_both_succeed(): void
    {
        $user = User::factory()->create(['email' => 'concurrent@example.test', 'password' => 'original-password-1', 'remember_token' => 'original-remember']);
        $token = Password::createToken($user);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());

        $pdo = $this->lockSession();
        $workers = [];

        try {
            // Hold the account's row in a third session.
            $pdo->beginTransaction();
            $locked = $pdo->query("select id from users where id = {$user->getKey()} for update")->fetchAll(PDO::FETCH_COLUMN);
            $this->assertSame([(string) $user->getKey()], array_map('strval', $locked), 'the lock session holds the account row');

            // Two submissions of the same link, with different new passwords, started together.
            $workers = [
                $this->startWorker($this->resetArguments($user, $token, 'first-new-password-1')),
                $this->startWorker($this->resetArguments($user, $token, 'second-new-password-2')),
            ];

            // Wait (bounded) until InnoDB says both are stuck waiting for that row.
            $deadline = microtime(true) + self::WAIT_FOR_LOCK_WAITERS_SECONDS;
            $waiters = [];

            do {
                foreach ($workers as $index => $worker) {
                    $status = proc_get_status($worker['process']);
                    $this->assertTrue($status['running'], "submission {$index} finished while the account row was locked: it does not wait for the lock, so nothing serializes two submissions");
                }

                $waiters = $this->lockWaiters($pdo);

                if (count($waiters) >= 2) {
                    break;
                }

                usleep(100_000);
            } while (microtime(true) < $deadline);

            $this->assertCount(2, $waiters, 'both submissions must be waiting for the account row before it is released: '.json_encode($waiters));
            foreach ($waiters as $statement) {
                $this->assertStringContainsString('users', $statement, 'they wait on the users table');
            }

            // Neither has changed anything yet.
            $this->assertSame(1, DB::table('password_reset_tokens')->where('email', $user->email)->count());
            $this->assertTrue(Hash::check('original-password-1', DB::table('users')->where('id', $user->getKey())->value('password')));

            // Release: the database now picks the order.
            $pdo->commit();

            // Bounded wait for both to finish.
            $deadline = microtime(true) + self::WAIT_FOR_EXIT_SECONDS;
            $exit = [];

            while (count($exit) < count($workers)) {
                foreach ($workers as $index => $worker) {
                    if (isset($exit[$index])) {
                        continue;
                    }

                    $status = proc_get_status($worker['process']);

                    if (! $status['running']) {
                        $exit[$index] = (int) $status['exitcode'];
                    }
                }

                if (microtime(true) >= $deadline) {
                    $this->fail('a submission was still running '.self::WAIT_FOR_EXIT_SECONDS.'s after the lock was released: a deadlock or a hang');
                }

                usleep(50_000);
            }

            $outcomes = [];
            foreach ($workers as $index => $worker) {
                $outcomes[$index] = $this->finishWorker($worker, $exit[$index]);
            }
            $workers = [];
        } finally {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                //
            }

            foreach ($workers as $worker) {
                $this->stop($worker);
            }
        }

        $statuses = array_map(fn (array $outcome) => $outcome['status'], $outcomes);
        sort($statuses);

        $this->assertSame(['rejected', 'reset'], $statuses, 'exactly one submission succeeds and the other is refused: '.json_encode($outcomes));

        // The winner's password is the one stored; the loser's never was.
        $winner = $outcomes[0]['status'] === 'reset' ? 'first-new-password-1' : 'second-new-password-2';
        $loser = $winner === 'first-new-password-1' ? 'second-new-password-2' : 'first-new-password-1';
        $stored = DB::table('users')->where('id', $user->getKey())->first();

        $this->assertTrue(Hash::check($winner, $stored->password), 'the winning password is stored');
        $this->assertFalse(Hash::check($loser, $stored->password), 'the refused submission changed nothing');
        $this->assertFalse(Hash::check('original-password-1', $stored->password));
        $this->assertNotSame('original-remember', $stored->remember_token, 'the remember token was rotated once');

        // The link is spent.
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count(), 'the token was consumed');
        $this->assertSame(1, DB::table('users')->where('email', $user->email)->count());
    }

    public function test_a_spent_link_is_refused_by_a_later_submission_too(): void
    {
        $user = User::factory()->create(['email' => 'serial@example.test', 'password' => 'original-password-1']);
        $token = Password::createToken($user);

        $first = $this->finishWorker($this->startWorker($this->resetArguments($user, $token, 'first-new-password-1')));
        $second = $this->finishWorker($this->startWorker($this->resetArguments($user, $token, 'second-new-password-2')));

        $this->assertSame('reset', $first['status']);
        $this->assertSame('rejected', $second['status']);

        $stored = DB::table('users')->where('id', $user->getKey())->value('password');
        $this->assertTrue(Hash::check('first-new-password-1', $stored));
        $this->assertFalse(Hash::check('second-new-password-2', $stored));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $user->email)->count());
    }

    public function test_a_submission_for_another_account_does_not_wait_for_a_locked_one(): void
    {
        $locked = User::factory()->create(['email' => 'locked@example.test', 'password' => 'original-password-1']);
        $other = User::factory()->create(['email' => 'other@example.test', 'password' => 'original-password-1']);
        $token = Password::createToken($other);

        $pdo = $this->lockSession();
        $worker = null;

        try {
            $pdo->beginTransaction();
            $pdo->query("select id from users where id = {$locked->getKey()} for update")->fetchAll();

            $worker = $this->startWorker($this->resetArguments($other, $token, 'brand-new-password-1'));

            $deadline = microtime(true) + self::WAIT_FOR_EXIT_SECONDS;
            while (($status = proc_get_status($worker['process']))['running']) {
                if (microtime(true) >= $deadline) {
                    $this->fail('a reset for a different account was still running after '.self::WAIT_FOR_EXIT_SECONDS."s while another account's row was locked");
                }

                usleep(50_000);
            }

            $outcome = $this->finishWorker($worker, (int) $status['exitcode']);
            $worker = null;
        } finally {
            try {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                //
            }

            if ($worker !== null) {
                $this->stop($worker);
            }
        }

        $this->assertSame('reset', $outcome['status']);
        $this->assertTrue(Hash::check('brand-new-password-1', DB::table('users')->where('id', $other->getKey())->value('password')));
        $this->assertTrue(Hash::check('original-password-1', DB::table('users')->where('id', $locked->getKey())->value('password')));
    }
}
