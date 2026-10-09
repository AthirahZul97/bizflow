<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Enums\ExpenseReceiptStatus;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Database\Factories\PlanFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\TestCase;

/**
 * receipts:prune: unconfirmed receipts older than the retention period are discarded and their
 * files deleted; confirmed receipts are never touched; it is safe to run repeatedly.
 */
class ReceiptPruneTest extends TestCase
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

    /**
     * An uploaded receipt (with its file) in the given state, uploaded $days days ago.
     */
    private function receiptAged(User $user, ExpenseReceiptStatus $status, int $days): ExpenseReceipt
    {
        $receipt = $this->uploadFor($user);
        $receipt->forceFill(['status' => $status, 'created_at' => now()->subDays($days)])->save();

        return $receipt->refresh();
    }

    private function prune(): void
    {
        $this->artisan('receipts:prune')->assertSuccessful();
    }

    /**
     * @return array<string, array{ExpenseReceiptStatus}>
     */
    public static function unconfirmedStates(): array
    {
        return [
            'queued' => [ExpenseReceiptStatus::Queued],
            'review' => [ExpenseReceiptStatus::Review],
            'failed' => [ExpenseReceiptStatus::Failed],
            'unreadable' => [ExpenseReceiptStatus::Unreadable],
        ];
    }

    #[DataProvider('unconfirmedStates')]
    public function test_an_unconfirmed_receipt_older_than_thirty_days_is_discarded_with_its_file(ExpenseReceiptStatus $status): void
    {
        $receipt = $this->receiptAged($this->user, $status, 31);

        $this->prune();

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->status);
        $this->assertNotNull($receipt->discarded_at);
        $this->assertNotNull($receipt->file_deleted_at);
        $this->assertNull($receipt->extraction);
        $this->assertFalse(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
    }

    #[DataProvider('unconfirmedStates')]
    public function test_a_younger_receipt_is_left_alone(ExpenseReceiptStatus $status): void
    {
        $receipt = $this->receiptAged($this->user, $status, 29);

        $this->prune();

        $this->assertSame($status, $receipt->refresh()->status);
        $this->assertTrue(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
    }

    public function test_the_retention_period_is_configurable(): void
    {
        config(['ocr.prune_after_days' => 7]);
        $receipt = $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 8);

        $this->prune();

        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->refresh()->status);
    }

    public function test_confirmed_receipts_and_their_files_are_never_pruned(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload());
        $receipt->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->prune();

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
        $this->assertNotNull($receipt->expense_id);
        $this->assertTrue(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
        $this->assertSame(1, Expense::count());
    }

    public function test_a_confirmed_receipt_whose_expense_was_deleted_is_kept_too(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload());
        $this->actingAs($this->user)->delete(route('expenses.destroy', Expense::sole()));
        $receipt->forceFill(['created_at' => now()->subYears(2)])->save();

        $this->prune();

        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->refresh()->status);
        $this->assertTrue($receipt->hasFile());
    }

    public function test_it_is_idempotent(): void
    {
        $old = $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 40);
        $fresh = $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 1);

        $this->prune();
        $afterFirst = $old->refresh()->only(['status', 'discarded_at', 'file_deleted_at', 'updated_at']);
        $this->artisan('receipts:prune')->expectsOutputToContain('Discarded 0')->assertSuccessful();
        $this->prune();

        $this->assertEquals($afterFirst, $old->refresh()->only(['status', 'discarded_at', 'file_deleted_at', 'updated_at']));
        $this->assertSame(ExpenseReceiptStatus::Review, $fresh->refresh()->status);
    }

    public function test_it_reports_what_it_discarded(): void
    {
        $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 40);
        $this->receiptAged($this->user, ExpenseReceiptStatus::Failed, 40);

        $this->artisan('receipts:prune')->expectsOutputToContain('Discarded 2 unconfirmed receipt(s)')->assertSuccessful();
    }

    public function test_it_finishes_a_discard_whose_file_could_not_be_deleted_earlier(): void
    {
        $receipt = $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 2);
        $receipt->forceFill(['status' => ExpenseReceiptStatus::Discarded, 'discarded_at' => now(), 'file_deleted_at' => null])->save();

        $this->prune();

        $receipt->refresh();
        $this->assertNotNull($receipt->file_deleted_at);
        $this->assertFalse(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
    }

    public function test_a_read_only_business_is_skipped_untouched(): void
    {
        $lapsed = User::factory()->create();
        $old = $this->receiptAged($lapsed, ExpenseReceiptStatus::Review, 90);
        $this->makeReadOnly($lapsed);

        $this->artisan('receipts:prune')->expectsOutputToContain('skipped')->assertSuccessful();

        $old->refresh();
        $this->assertSame(ExpenseReceiptStatus::Review, $old->status, 'read-only never deletes business data');
        $this->assertTrue($old->hasFile());

        // It catches up once the business can write again.
        $this->subscribe($lapsed, fn ($f) => $f->state(['plan_id' => PlanFactory::seeded('legacy')->getKey()]));
        $this->prune();
        $this->assertSame(ExpenseReceiptStatus::Discarded, $old->refresh()->status);
    }

    public function test_one_businesss_pruning_never_touches_anothers(): void
    {
        $other = User::factory()->create();
        $mine = $this->receiptAged($this->user, ExpenseReceiptStatus::Review, 40);
        $theirs = $this->receiptAged($other, ExpenseReceiptStatus::Review, 5);

        $this->prune();

        $this->assertSame(ExpenseReceiptStatus::Discarded, $mine->refresh()->status);
        $this->assertSame(ExpenseReceiptStatus::Review, $theirs->refresh()->status);
        $this->assertTrue($theirs->hasFile());
    }

    public function test_the_command_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'receipts:prune'));

        $this->assertNotNull($event);
        $this->assertSame('15 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
