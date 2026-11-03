<?php

namespace Tests\Mysql;

use App\Enums\ExpenseReceiptStatus;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Tests\Feature\Billing\ManagesSubscriptions;

/**
 * MySQL 8 behaviour of receipt recovery that SQLite cannot show: separate processes running the
 * sweeper (receipts:recover) at the same moment over the same stuck receipts, each of which must
 * be dispatched exactly once, and receipts out of attempts failed exactly once.
 *
 * Opt-in against a scratch database; see MysqlTestCase. The sweepers run as tests/Mysql/worker.php
 * processes with the queue discarded, so only their database work is exercised. The job's claim
 * under the row lock is exercised by the receipt service tests; what is shown here is that the
 * conditional "right to dispatch" update lets exactly one of several real sessions win.
 */
class ReceiptRecoveryMysqlTest extends MysqlTestCase
{
    use ManagesSubscriptions;

    /**
     * @return array<string, mixed>
     */
    private function sweep(): array
    {
        return ['action' => 'recover_receipts'];
    }

    /**
     * @return array{0: int, 1: int, 2: int} redispatched, failed, dispatch errors
     */
    private function counts(array $outcome): array
    {
        $this->assertStringStartsWith('recovered:', (string) $outcome['status'], json_encode($outcome));

        return array_map('intval', array_slice(explode(':', $outcome['status']), 1));
    }

    private function stuck(int $businessId, int $attempts, ExpenseReceiptStatus $status = ExpenseReceiptStatus::Processing): ExpenseReceipt
    {
        $old = now()->subHour();

        $receipt = ExpenseReceipt::factory()->create([
            'business_id' => $businessId,
            'status' => $status,
            'attempts' => $attempts,
            'processing_started_at' => $status === ExpenseReceiptStatus::Processing ? $old : null,
            'queued_at' => $old,
            'counted_at' => now(),
        ]);

        ExpenseReceipt::query()->whereKey($receipt->getKey())->update(['updated_at' => $old]);

        return $receipt->refresh();
    }

