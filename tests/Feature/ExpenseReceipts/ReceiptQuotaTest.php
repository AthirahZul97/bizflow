<?php

namespace Tests\Feature\ExpenseReceipts;

use App\Billing\EntitlementService;
use App\Billing\Meters\MonthlyReceiptOcrMeter;
use App\Enums\Entitlement;
use App\Enums\ExpenseReceiptStatus;
use App\Exceptions\EntitlementException;
use App\Exceptions\ExpenseReceiptException;
use App\Jobs\ProcessExpenseReceipt;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Plan;
use App\Models\User;
use App\Ocr\Exceptions\OcrPermanentException;
use App\Ocr\ReceiptExtraction;
use App\Services\ExpenseReceiptService;
use Database\Factories\PlanFactory;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\ManagesSubscriptions;
use Tests\Support\ScriptedOcrProvider;
use Tests\TestCase;

/**
 * The monthly OCR allowance: what counts, what releases a unit, retries, discards and the
 * placeholder plan values. Also the retry and discard actions themselves.
 */
class ReceiptQuotaTest extends TestCase
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

    private function used(?User $user = null): int
    {
        return app(EntitlementService::class)->fresh($this->businessOf($user ?? $this->user))->used(Entitlement::ReceiptOcr);
    }

    private function failReading(ExpenseReceipt $receipt): void
    {
        $this->runJob($receipt, new ScriptedOcrProvider([new OcrPermanentException('Rejected.')]));
    }

    // ---- the placeholder plan values ---------------------------------------------------------

    public function test_the_placeholder_plan_values(): void
    {
        $trial = PlanFactory::seeded('trial');
        $free = PlanFactory::seeded('free');
        $legacy = PlanFactory::seeded('legacy');

        $this->assertSame(20, $trial->valueOf(Entitlement::ReceiptOcr));
        $this->assertSame(0, $free->valueOf(Entitlement::ReceiptOcr));
        $this->assertNull($legacy->valueOf(Entitlement::ReceiptOcr), 'Legacy is unlimited (a development placeholder), never denied by assumption');
        $this->assertArrayHasKey('expenses.ocr_monthly_max', $legacy->entitlements);
    }

    public function test_a_legacy_business_can_scan_receipts(): void
    {
        $this->assertTrue($this->user->businesses()->sole()->currentSubscription->plan->isLegacy());

        $this->actingAs($this->user)->get(route('expense-receipts.create'))->assertOk();
        $this->uploadFor($this->user);

        $this->assertSame(1, $this->used());
    }

    public function test_a_new_trial_has_twenty_scans_a_month(): void
    {
        $this->subscribe($this->user, fn ($f) => $f->state(['plan_id' => PlanFactory::seeded('trial')->getKey()]));

        foreach (range(1, 20) as $ignored) {
            $this->uploadFor($this->user);
        }

        $this->assertSame(20, $this->used());
        $this->assertThrows(fn () => $this->uploadFor($this->user), EntitlementException::class, 'limit of 20');
    }

    public function test_the_free_plan_cannot_scan_receipts(): void
    {
        $this->subscribe($this->user, fn ($f) => $f->state(['plan_id' => PlanFactory::seeded('free')->getKey()]));

        $this->assertThrows(fn () => $this->uploadFor($this->user), EntitlementException::class, "doesn't include");
        $this->assertSame(0, ExpenseReceipt::count());
        $this->assertSame([], Storage::disk(ExpenseReceipt::DISK)->allFiles());
    }

    public function test_the_plan_seeder_gives_paid_placeholders_an_ocr_limit(): void
    {
        $this->seed(PlanSeeder::class);

        $paid = Plan::query()->where('price', '>', 0)->get();

        $this->assertCount(2, $paid);
        foreach ($paid as $plan) {
            $this->assertSame(100, $plan->valueOf(Entitlement::ReceiptOcr));
        }
    }

    // ---- counting -----------------------------------------------------------------------------

    public function test_the_limit_boundary_is_exact(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 2]);

        $this->uploadFor($this->user);
        $this->uploadFor($this->user);

        $this->assertThrows(fn () => $this->uploadFor($this->user), EntitlementException::class, 'limit of 2');
        $this->assertSame(2, ExpenseReceipt::count());
        $this->assertCount(2, Storage::disk(ExpenseReceipt::DISK)->allFiles(), 'a refused upload leaves no file');
        Queue::assertPushed(ProcessExpenseReceipt::class, 2);
    }

    public function test_the_limit_is_checked_fresh_not_from_the_memo(): void
    {
        $service = app(ExpenseReceiptService::class);
        $business = $this->businessOf($this->user);
        // Warm the per-request memo with a plan that allows plenty.
        app(EntitlementService::class)->for($business)->check(Entitlement::ReceiptOcr);
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $service->upload($business, $this->user, $this->upload('a.png', $this->uniquePng()));

        // The memo (if it were trusted) still says "plenty"; the real count says "full".
        $this->assertThrows(fn () => $service->upload($business, $this->user, $this->upload('b.png', $this->uniquePng())), EntitlementException::class);
        $this->assertSame(1, ExpenseReceipt::count());
    }

    public function test_the_upload_takes_the_business_row_lock_before_it_checks_or_inserts(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 5]);
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = strtolower($query->sql);
        });

        $this->uploadFor($this->user);

        $lock = collect($sql)->search(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "businesses"') && str_contains($q, 'limit 1'));
        $count = collect($sql)->search(fn ($q) => str_contains($q, 'count(*)') && str_contains($q, 'expense_receipts'));
        $insert = collect($sql)->search(fn ($q) => str_starts_with($q, 'insert into "expense_receipts"'));

        $this->assertNotFalse($lock);
        $this->assertNotFalse($count);
        $this->assertNotFalse($insert);
        $this->assertLessThan($count, $lock, 'lock the business before counting');
        $this->assertLessThan($insert, $count, 'count before inserting');
    }

    public function test_receipts_are_counted_per_business(): void
    {
        $other = User::factory()->create();
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $this->limitTo($other, ['expenses.ocr_monthly_max' => 1]);

        $this->uploadFor($this->user);
        $this->uploadFor($other);

        $this->assertSame(1, $this->used());
        $this->assertSame(1, $this->used($other));
        $this->assertSame(1, (new MonthlyReceiptOcrMeter)->used($this->businessOf($other)));
    }

    public function test_the_allowance_is_per_calendar_month_in_the_billing_timezone(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $this->travelTo('2026-10-31 23:00:00');
        $this->uploadFor($this->user);
        $this->assertThrows(fn () => $this->uploadFor($this->user), EntitlementException::class);

        $this->travelTo('2026-11-01 00:30:00');   // half an hour into a new month
        $this->assertSame(0, $this->used());
        $this->uploadFor($this->user);
        $this->assertSame(1, $this->used());
    }

    public function test_a_technical_failure_does_not_permanently_use_the_allowance(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $first = $this->uploadFor($this->user);
        $this->assertSame(1, $this->used());

        $this->failReading($first);

        $this->assertSame(ExpenseReceiptStatus::Failed, $first->refresh()->status);
        $this->assertSame(0, $this->used());
        $this->uploadFor($this->user);   // the unit came back
        $this->assertSame(1, $this->used());
    }

    public function test_a_successfully_read_receipt_counts_once_however_it_ends(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 5]);
        $read = $this->receiptInReview($this->user);
        $this->assertSame(1, $this->used());

        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $read), $this->confirmPayload());

        $this->assertSame(1, $this->used(), 'confirming uses no more allowance');
    }

    public function test_confirming_works_at_the_limit_and_uses_no_allowance(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $receipt = $this->receiptInReview($this->user);

        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload())->assertSessionHasNoErrors();

        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->refresh()->status);
        $this->assertSame(1, $this->used());
    }

    public function test_the_http_upload_at_the_limit_is_refused_with_a_message(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $this->uploadFor($this->user);

        // The page itself is closed at the limit (policy), and a forged POST is refused as well.
        $this->actingAs($this->user)->get(route('expense-receipts.create'))->assertForbidden();
        $this->actingAs($this->user)->post(route('expense-receipts.store'), ['receipt' => $this->upload()])->assertForbidden();
        $this->assertSame(1, ExpenseReceipt::count());
    }

    // ---- retry --------------------------------------------------------------------------------

    public function test_retrying_a_failed_receipt_takes_a_unit_again(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $failed = $this->uploadFor($this->user);
        $this->failReading($failed);
        $this->assertSame(0, $this->used());

        $this->actingAs($this->user)->post(route('expense-receipts.retry', $failed))->assertRedirect(route('expense-receipts.show', $failed));

        $failed->refresh();
        $this->assertSame(ExpenseReceiptStatus::Queued, $failed->status);
        $this->assertNotNull($failed->counted_at);
        $this->assertNull($failed->failed_at);
        $this->assertNull($failed->last_error);
        $this->assertSame(1, $this->used());
        Queue::assertPushed(ProcessExpenseReceipt::class, 2);
    }

    public function test_a_retry_cannot_exceed_the_limit(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $failed = $this->uploadFor($this->user);
        $this->failReading($failed);
        $this->uploadFor($this->user);   // takes the freed unit
        $this->assertSame(1, $this->used());

        $this->assertThrows(
            fn () => app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $failed),
            EntitlementException::class,
            'limit of 1',
        );

        $this->assertSame(ExpenseReceiptStatus::Failed, $failed->refresh()->status);
        $this->assertNull($failed->counted_at);
        $this->assertSame(1, $this->used());
        Queue::assertPushed(ProcessExpenseReceipt::class, 2);
    }

    public function test_retrying_an_unreadable_or_reviewed_receipt_uses_no_extra_allowance(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 2]);
        $unreadable = $this->uploadFor($this->user);
        $this->runJob($unreadable, new ScriptedOcrProvider([new ReceiptExtraction([], 'scripted')]));
        $review = $this->receiptInReview($this->user);
        $this->assertSame(2, $this->used());

        app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $unreadable);
        app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $review);

        $this->assertSame(2, $this->used(), 'both already hold their one unit');
        $this->assertSame(ExpenseReceiptStatus::Queued, $unreadable->refresh()->status);
        $this->assertSame(ExpenseReceiptStatus::Queued, $review->refresh()->status);
    }

    public function test_retrying_a_receipt_at_the_limit_that_already_holds_its_unit_is_allowed(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $review = $this->receiptInReview($this->user);

        app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $review);

        $this->assertSame(ExpenseReceiptStatus::Queued, $review->refresh()->status);
        $this->assertSame(1, $this->used());
    }

    public function test_a_retry_can_succeed_and_then_confirm(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->failReading($receipt);

        app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $receipt);
        $this->runJob($receipt->refresh());

        $this->assertSame(ExpenseReceiptStatus::Review, $receipt->refresh()->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertSame(1, $this->used());
    }

    public function test_a_second_retry_while_the_first_is_queued_is_refused(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->failReading($receipt);
        $service = app(ExpenseReceiptService::class);

        $service->retry($this->businessOf($this->user), $receipt);

        $this->assertThrows(fn () => $service->retry($this->businessOf($this->user), $receipt->fresh()), ExpenseReceiptException::class);
        Queue::assertPushed(ProcessExpenseReceipt::class, 2);   // the upload and the one retry
    }

    public function test_retries_are_bounded_by_the_attempt_cap(): void
    {
        config(['ocr.max_attempts' => 2]);
        $receipt = $this->uploadFor($this->user);
        $service = app(ExpenseReceiptService::class);
        $business = $this->businessOf($this->user);

        $this->failReading($receipt);
        $service->retry($business, $receipt);
        $this->failReading($receipt->refresh());

        $this->assertThrows(fn () => $service->retry($business, $receipt->fresh()), ExpenseReceiptException::class, 'too many times');
        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
    }

    /**
     * @return array<string, array{ExpenseReceiptStatus}>
     */
    public static function unretryableStates(): array
    {
        return [
            'queued' => [ExpenseReceiptStatus::Queued],
            'processing' => [ExpenseReceiptStatus::Processing],
            'confirmed' => [ExpenseReceiptStatus::Confirmed],
            'discarded' => [ExpenseReceiptStatus::Discarded],
        ];
    }

    #[DataProvider('unretryableStates')]
    public function test_only_failed_unreadable_or_reviewed_receipts_can_be_retried(ExpenseReceiptStatus $status): void
    {
        $receipt = ExpenseReceipt::factory()->status($status)->create(['business_id' => $this->businessOf($this->user)]);

        $this->actingAs($this->user)->post(route('expense-receipts.retry', $receipt))->assertSessionHas('error');

        $this->assertSame($status, $receipt->refresh()->status);
    }

    public function test_a_plan_without_ocr_cannot_retry(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->failReading($receipt);
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 0]);

        $this->actingAs($this->user)->post(route('expense-receipts.retry', $receipt))->assertForbidden();

        $this->assertSame(ExpenseReceiptStatus::Failed, $receipt->refresh()->status);
    }

    public function test_a_receipt_whose_file_is_gone_cannot_be_retried(): void
    {
        $receipt = $this->uploadFor($this->user);
        $this->failReading($receipt);
        Storage::disk(ExpenseReceipt::DISK)->delete($receipt->storage_path);

        $this->assertThrows(fn () => app(ExpenseReceiptService::class)->retry($this->businessOf($this->user), $receipt), ExpenseReceiptException::class, 'no longer available');
    }

    // ---- discard ------------------------------------------------------------------------------

    public function test_discarding_a_receipt_that_was_never_read_returns_its_unit_and_deletes_the_file(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $receipt = $this->uploadFor($this->user);

        $this->actingAs($this->user)->get(route('expense-receipts.delete', $receipt))->assertOk()->assertSee('permanently deleted');
        $this->assertSame(ExpenseReceiptStatus::Queued, $receipt->refresh()->status, 'the confirmation page deletes nothing');

        $this->actingAs($this->user)->delete(route('expense-receipts.destroy', $receipt))->assertRedirect(route('expense-receipts.index'));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->status);
        $this->assertNotNull($receipt->discarded_at);
        $this->assertNotNull($receipt->file_deleted_at);
        $this->assertNull($receipt->extraction);
        $this->assertNull($receipt->counted_at);
        $this->assertFalse(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
        $this->assertSame(0, $this->used());
    }

    public function test_discarding_a_read_receipt_keeps_its_unit_so_upload_discard_loops_cost_something(): void
    {
        $this->limitTo($this->user, ['expenses.ocr_monthly_max' => 1]);
        $receipt = $this->receiptInReview($this->user);

        $this->actingAs($this->user)->delete(route('expense-receipts.destroy', $receipt));

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->status);
        $this->assertNull($receipt->extraction, 'what was read is cleared');
        $this->assertNotNull($receipt->counted_at);
        $this->assertSame(1, $this->used());
    }

    public function test_a_confirmed_receipt_with_its_expense_cannot_be_discarded(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload());

        $this->actingAs($this->user)->delete(route('expense-receipts.destroy', $receipt))->assertSessionHas('error');

        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->refresh()->status);
        $this->assertTrue($receipt->hasFile());
    }

    public function test_a_confirmed_receipt_whose_expense_was_deleted_can_be_discarded(): void
    {
        $receipt = $this->receiptInReview($this->user);
        $this->actingAs($this->user)->post(route('expense-receipts.confirm', $receipt), $this->confirmPayload());
        $this->actingAs($this->user)->delete(route('expenses.destroy', Expense::sole()));

        $this->actingAs($this->user)->delete(route('expense-receipts.destroy', $receipt))->assertSessionHasNoErrors();

        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->status);
        $this->assertFalse(Storage::disk(ExpenseReceipt::DISK)->exists($receipt->storage_path));
    }

    public function test_discarding_twice_is_harmless(): void
    {
        $receipt = $this->uploadFor($this->user);
        $service = app(ExpenseReceiptService::class);
        $business = $this->businessOf($this->user);

        $service->discard($business, $receipt);

        $this->assertThrows(fn () => $service->discard($business, $receipt->fresh()), ExpenseReceiptException::class);
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->refresh()->status);
    }

    public function test_a_discarded_receipt_is_not_processed_even_if_its_job_still_runs(): void
    {
        $receipt = $this->uploadFor($this->user);
        $provider = new ScriptedOcrProvider([ScriptedOcrProvider::extraction()]);
        app(ExpenseReceiptService::class)->discard($this->businessOf($this->user), $receipt);

        $this->runJob($receipt, $provider);

        $this->assertSame(0, $provider->calls());
        $this->assertSame(ExpenseReceiptStatus::Discarded, $receipt->refresh()->status);
    }

    // ---- the plan migration ---------------------------------------------------------------------

    private function ocrMigration(): object
    {
        return require database_path('migrations/2026_10_05_100100_add_receipt_ocr_entitlement_to_seeded_plans.php');
    }

    private function stripOcrKey(): void
    {
        foreach (Plan::query()->whereIn('code', ['legacy', 'trial', 'free'])->get() as $plan) {
            $entitlements = $plan->entitlements;
            unset($entitlements['expenses.ocr_monthly_max']);
            DB::table('plans')->where('id', $plan->id)->update(['entitlements' => json_encode($entitlements)]);
        }
    }

    public function test_the_migration_updates_unreferenced_plans_in_place_and_is_idempotent(): void
    {
        DB::table('subscriptions')->delete();
        $this->stripOcrKey();
        $before = Plan::count();

        $this->ocrMigration()->up();
        $this->ocrMigration()->up();

        $this->assertSame($before, Plan::count());
        $this->assertNull(PlanFactory::seeded('legacy')->valueOf(Entitlement::ReceiptOcr));
        $this->assertSame(20, PlanFactory::seeded('trial')->valueOf(Entitlement::ReceiptOcr));
        $this->assertSame(0, PlanFactory::seeded('free')->valueOf(Entitlement::ReceiptOcr));
    }

    public function test_the_migration_never_edits_a_plan_that_subscriptions_use(): void
    {
        // $this->user's business is on Legacy v1, so Legacy is referenced.
        $this->stripOcrKey();
        $legacyV1 = PlanFactory::seeded('legacy');

        $this->ocrMigration()->up();

        $legacyV1->refresh();
        $this->assertArrayNotHasKey('expenses.ocr_monthly_max', $legacyV1->entitlements, 'a referenced plan version is immutable');
        $this->assertFalse($legacyV1->is_active, 'the old version is retired');
        $v2 = Plan::query()->where('code', 'legacy')->where('version', 2)->sole();
        $this->assertNull($v2->valueOf(Entitlement::ReceiptOcr));
        $this->assertTrue($v2->is_active);
        $this->assertSame(Plan::latestActive('legacy')->getKey(), $v2->getKey());
        // The unreferenced Trial plan was edited in place, so no second version.
        $this->assertSame(1, Plan::query()->where('code', 'trial')->count());
    }
}
