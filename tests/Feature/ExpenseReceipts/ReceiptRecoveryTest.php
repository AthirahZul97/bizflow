<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Enums\ExpenseReceiptStatus;
use App\Exceptions\EntitlementException;
use App\Exceptions\ExpenseReceiptException;
use App\Jobs\ProcessExpenseReceipt;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\Exceptions\OcrTransientException;
use App\Ocr\NormalizedReceipt;
use App\Ocr\ReceiptExtractionNormalizer;
use App\Services\ExpenseReceiptService;
use App\Services\ReceiptFileInspector;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\Support\ScriptedOcrProvider;
use Tests\TestCase;

/**
 * Recovery of receipts whose OCR run did not finish: a worker that died, a job redelivered
 * inside the stale window, a job that was lost, failures while saving or recovering, workers
 * whose claim was superseded, and the terminal outcome when the attempts are used up.
 *
 * These run on SQLite and prove the state machine, not MySQL locking: the concurrent recovery of
 * the same receipt by separate processes is tests/Mysql/ReceiptRecoveryMysqlTest.
 */
class ReceiptRecoveryTest extends TestCase
{
    use HandlesReceipts;
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReceipts();
        $this->user = User::factory()->create();
    }

    private function service(): ExpenseReceiptService
    {
        return app(ExpenseReceiptService::class);
    }

    private function stale(): int
    {
        return (int) config('ocr.stale_processing_seconds');
    }

    private function idle(): int
    {
        return (int) config('ocr.redispatch_after_seconds');
    }

    private function used(): int
    {
        return app(EntitlementService::class)->fresh($this->businessOf($this->user))->used(Entitlement::ReceiptOcr);
    }

    private function normalized(): NormalizedReceipt
    {
        return (new ReceiptExtractionNormalizer)->normalize(ScriptedOcrProvider::extraction());
    }

    /**
     * How many OCR jobs for this receipt have been queued (the upload counts as one).
     */
    private function dispatchedFor(ExpenseReceipt $receipt): int
    {
        return Queue::pushed(ProcessExpenseReceipt::class, fn (ProcessExpenseReceipt $job) => $job->expenseReceiptId === $receipt->getKey())->count();
    }

    /**
     * A receipt a worker claimed and then died on: "processing", attempt 1, nothing else happens.
     */
    private function stuckProcessing(): ExpenseReceipt
    {
        $receipt = $this->uploadFor($this->user);
        $this->assertNotNull($this->service()->claim($receipt->getKey()));

        return $receipt->refresh();
    }

    /**
     * Replace the service with one that fails the named methods and/or intercepts dispatching,
     * the way an unavailable database or queue would.
     *
     * @param  list<string>  $failing  markExtracted, markForRetry, markFailed
     * @param  (Closure(int, Closure): void)|null  $onDispatch  Gets the receipt id and a closure that really dispatches.
     */
    private function bindService(array $failing = [], ?Closure $onDispatch = null): ExpenseReceiptService
    {
        $service = new class(app(EntitlementService::class), app(ReceiptFileInspector::class), $failing, $onDispatch) extends ExpenseReceiptService
        {
            /**
             * @param  list<string>  $failing
             */
            public function __construct(EntitlementService $entitlements, ReceiptFileInspector $inspector, private readonly array $failing, private readonly ?Closure $onDispatch)
            {
                parent::__construct($entitlements, $inspector);
            }

            public function markExtracted(ExpenseReceipt $receipt, NormalizedReceipt $result): bool
            {
                $this->failIf('markExtracted');

                return parent::markExtracted($receipt, $result);
            }

            public function markForRetry(ExpenseReceipt $receipt, string $error): bool
            {
                $this->failIf('markForRetry');

                return parent::markForRetry($receipt, $error);
            }

            public function markFailed(int $receiptId, string $error, ?int $attempt = null): bool
            {
                $this->failIf('markFailed');

                return parent::markFailed($receiptId, $error, $attempt);
            }

            protected function dispatchProcessing(int $receiptId): void
            {
                if ($this->onDispatch === null) {
                    parent::dispatchProcessing($receiptId);

                    return;
                }

                ($this->onDispatch)($receiptId, fn () => parent::dispatchProcessing($receiptId));
            }

            private function failIf(string $method): void
            {
                if (in_array($method, $this->failing, true)) {
                    throw new RuntimeException("{$method} failed");
                }
            }
        };

        $this->app->instance(ExpenseReceiptService::class, $service);

        return $service;
    }

    private function useRealService(): void
    {
        $this->app->forgetInstance(ExpenseReceiptService::class);
    }

    // ---- queue redelivery and stale recovery ----------------------------------------------

    public function test_a_job_redelivered_inside_the_stale_window_does_nothing_and_the_sweeper_recovers_the_receipt_later(): void
    {
        $receipt = $this->stuckProcessing();
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        // The queue redelivers the job at retry_after (90s): the claim still looks live.
        $this->travel(90)->seconds();
        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame(0, $provider->calls(), 'the job ended without reading anything');
        $this->assertSame(['redispatched' => 0, 'failed' => 0, 'dispatch_errors' => 0], $this->service()->recoverStuck(), 'nothing is stale yet');

        // Once the claim is stale the sweeper puts the work back on the queue...
        $this->travel($this->stale() - 90 + 1)->seconds();
        $this->assertSame(['redispatched' => 1, 'failed' => 0, 'dispatch_errors' => 0], $this->service()->recoverStuck());
        $this->assertSame(2, $this->dispatchedFor($receipt), 'the upload and the recovery');

        // ...and that job reclaims the receipt and finishes it.
        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertSame(1, $provider->calls());
        $this->assertNotNull($receipt->counted_at);
        $this->assertSame(1, $this->used(), 'the receipt held one unit throughout');
    }

    public function test_a_stale_receipt_is_redispatched_once_and_then_throttled_until_it_would_be_stuck_again(): void
    {
        $receipt = $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();

        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(0, $this->service()->recoverStuck()['redispatched'], 'a second run does not dispatch it again');
        $this->assertSame(2, $this->dispatchedFor($receipt));

        // The dispatched job never ran (lost): after one more idle period it is tried again.
        $this->travel($this->idle() + 1)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(3, $this->dispatchedFor($receipt));
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->refresh()->status, 'the sweeper never changes a claim, claim() does');
    }

    public function test_a_live_worker_is_not_taken_over_before_the_threshold(): void
    {
        $receipt = $this->stuckProcessing();
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        $this->travel($this->stale() - 1)->seconds();

        $this->assertSame(['redispatched' => 0, 'failed' => 0, 'dispatch_errors' => 0], $this->service()->recoverStuck());
        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->status);
        $this->assertSame(1, $receipt->attempts);
        $this->assertSame(0, $provider->calls());
        $this->assertSame(1, $this->dispatchedFor($receipt), 'only the upload');
    }

    public function test_a_queued_receipt_whose_job_was_lost_is_dispatched_again(): void
    {
        $receipt = $this->uploadFor($this->user);   // the pushed job is "lost": it is never run

        $this->travel($this->idle() - 1)->seconds();
        $this->assertSame(0, $this->service()->recoverStuck()['redispatched'], 'a normal queue delay is not a lost job');

        $this->travel(2)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->refresh()->status);

        $this->runJob($receipt);

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertSame(1, $receipt->attempts);
    }

    // ---- failures while saving and while recovering ----------------------------------------

    public function test_a_failure_to_save_the_result_puts_the_receipt_back_in_the_queue_and_rethrows(): void
    {
        $this->bindService(['markExtracted']);
        $receipt = $this->uploadFor($this->user);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        try {
            $this->runJob($receipt, $provider);
            $this->fail('the job must fail so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertSame('markExtracted failed', $e->getMessage());
        }

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->status, 'recoverable, not stuck in processing');
        $this->assertSame(1, $receipt->attempts);
        $this->assertNotNull($receipt->counted_at);
        $this->assertNotNull($receipt->last_error);

        $this->useRealService();
        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->attempts);
    }

    public function test_when_recovery_itself_fails_the_original_exception_surfaces_and_the_sweeper_finishes_the_job(): void
    {
        Exceptions::fake();
        $this->bindService(['markExtracted', 'markForRetry']);
        $receipt = $this->uploadFor($this->user);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);

        try {
            $this->runJob($receipt, $provider);
            $this->fail('the job must fail so the queue retries it');
        } catch (RuntimeException $e) {
            $this->assertSame('markExtracted failed', $e->getMessage(), 'the recovery failure does not replace the original');
        }

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'markForRetry failed');
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->refresh()->status);

        // Not permanently stuck: after the threshold the sweeper dispatches it and it completes.
        $this->useRealService();
        $this->travel($this->stale() + 1)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->runJob($receipt, $provider);

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
    }

    public function test_a_permanent_failure_that_cannot_be_recorded_still_surfaces_and_is_recoverable(): void
    {
        Exceptions::fake();
        $this->bindService(['markFailed']);
        $receipt = $this->uploadFor($this->user);

        try {
            $this->runJob($receipt, new ScriptedOcrProvider([new OcrPermanentException('The provider rejected this file.')]));
            $this->fail('the job must not report success while the receipt is unrecorded');
        } catch (OcrPermanentException $e) {
            $this->assertSame('The provider rejected this file.', $e->getMessage());
        }

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'markFailed failed');
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->refresh()->status);

        $this->useRealService();
        $this->travel($this->stale() + 1)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
    }

    public function test_the_queues_failed_hook_never_throws_and_leaves_the_row_to_the_sweeper(): void
    {
        Exceptions::fake();
        $this->bindService(['markFailed']);
        $receipt = $this->uploadFor($this->user);

        (new ProcessExpenseReceipt($receipt->getKey()))->failed(new OcrTransientException('The OCR service timed out.'));

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'markFailed failed');
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->refresh()->status);

        $this->useRealService();
        $this->travel($this->idle() + 1)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
    }

    public function test_the_failed_hook_without_a_claim_never_fails_a_live_claim(): void
    {
        $receipt = $this->stuckProcessing();

        $this->assertFalse($this->service()->markFailed($receipt->getKey(), 'Not yours to fail.'));
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->refresh()->status);
        $this->assertNotNull($receipt->counted_at);

        // The same call once the claim is stale is allowed: nobody is running it.
        $this->travel($this->stale() + 1)->seconds();
        $this->assertTrue($this->service()->markFailed($receipt->getKey(), 'The attempts ran out.'));
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
        $this->assertNull($receipt->counted_at);
    }

    // ---- ownership: a superseded worker ------------------------------------------------------

    public function test_a_superseded_worker_cannot_write_success_retry_or_failure(): void
    {
        $receipt = $this->uploadFor($this->user);
        $old = $this->service()->claim($receipt->getKey());
        $this->travel($this->stale() + 1)->seconds();
        $new = $this->service()->claim($receipt->getKey());

        $this->assertSame(1, $old->attempts);
        $this->assertSame(2, $new->attempts);

        // The old worker finishes late, in every way it can.
        $this->assertFalse($this->service()->markExtracted($old, $this->normalized()));
        $this->assertFalse($this->service()->markForRetry($old, 'late retry'));
        $this->assertFalse($this->service()->markFailed($old->getKey(), 'late failure', $old->attempts));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Processing, $receipt->status, 'the newer attempt is untouched');
        $this->assertSame(2, $receipt->attempts);
        $this->assertNotNull($receipt->counted_at, 'its quota unit is untouched');
        $this->assertNull($receipt->extraction);
        $this->assertNull($receipt->last_error);

        // The current worker is the one that can finish it.
        $this->assertTrue($this->service()->markExtracted($new, $this->normalized()));
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
    }

    public function test_a_superseded_worker_cannot_change_a_finished_receipt_or_its_expense(): void
    {
        $receipt = $this->uploadFor($this->user);
        $old = $this->service()->claim($receipt->getKey());
        $this->travel($this->stale() + 1)->seconds();
        $new = $this->service()->claim($receipt->getKey());
        $this->service()->markExtracted($new, $this->normalized());

        [$expense, $created] = $this->service()->confirm($this->businessOf($this->user), $receipt->refresh(), $this->user, $this->confirmPayload());
        $this->assertTrue($created);

        $this->assertFalse($this->service()->markExtracted($old, $this->normalized()));
        $this->assertFalse($this->service()->markForRetry($old, 'late'));
        $this->assertFalse($this->service()->markFailed($old->getKey(), 'late', $old->attempts));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
        $this->assertSame($expense->getKey(), $receipt->expense_id);
        $this->assertSame(1, Expense::count());
        $this->assertNotNull($receipt->counted_at);

        // Confirming again stays idempotent.
        [$again, $createdAgain] = $this->service()->confirm($this->businessOf($this->user), $receipt, $this->user, $this->confirmPayload(['amount' => '1.00']));
        $this->assertFalse($createdAgain);
        $this->assertTrue($again->is($expense));
    }

    // ---- concurrent recovery ----------------------------------------------------------------

    public function test_a_second_sweeper_running_between_the_win_and_the_dispatch_dispatches_nothing(): void
    {
        $receipt = $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();

        $inner = null;
        $service = null;
        $service = $this->bindService([], function (int $id, Closure $dispatch) use (&$service, &$inner) {
            $inner = $service->recoverStuck();   // another sweeper, after the first took the right
            $dispatch();
        });

        $outer = $service->recoverStuck();

        $this->assertSame(['redispatched' => 1, 'failed' => 0, 'dispatch_errors' => 0], $outer);
        $this->assertSame(['redispatched' => 0, 'failed' => 0, 'dispatch_errors' => 0], $inner);
        $this->assertSame(2, $this->dispatchedFor($receipt), 'the upload and exactly one recovery');
    }

    public function test_recovery_dispatches_each_receipt_once_and_a_dispatched_job_reads_it_once(): void
    {
        $receipt = $this->stuckProcessing();
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        $this->travel($this->stale() + 1)->seconds();

        $this->service()->recoverStuck();
        $this->service()->recoverStuck();

        // Even if both dispatches ran, only one worker may read it.
        $this->runJob($receipt, $provider);
        $this->runJob($receipt, $provider);

        $this->assertSame(1, $provider->calls());
        $this->assertSame(2, $receipt->refresh()->attempts);
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->status);
    }

    // ---- a crash between choosing the work and dispatching it --------------------------------

    public function test_a_failed_dispatch_hands_the_right_back_so_the_next_run_retries_at_once(): void
    {
        Exceptions::fake();
        $receipt = $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();
        $before = $receipt->refresh()->updated_at;

        $this->bindService([], function (int $id, Closure $dispatch) {
            throw new RuntimeException('the queue is down');
        });
        $result = $this->service()->recoverStuck();

        $this->assertSame(['redispatched' => 0, 'failed' => 0, 'dispatch_errors' => 1], $result);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'the queue is down');
        $this->assertTrue($before->equalTo($receipt->refresh()->updated_at), 'the dispatch right was handed back');

        $this->useRealService();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched'], 'no waiting for another idle period');
    }

    public function test_a_sweeper_that_died_after_taking_the_right_is_covered_one_idle_period_later(): void
    {
        $receipt = $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();

        // What a sweeper that crashed between its conditional update and its dispatch leaves behind.
        ExpenseReceipt::query()->whereKey($receipt->getKey())->update(['updated_at' => now()]);

        $this->assertSame(0, $this->service()->recoverStuck()['redispatched'], 'throttled: a dispatch may be in flight');
        $this->assertSame(1, $this->dispatchedFor($receipt));

        $this->travel($this->idle() + 1)->seconds();
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(2, $this->dispatchedFor($receipt));
    }

    // ---- attempt limit and the terminal state ------------------------------------------------

    public function test_a_stale_receipt_out_of_attempts_fails_for_good_and_keeps_its_file_for_manual_entry(): void
    {
        $receipt = $this->uploadFor($this->user);
        ExpenseReceipt::query()->whereKey($receipt->getKey())->update([
            'status' => ExpenseReceiptStatus::Processing->value,
            'attempts' => (int) config('ocr.max_attempts'),
            'processing_started_at' => now()->subSeconds($this->stale() + 1),
            'updated_at' => now()->subSeconds($this->stale() + 1),
        ]);
        $this->assertSame(1, $this->used());

        $result = $this->service()->recoverStuck();

        $this->assertSame(['redispatched' => 0, 'failed' => 1, 'dispatch_errors' => 0], $result);
        $this->assertSame(1, $this->dispatchedFor($receipt), 'nothing is dispatched for a receipt out of attempts');

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertStringContainsString('too many times', $receipt->last_error);
        $this->assertNull($receipt->counted_at, 'the allowance is released');
        $this->assertSame(0, $this->used());
        $this->assertTrue($receipt->hasFile(), 'the file stays for manual handling');

        // Terminal for the reader, but the user is not stuck: typing the details in works.
        $this->assertThrows(
            fn () => $this->service()->retry($this->businessOf($this->user), $receipt),
            ExpenseReceiptException::class,
            'too many times',
        );
        $this->service()->useManualEntry($this->businessOf($this->user), $receipt);
        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertTrue($receipt->isManual());
    }

    public function test_a_queued_receipt_out_of_attempts_also_fails_for_good(): void
    {
        $receipt = $this->uploadFor($this->user);
        ExpenseReceipt::query()->whereKey($receipt->getKey())->update([
            'attempts' => (int) config('ocr.max_attempts'),
            'updated_at' => now()->subSeconds($this->idle() + 1),
        ]);

        $this->assertSame(1, $this->service()->recoverStuck()['failed']);

        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
        $this->assertNull($receipt->counted_at);
    }

    public function test_repeated_worker_deaths_end_in_a_terminal_failure(): void
    {
        config(['ocr.max_attempts' => 3]);
        $receipt = $this->uploadFor($this->user);

        // Each round: a worker claims it and dies; the sweeper recovers it.
        for ($round = 1; $round <= 3; $round++) {
            $this->assertNotNull($this->service()->claim($receipt->getKey()), "round {$round}");
            $this->travel($this->stale() + 1)->seconds();
            $this->service()->recoverStuck();
        }

        $receipt->refresh();
        $this->assertSame(3, $receipt->attempts);
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status, 'the attempt cap ends the loop');
        $this->assertNull($receipt->counted_at);
        $this->assertTrue($receipt->hasFile());
    }

    // ---- quota and files through recovery ----------------------------------------------------

    public function test_recovery_keeps_the_unit_until_the_terminal_failure_releases_it(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $receipt = $this->stuckProcessing();
        $this->assertSame(1, $this->used());

        $this->travel($this->stale() + 1)->seconds();
        $this->service()->recoverStuck();

        // Recovered, not released: the single unit is still held, so no second upload fits.
        $this->assertSame(1, $this->used());
        $this->assertThrows(fn () => $this->uploadFor($this->user), EntitlementException::class, 'limit of 1');
        $this->assertTrue($receipt->refresh()->hasFile());

        // Out of attempts: terminal failure gives the unit back and an upload fits again.
        ExpenseReceipt::query()->whereKey($receipt->getKey())->update([
            'attempts' => (int) config('ocr.max_attempts'),
            'updated_at' => now()->subSeconds($this->idle() + 1),
        ]);
        $this->service()->recoverStuck();

        $this->assertSame(0, $this->used());
        $this->assertTrue($receipt->refresh()->hasFile());
        $this->uploadFor($this->user);
        $this->assertSame(1, $this->used());
    }

    public function test_a_recovered_receipt_whose_plan_lost_ocr_fails_for_good_without_calling_the_provider(): void
    {
        $receipt = $this->stuckProcessing();
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 0]);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        $this->travel($this->stale() + 1)->seconds();

        $this->service()->recoverStuck();
        $this->runJob($receipt, $provider);

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->status);
        $this->assertNull($receipt->counted_at);
        $this->assertSame(0, $provider->calls());
        $this->assertTrue($receipt->hasFile());
    }

    // ---- configuration consistency -----------------------------------------------------------

    public function test_the_timings_fit_together(): void
    {
        $job = new ProcessExpenseReceipt(1);
        $retryAfter = (int) config('queue.connections.database.retry_after');

        // An attempt is never picked up twice while it is still within its timeout.
        $this->assertLessThan($retryAfter, $job->timeout);
        // A worker that is still inside its timeout can never look stale, so it cannot be taken over.
        $this->assertGreaterThan($job->timeout, $this->stale());
        // The sweeper does not race a job the queue is about to release after its longest backoff.
        $this->assertGreaterThanOrEqual(max($job->backoff), $this->idle());
        $this->assertGreaterThan(0, (int) config('ocr.recovery_batch'));
        $this->assertGreaterThanOrEqual(1, (int) config('ocr.max_attempts'));
    }

    public function test_the_sweeper_is_scheduled_every_minute_with_a_bounded_overlap_lock(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'receipts:recover'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt, 'a crashed run must not block recovery for the default day');
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();

        $this->artisan('receipts:recover')
            ->expectsOutputToContain('Re-dispatched 1 receipt(s), failed 0 out of attempts.')
            ->assertSuccessful();

        $this->artisan('receipts:recover')
            ->expectsOutputToContain('Re-dispatched 0 receipt(s)')
            ->assertSuccessful();
    }

    public function test_a_batch_limit_bounds_one_run_and_the_next_run_continues(): void
    {
        config(['ocr.recovery_batch' => 1]);
        $one = $this->stuckProcessing();
        $two = $this->stuckProcessing();
        $this->travel($this->stale() + 1)->seconds();

        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(1, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(0, $this->service()->recoverStuck()['redispatched']);
        $this->assertSame(2, $this->dispatchedFor($one));
        $this->assertSame(2, $this->dispatchedFor($two));
    }
}