    public function test_concurrent_sweepers_dispatch_each_stuck_receipt_exactly_once(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $a = $this->stuck($business->getKey(), 1);
        $b = $this->stuck($business->getKey(), 2);
        $lost = $this->stuck($business->getKey(), 0, ExpenseReceiptStatus::Queued);
        $live = ExpenseReceipt::factory()->create([
            'business_id' => $business->getKey(),
            'status' => ExpenseReceiptStatus::Processing,
            'attempts' => 1,
            'processing_started_at' => now(),
            'counted_at' => now(),
        ]);

        // Hold one candidate's row so the sweepers pile up behind it and then race for all of them.
        $outcomes = $this->raceBehindRowLock('expense_receipts', $a->getKey(), [$this->sweep(), $this->sweep(), $this->sweep()]);

        $redispatched = array_sum(array_map(fn (array $o) => $this->counts($o)[0], $outcomes));
        $failed = array_sum(array_map(fn (array $o) => $this->counts($o)[1], $outcomes));
        $errors = array_sum(array_map(fn (array $o) => $this->counts($o)[2], $outcomes));

        $this->assertSame(3, $redispatched, 'three stuck receipts, each dispatched exactly once across all sweepers: '.json_encode($outcomes));
        $this->assertSame(0, $failed);
        $this->assertSame(0, $errors);

        // Only the dispatch right (updated_at) moved, and a stale owner was revoked.
        foreach ([$a, $b, $lost] as $receipt) {
            $fresh = $receipt->fresh();
            $this->assertSame($receipt->status, $fresh->status);
            $this->assertSame($receipt->attempts, $fresh->attempts, 'a recovery dispatch is not a provider attempt');
            $this->assertTrue($fresh->updated_at->gt($receipt->updated_at), 'the dispatch right was taken');

            if ($receipt->status === ExpenseReceiptStatus::Processing) {
                $this->assertNull($fresh->processing_started_at, 'the stale owner was revoked in the same update');
            }
        }

        // The live claim was never a candidate.
        $this->assertSame(ExpenseReceiptStatus::Processing, $live->fresh()->status);
        $this->assertTrue($live->updated_at->equalTo($live->fresh()->updated_at));
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(ExpenseReceipt $receipt): array
    {
        return ['action' => 'claim_receipt', 'receipt_id' => $receipt->getKey()];
    }

    /**
     * A late worker saving its result under the claim it took earlier (the receipt as it was).
     *
     * @return array<string, mixed>
     */
    private function lateExtraction(ExpenseReceipt $receipt): array
    {
        return [
            'action' => 'extract_receipt',
            'receipt_id' => $receipt->getKey(),
            'attempts' => $receipt->attempts,
            'processing_started_at' => $receipt->processing_started_at->format('Y-m-d H:i:s'),
        ];
    }

    public function test_a_sweeper_and_a_claiming_worker_racing_for_a_stale_receipt_leave_exactly_one_new_attempt(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $stale = $this->stuck($business->getKey(), 1);

        // Both block on the receipt's row; whichever the database lets through first, the other
        // must see the result of it. No sleep decides the outcome: the row lock does.
        $outcomes = $this->raceBehindRowLock('expense_receipts', $stale->getKey(), [$this->sweep(), $this->claim($stale)]);

        $this->assertSame('claimed:2', $outcomes[1]['status'], 'the worker took the stale receipt exactly once: '.json_encode($outcomes));
        [$redispatched, $failed, $errors] = $this->counts($outcomes[0]);
        $this->assertContains($redispatched, [0, 1], 'the sweeper dispatched at most once: '.json_encode($outcomes));
        $this->assertSame([0, 0], [$failed, $errors]);

        $fresh = $stale->fresh();
        $this->assertSame(ExpenseReceiptStatus::Processing, $fresh->status);
        $this->assertSame(2, $fresh->attempts, 'one provider attempt was added, never two');
        $this->assertNotNull($fresh->processing_started_at, 'the new claim is live, not revoked');
        $this->assertTrue($fresh->processing_started_at->gt(now()->subMinutes(5)));
        $this->assertNotNull($fresh->counted_at, 'the unit is still held by the live attempt');
    }

    public function test_a_late_result_and_a_sweeper_never_both_win(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $stale = $this->stuck($business->getKey(), 1);

        $outcomes = $this->raceBehindRowLock('expense_receipts', $stale->getKey(), [$this->lateExtraction($stale), $this->sweep()]);

        $saved = $outcomes[0]['status'];
        [$redispatched] = $this->counts($outcomes[1]);
        $fresh = $stale->fresh();

        if ($saved === 'extracted') {
            // The result was saved before the sweep reached the row: nothing left to recover.
            $this->assertSame(0, $redispatched, 'a finished receipt is not dispatched again: '.json_encode($outcomes));
            $this->assertSame(ExpenseReceiptStatus::Review, $fresh->status);
            $this->assertNotNull($fresh->extraction);
        } else {
            // The sweep revoked the claim first: the late result must have been refused.
            $this->assertSame('ignored', $saved, json_encode($outcomes));
            $this->assertSame(1, $redispatched, json_encode($outcomes));
            $this->assertSame(ExpenseReceiptStatus::Processing, $fresh->status);
            $this->assertNull($fresh->extraction, 'a revoked worker saved nothing');
            $this->assertNull($fresh->processing_started_at);
        }

        $this->assertSame(1, $fresh->attempts, 'neither side is a provider attempt');
        $this->assertNotNull($fresh->counted_at);
    }

    public function test_concurrent_sweeps_keep_each_business_to_its_own_receipts_and_allowance(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();
        $a = $this->businessOf($ownerA);
        $b = $this->businessOf($ownerB);

        $exhaustedA = $this->stuck($a->getKey(), (int) config('ocr.max_attempts'));
        $stuckA = $this->stuck($a->getKey(), 1);
        $exhaustedB = $this->stuck($b->getKey(), (int) config('ocr.max_attempts'), ExpenseReceiptStatus::Queued);
        $liveB = ExpenseReceipt::factory()->create([
            'business_id' => $b->getKey(),
            'status' => ExpenseReceiptStatus::Processing,
            'attempts' => 1,
            'processing_started_at' => now(),
            'counted_at' => now(),
        ]);
        $reviewedA = ExpenseReceipt::factory()->inReview()->create(['business_id' => $a->getKey()]);

        $outcomes = $this->raceBehindRowLock('expense_receipts', $stuckA->getKey(), [$this->sweep(), $this->sweep(), $this->sweep()]);

        $this->assertSame(2, array_sum(array_map(fn (array $o) => $this->counts($o)[1], $outcomes)), 'each exhausted receipt failed once: '.json_encode($outcomes));
        $this->assertSame(1, array_sum(array_map(fn (array $o) => $this->counts($o)[0], $outcomes)), 'the one recoverable receipt dispatched once');

        foreach ([$exhaustedA, $exhaustedB] as $receipt) {
            $fresh = $receipt->fresh();
            $this->assertSame(ExpenseReceiptStatus::Failed, $fresh->status);
            $this->assertNull($fresh->counted_at, 'its own unit was released');
            $this->assertSame($receipt->business_id, $fresh->business_id);
        }

        // What belongs to nobody the sweep decided about is exactly as it was.
        $this->assertSame(ExpenseReceiptStatus::Processing, $liveB->fresh()->status);
        $this->assertNotNull($liveB->fresh()->counted_at);
        $this->assertSame(ExpenseReceiptStatus::Review, $reviewedA->fresh()->status);
        $this->assertNotNull($reviewedA->fresh()->counted_at);
        $this->assertNotNull($stuckA->fresh()->counted_at);
        $this->assertSame(0, $a->expenses()->count() + $b->expenses()->count(), 'recovery never creates an expense');
    }

    public function test_concurrent_sweepers_fail_a_receipt_out_of_attempts_exactly_once(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $exhausted = $this->stuck($business->getKey(), (int) config('ocr.max_attempts'));

        $outcomes = $this->raceBehindRowLock('expense_receipts', $exhausted->getKey(), [$this->sweep(), $this->sweep()]);

        $this->assertSame(1, array_sum(array_map(fn (array $o) => $this->counts($o)[1], $outcomes)), 'failed once: '.json_encode($outcomes));
        $this->assertSame(0, array_sum(array_map(fn (array $o) => $this->counts($o)[0], $outcomes)), 'and never dispatched');

        $fresh = $exhausted->fresh();
        $this->assertSame(ExpenseReceiptStatus::Failed, $fresh->status);
        $this->assertNull($fresh->counted_at, 'the unit is released once');
        $this->assertNotNull($fresh->failed_at);
    }
}
