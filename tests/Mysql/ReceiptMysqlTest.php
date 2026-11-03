<?php

namespace Tests\Mysql;

use App\Billing\EntitlementService;
use App\Enums\Entitlement;
use App\Enums\ExpenseReceiptStatus;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\Plan;
use App\Models\User;
use Database\Factories\PlanFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Feature\Billing\ManagesSubscriptions;

/**
 * MySQL 8 behaviour of Phase 2E that SQLite cannot show: two real processes racing for the last
 * unit of the monthly OCR allowance and for the same receipt's confirmation, and the plan
 * migration's versioning on a fresh database.
 *
 * Opt-in against a scratch database; see MysqlTestCase. Receipt files from the upload race go to
 * a temporary directory that is removed afterwards, never to the application's storage.
 */
class ReceiptMysqlTest extends MysqlTestCase
{
    use ManagesSubscriptions;

    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->diskRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bizflow-mysql-receipts-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if ($this->diskRoot !== '') {
            File::deleteDirectory($this->diskRoot);
        }

        parent::tearDown();
    }

    private function ocrUsed(User $owner): int
    {
        return app(EntitlementService::class)->fresh($this->businessOf($owner))->used(Entitlement::ReceiptOcr);
    }

    /**
     * @return array<string, mixed>
     */
    private function upload(int $businessId, int $userId): array
    {
        return ['action' => 'upload_receipt', 'business_id' => $businessId, 'user_id' => $userId, 'disk_root' => $this->diskRoot];
    }

    /**
     * @return array<string, mixed>
     */
    private function confirm(int $businessId, int $userId, int $receiptId, string $amount): array
    {
        return [
            'action' => 'confirm_receipt', 'business_id' => $businessId, 'user_id' => $userId, 'receipt_id' => $receiptId,
            'values' => ['expense_date' => '2026-09-20', 'category' => 'office', 'description' => 'Race '.$amount, 'amount' => $amount, 'payee' => 'Shop', 'notes' => null],
        ];
    }

    // ---- the monthly allowance ------------------------------------------------------------

    public function test_two_uploads_for_the_last_unit_let_exactly_one_through(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $this->limitTo($owner, ['expenses.ocr_monthly_max' => 2]);
        ExpenseReceipt::factory()->create(['business_id' => $business->getKey()]);   // already holds one of the two units
        $this->assertSame(1, $this->ocrUsed($owner));

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            $this->upload($business->getKey(), $owner->getKey()),
            $this->upload($business->getKey(), $owner->getKey()),
        ]);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['created', 'denied'], $statuses, json_encode($outcomes));
        $this->assertSame(2, $this->ocrUsed($owner), 'the allowance is used exactly up to the limit, never past it');
        $this->assertSame(2, $business->expenseReceipts()->count(), 'the refused upload left no row');
        $this->assertSame(2, $business->expenseReceipts()->whereNotNull('counted_at')->count());

        $uploaded = $business->expenseReceipts()->where('status', ExpenseReceiptStatus::Queued->value)->whereNotNull('uploaded_by')->get();
        $this->assertCount(1, $uploaded);
        $this->assertSame(ExpenseReceiptStatus::Queued, $uploaded->first()->status, 'no corrupted state: it is a queued receipt');
        $this->assertCount(1, File::allFiles($this->diskRoot), 'the refused upload left no file behind');
    }

    public function test_uploads_for_a_business_with_room_all_succeed_and_are_all_counted(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $this->limitTo($owner, ['expenses.ocr_monthly_max' => 5]);

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            $this->upload($business->getKey(), $owner->getKey()),
            $this->upload($business->getKey(), $owner->getKey()),
            $this->upload($business->getKey(), $owner->getKey()),
        ]);

        $this->assertSame(['created', 'created', 'created'], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame(3, $this->ocrUsed($owner), 'three uploads, three units: nothing counted twice or lost');
        $this->assertCount(3, File::allFiles($this->diskRoot));
    }

    public function test_one_business_never_waits_for_anothers_allowance_lock(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->limitTo($a, ['expenses.ocr_monthly_max' => 1]);
        $this->limitTo($b, ['expenses.ocr_monthly_max' => 1]);
        $other = $this->businessOf($b);

        $outcomes = $this->raceBehindBusinessLock($this->businessOf($a)->getKey(), [$this->upload($other->getKey(), $b->getKey())], null, 3000);

        $this->assertSame('created', $outcomes[0]['status'], json_encode($outcomes));
        $this->assertLessThan(2.0, $outcomes[0]['seconds'], 'business B must not queue behind business A\'s lock');
    }

    // ---- confirming ---------------------------------------------------------------------------

    public function test_two_confirmations_of_one_receipt_create_exactly_one_expense(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $receipt = ExpenseReceipt::factory()->inReview()->create(['business_id' => $business->getKey()]);

        $outcomes = $this->raceBehindRowLock('expense_receipts', $receipt->getKey(), [
            $this->confirm($business->getKey(), $owner->getKey(), $receipt->getKey(), '10.00'),
            $this->confirm($business->getKey(), $owner->getKey(), $receipt->getKey(), '20.00'),
        ]);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['created', 'existing'], $statuses, json_encode($outcomes));

        $this->assertSame(1, $business->expenses()->count(), 'exactly one expense');
        $expense = $business->expenses()->sole();
        $receipt->refresh();
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->status);
        $this->assertSame($expense->getKey(), $receipt->expense_id);
        $this->assertSame($owner->getKey(), $receipt->confirmed_by);
        $this->assertNotNull($receipt->confirmed_at);
        $this->assertSame(1, $business->expenseReceipts()->where('expense_id', $expense->getKey())->count());

        // The winner's values are the ones stored, and the loser's are not.
        $winner = $outcomes[0]['status'] === 'created' ? '10.00' : '20.00';
        $this->assertSame($winner, $expense->amount);
        $this->assertSame(1, Expense::query()->count(), 'no second expense anywhere');
    }

    public function test_the_database_refuses_two_receipts_for_one_expense(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $expense = Expense::factory()->create(['business_id' => $business->getKey()]);
        ExpenseReceipt::factory()->create(['business_id' => $business->getKey(), 'expense_id' => $expense->getKey()]);

        try {
            ExpenseReceipt::factory()->create(['business_id' => $business->getKey(), 'expense_id' => $expense->getKey()]);
            $this->fail('the unique expense_id should refuse a second receipt');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) ($e->errorInfo[1] ?? 0), 'MySQL duplicate entry');
        }
    }

    public function test_deleting_an_expense_keeps_its_receipt_and_clears_the_link(): void
    {
        $owner = User::factory()->create();
        $business = $this->businessOf($owner);
        $expense = Expense::factory()->create(['business_id' => $business->getKey()]);
        $receipt = ExpenseReceipt::factory()->create(['business_id' => $business->getKey(), 'expense_id' => $expense->getKey(), 'status' => ExpenseReceiptStatus::Confirmed]);

        $expense->delete();

        $this->assertNull($receipt->fresh()->expense_id);
        $this->assertSame(ExpenseReceiptStatus::Confirmed, $receipt->fresh()->status);
    }

    // ---- the plan migration -------------------------------------------------------------------

    private function ocrMigration(): object
    {
        return require base_path('database/migrations/2026_10_05_100100_add_receipt_ocr_entitlement_to_seeded_plans.php');
    }

    public function test_a_fresh_database_has_the_ocr_entitlement_on_the_base_plans(): void
    {
        $this->assertSame(3, Plan::count());
        $this->assertNull(PlanFactory::seeded('legacy')->valueOf(Entitlement::ReceiptOcr), 'Legacy is unlimited');
        $this->assertSame(20, PlanFactory::seeded('trial')->valueOf(Entitlement::ReceiptOcr));
        $this->assertSame(0, PlanFactory::seeded('free')->valueOf(Entitlement::ReceiptOcr));
        $this->assertSame(3, Plan::query()->where('is_active', true)->count());
    }

    public function test_the_migration_versions_referenced_plans_and_leaves_their_subscriptions_valid(): void
    {
        $legacyOwner = User::factory()->create();       // the factory starts it on Legacy v1
        $trialOwner = User::factory()->create();
        $freeOwner = User::factory()->create();
        $this->subscribe($trialOwner, fn ($f) => $f->state(['plan_id' => PlanFactory::seeded('trial')->getKey()]));
        $this->subscribe($freeOwner, fn ($f) => $f->state(['plan_id' => PlanFactory::seeded('free')->getKey()]));
        $v1 = [
            'legacy' => PlanFactory::seeded('legacy')->getKey(),
            'trial' => PlanFactory::seeded('trial')->getKey(),
            'free' => PlanFactory::seeded('free')->getKey(),
        ];

        try {
            // Put the plans back to how Phase 2D left them (no OCR key), then run the migration.
            foreach ($v1 as $id) {
                $entitlements = Plan::query()->findOrFail($id)->entitlements;
                unset($entitlements['expenses.ocr_monthly_max']);
                DB::table('plans')->where('id', $id)->update(['entitlements' => json_encode($entitlements)]);
            }

            $this->ocrMigration()->up();

            // Every v1 plan is referenced, so none is edited: each is retired and a v2 carries the key.
            $this->assertSame(6, Plan::count());
            foreach ($v1 as $code => $id) {
                $old = Plan::query()->findOrFail($id);
                $this->assertArrayNotHasKey('expenses.ocr_monthly_max', $old->entitlements, "{$code} v1 is immutable");
                $this->assertFalse($old->is_active, "{$code} v1 is retired");
                $this->assertSame(2, Plan::latestActive($code)->version);
            }
            $this->assertNull(Plan::latestActive('legacy')->valueOf(Entitlement::ReceiptOcr));
            $this->assertSame(20, Plan::latestActive('trial')->valueOf(Entitlement::ReceiptOcr));
            $this->assertSame(0, Plan::latestActive('free')->valueOf(Entitlement::ReceiptOcr));

            // The existing subscriptions still point at the version they started on, and still work.
            foreach ([[$legacyOwner, 'legacy'], [$trialOwner, 'trial'], [$freeOwner, 'free']] as [$owner, $code]) {
                $subscription = $this->businessOf($owner)->currentSubscription()->with('plan')->sole();
                $this->assertSame($v1[$code], $subscription->plan_id, "{$code} subscription is unchanged");
                $this->assertTrue($subscription->isCurrent());
                $this->assertTrue(app(EntitlementService::class)->fresh($this->businessOf($owner))->canWrite());
            }

            // Running it again creates nothing: the newest version of each plan already has the key.
            $this->ocrMigration()->up();
            $this->assertSame(6, Plan::count());
            $this->assertSame(3, Plan::query()->where('is_active', true)->count());
            $this->assertSame(1, Plan::query()->where('code', 'legacy')->where('version', 2)->count());
        } finally {
            $this->rebuildScratchSchema();
        }
    }

    public function test_the_migration_updates_unreferenced_plans_in_place_on_mysql(): void
    {
        try {
            DB::table('subscriptions')->delete();
            foreach (Plan::query()->get() as $plan) {
                $entitlements = $plan->entitlements;
                unset($entitlements['expenses.ocr_monthly_max']);
                DB::table('plans')->where('id', $plan->getKey())->update(['entitlements' => json_encode($entitlements)]);
            }

            $this->ocrMigration()->up();
            $this->ocrMigration()->up();

            $this->assertSame(3, Plan::count(), 'nothing referenced, so nothing versioned');
            $this->assertNull(PlanFactory::seeded('legacy')->valueOf(Entitlement::ReceiptOcr));
            $this->assertSame(20, PlanFactory::seeded('trial')->valueOf(Entitlement::ReceiptOcr));
            $this->assertSame(0, PlanFactory::seeded('free')->valueOf(Entitlement::ReceiptOcr));
        } finally {
            $this->rebuildScratchSchema();
        }
    }
}
