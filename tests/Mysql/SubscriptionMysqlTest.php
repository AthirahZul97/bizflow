<?php

namespace Tests\Mysql;

use App\Billing\EntitlementGuard;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Billing\ManagesSubscriptions;

/**
 * MySQL 8 behaviour of the commercial layer that SQLite cannot show: the unique key with
 * NULLs, foreign-key restrictions, the backfill's INSERT IGNORE, rollback and re-migration, and
 * real concurrency (separate processes, separate connections, real row locks).
 *
 * Opt-in against a scratch database; see MysqlTestCase.
 */
class SubscriptionMysqlTest extends MysqlTestCase
{
    use ManagesSubscriptions;

    private function owner(): User
    {
        return User::factory()->create();
    }

    private function mysqlError(callable $call): ?int
    {
        try {
            $call();
        } catch (QueryException $e) {
            return (int) ($e->errorInfo[1] ?? 0);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Customer $customer): array
    {
        return [
            'customer_id' => $customer->id,
            'issue_date' => '2026-09-01',
            'due_date' => '2026-10-01',
            'discount_amount' => '0',
            'tax_label' => null,
            'tax_rate' => '0',
            'notes' => null,
            'items' => [['product_id' => null, 'name' => 'Work', 'description' => null, 'unit' => 'hr', 'quantity' => '1', 'unit_price' => '100.00']],
        ];
    }

    // ---- schema ---------------------------------------------------------------------------

    public function test_the_column_types_are_what_the_design_says(): void
    {
        $type = fn (string $table, string $column) => DB::selectOne(
            'select column_type as t, is_nullable as n from information_schema.columns where table_schema = ? and table_name = ? and column_name = ?',
            [$this->scratch, $table, $column],
        );

        $this->assertSame('decimal(10,2)', $type('plans', 'price')->t);
        $this->assertSame('json', $type('plans', 'entitlements')->t);
        $this->assertSame('YES', $type('plans', 'billing_interval')->n);
        $this->assertSame('char(3)', $type('plans', 'currency')->t);
        $this->assertSame('tinyint unsigned', $type('subscriptions', 'is_current')->t);
        $this->assertSame('YES', $type('subscriptions', 'is_current')->n);
        $this->assertSame('NO', $type('subscriptions', 'business_id')->n);
        $this->assertSame('YES', $type('subscriptions', 'pending_plan_id')->n);
        $this->assertSame('timestamp', $type('subscriptions', 'current_period_ends_at')->t);
        $this->assertSame('YES', $type('subscriptions', 'current_period_ends_at')->n);
    }

    public function test_the_indexes_exist_and_the_current_key_is_unique(): void
    {
        $indexes = collect(DB::select('show index from subscriptions where non_unique = 0'))->groupBy('Key_name')
            ->map(fn ($rows) => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all())->values()->all();

        $this->assertContains(['business_id', 'is_current'], $indexes);
        $this->assertContains(['code', 'version'], collect(DB::select('show index from plans where non_unique = 0'))
            ->groupBy('Key_name')->map(fn ($rows) => $rows->sortBy('Seq_in_index')->pluck('Column_name')->all())->values()->all());
    }

    public function test_many_null_rows_are_allowed_but_a_second_current_row_is_refused(): void
    {
        $business = $this->businessOf($this->owner());
        Subscription::factory()->count(3)->for($business)->ended()->create();

        $this->assertSame(4, $business->subscriptions()->count());
        $this->assertSame(1062, $this->mysqlError(fn () => Subscription::factory()->for($business)->create()));
    }

    public function test_foreign_keys_restrict_deleting_referenced_rows(): void
    {
        $owner = $this->owner();
        $business = $this->businessOf($owner);
        $plan = Plan::factory()->monthly()->unlimited()->create();
        app(SubscriptionService::class)->activate($business, $plan);

        $this->assertSame(1451, $this->mysqlError(fn () => DB::table('plans')->where('id', $plan->getKey())->delete()));
        $this->assertSame(1451, $this->mysqlError(fn () => DB::table('businesses')->where('id', $business->getKey())->delete()));
    }

    public function test_a_pending_plan_is_protected_by_its_own_foreign_key(): void
    {
        $business = $this->businessOf($this->owner());
        $free = Plan::factory()->create(['price' => '0.00']);
        app(SubscriptionService::class)->activate($business, Plan::factory()->monthly()->unlimited()->create());
        app(SubscriptionService::class)->changePlan($business, $free);

        $this->assertSame(1451, $this->mysqlError(fn () => DB::table('plans')->where('id', $free->getKey())->delete()));
        $this->assertSame(1452, $this->mysqlError(fn () => DB::table('subscriptions')->where('business_id', $business->getKey())->where('is_current', 1)->update(['pending_plan_id' => 99999999])));
    }

    public function test_a_subscription_cannot_point_at_a_missing_plan_or_business(): void
    {
        $business = $this->businessOf($this->owner());

        $this->assertSame(1452, $this->mysqlError(fn () => DB::table('subscriptions')->insert([
            'business_id' => $business->getKey(), 'plan_id' => 99999999, 'status' => 'active', 'is_current' => null,
            'started_at' => now(), 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
        ])));
        $this->assertSame(1452, $this->mysqlError(fn () => DB::table('subscriptions')->insert([
            'business_id' => 99999999, 'plan_id' => Plan::query()->first()->getKey(), 'status' => 'active', 'is_current' => null,
            'started_at' => now(), 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
        ])));
    }

    public function test_deleting_a_user_only_nulls_the_audit_column(): void
    {
        $actor = User::factory()->withoutBusiness()->create();
        $business = $this->businessOf($this->owner());
        $row = Subscription::factory()->for($business)->ended()->create(['created_by' => $actor->getKey()]);

        $actor->delete();

        $this->assertNull($row->fresh()->created_by);
        $this->assertTrue($row->fresh()->exists);
    }

    public function test_the_stored_plan_terms_survive_a_round_trip_exactly(): void
    {
        $plan = Plan::factory()->monthly('1234567.89')->entitlements(['customers.max' => null, 'products.max' => 0, 'invoices.email' => true])->create();

        $fresh = Plan::query()->findOrFail($plan->getKey());

        $this->assertSame('1234567.89', (string) $fresh->price);
        // MySQL's JSON type does not keep object key order, so compare by key; assertSame still
        // tells null from 0 and true from 1.
        $expected = ['customers.max' => null, 'products.max' => 0, 'invoices.email' => true];
        $actual = $fresh->entitlements;
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    // ---- backfill, rollback, re-migration -------------------------------------------------

    public function test_the_backfill_is_idempotent_on_mysql_and_insert_ignore_swallows_a_duplicate(): void
    {
        $now = now();
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = DB::table('businesses')->insertGetId(['name' => "B{$i}", 'created_at' => $now, 'updated_at' => $now]);
        }
        $migration = require base_path('database/migrations/2026_10_02_100200_seed_base_plans_and_backfill_subscriptions.php');

        $migration->up();
        $migration->up();

        $this->assertSame(5, Subscription::query()->whereIn('business_id', $ids)->where('is_current', 1)->count());
        $this->assertSame(5, Subscription::query()->whereIn('business_id', $ids)->count());
        $this->assertSame(3, Plan::count());

        // A racing second backfill's duplicate is ignored, not an error.
        $legacy = Plan::query()->where('code', 'legacy')->value('id');
        DB::table('subscriptions')->insertOrIgnore([
            'business_id' => $ids[0], 'plan_id' => $legacy, 'status' => 'active', 'is_current' => 1,
            'started_at' => $now, 'cancel_at_period_end' => false, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertSame(1, Subscription::query()->where('business_id', $ids[0])->count());
    }

    public function test_the_commercial_migrations_roll_back_and_apply_again_and_the_backfill_reruns(): void
    {
        $owner = $this->owner();
        $business = $this->businessOf($owner);

        // The five newest migrations: plans, subscriptions, the seed/backfill (Phase 2D), then the
        // receipts table and the OCR entitlement (Phase 2E, which sit on top of them).
        Artisan::call('migrate:rollback', ['--step' => 5, '--force' => true, '--database' => 'mysql']);
        $this->assertFalse(Schema::hasTable('expense_receipts'));
        $this->assertFalse(Schema::hasTable('subscriptions'));
        $this->assertFalse(Schema::hasTable('plans'));
        $this->assertTrue(Schema::hasTable('businesses'), 'business data is not touched by the rollback');
        $this->assertTrue(Business::query()->whereKey($business->getKey())->exists());

        try {
            Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

            // The backfill gave the business a Legacy v1 subscription before the OCR migration ran,
            // so Legacy v1 is referenced: it is left untouched and retired, and Legacy v2 carries the
            // OCR entitlement (immutable plan versions). Trial and Free were unreferenced: edited in place.
            $this->assertSame(4, Plan::count());
            $this->assertSame(2, Plan::query()->where('code', 'legacy')->count());
            $subscription = $business->subscriptions()->with('plan')->sole();
            $this->assertTrue($subscription->plan->isLegacy());
            $this->assertSame(1, $subscription->plan->version, 'the existing subscription still points at the version it started on');
            $this->assertTrue($subscription->isCurrent());
            $this->assertTrue(Schema::hasTable('expense_receipts'));
        } finally {
            $this->rebuildScratchSchema();
        }
    }

    // ---- concurrency ----------------------------------------------------------------------

    public function test_two_customer_creates_at_the_limit_let_exactly_one_through(): void
    {
        $owner = $this->owner();
        $this->limitTo($owner, ['customers.max' => 1]);
        $business = $this->businessOf($owner);

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'create_customer', 'business_id' => $business->getKey(), 'name' => 'First'],
            ['action' => 'create_customer', 'business_id' => $business->getKey(), 'name' => 'Second'],
        ]);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['created', 'denied'], $statuses, json_encode($outcomes));
        $this->assertSame(1, $business->customers()->count());
    }

    public function test_two_product_creates_at_the_limit_let_exactly_one_through(): void
    {
        $owner = $this->owner();
        $this->limitTo($owner, ['products.max' => 1]);
        $business = $this->businessOf($owner);

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'create_product', 'business_id' => $business->getKey(), 'name' => 'First'],
            ['action' => 'create_product', 'business_id' => $business->getKey(), 'name' => 'Second'],
        ]);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['created', 'denied'], $statuses, json_encode($outcomes));
        $this->assertSame(1, $business->products()->count());
    }

    public function test_two_invoice_issues_at_the_monthly_limit_let_exactly_one_through(): void
    {
        $owner = $this->owner();
        $business = $this->businessOf($owner);
        $this->limitTo($owner, ['invoices.monthly_max' => 1]);
        $customer = Customer::factory()->ownedBy($owner)->create();
        $a = app(InvoiceService::class)->saveDraft($business, $owner, $this->invoicePayload($customer));
        $b = app(InvoiceService::class)->saveDraft($business, $owner, $this->invoicePayload($customer));

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'issue_invoice', 'business_id' => $business->getKey(), 'invoice_id' => $a->getKey()],
            ['action' => 'issue_invoice', 'business_id' => $business->getKey(), 'invoice_id' => $b->getKey()],
        ]);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['denied', 'issued'], $statuses, json_encode($outcomes));
        $this->assertSame(1, Invoice::query()->where('business_id', $business->getKey())->whereNotNull('issued_at')->count());
        $this->assertSame(['INV-00001'], Invoice::query()->where('business_id', $business->getKey())->whereNotNull('invoice_number')->pluck('invoice_number')->all());
    }

    public function test_an_allowed_answer_memoized_before_waiting_is_not_trusted_after_the_lock(): void
    {
        $owner = $this->owner();
        $this->limitTo($owner, ['customers.max' => 1]);
        $business = $this->businessOf($owner);

        // While the worker waits for the lock (it has already memoized "allowed"), another request
        // commits the one allowed customer.
        $outcomes = $this->raceBehindBusinessLock(
            $business->getKey(),
            [['action' => 'create_customer', 'business_id' => $business->getKey(), 'name' => 'Late', 'memo_first' => true]],
            function (\PDO $pdo) use ($business) {
                $pdo->exec("insert into customers (business_id, name, created_at, updated_at) values ({$business->getKey()}, 'Won the race', now(), now())");
            },
        );

        $this->assertSame('denied', $outcomes[0]['status'], json_encode($outcomes));
        $this->assertSame(['Won the race'], $business->customers()->pluck('name')->all());
    }

    public function test_a_subscription_that_ends_while_a_request_waits_is_honoured_after_the_lock(): void
    {
        $owner = $this->owner();
        $this->limitTo($owner, ['customers.max' => 10]);
        $business = $this->businessOf($owner);

        $outcomes = $this->raceBehindBusinessLock(
            $business->getKey(),
            [['action' => 'create_customer', 'business_id' => $business->getKey(), 'name' => 'Too late', 'memo_first' => true]],
            function (\PDO $pdo) use ($business) {
                $pdo->exec("update subscriptions set ended_at = now() - interval 1 hour where business_id = {$business->getKey()} and is_current = 1");
            },
        );

        $this->assertSame('denied', $outcomes[0]['status'], json_encode($outcomes));
        $this->assertStringContainsString('read-only', $outcomes[0]['message']);
        $this->assertSame(0, $business->customers()->count());
    }

    public function test_one_business_never_waits_for_anothers_lock(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $this->limitTo($a, ['customers.max' => 5]);
        $this->limitTo($b, ['customers.max' => 5]);
        $businessA = $this->businessOf($a);
        $businessB = $this->businessOf($b);

        $outcomes = $this->raceBehindBusinessLock($businessA->getKey(), [
            ['action' => 'create_customer', 'business_id' => $businessB->getKey(), 'name' => 'Not blocked'],
        ], null, 3000);

        $this->assertSame('created', $outcomes[0]['status'], json_encode($outcomes));
        $this->assertLessThan(2.0, $outcomes[0]['seconds'], 'business B must not queue behind business A\'s lock');
        $this->assertSame(1, $businessB->customers()->count());
        $this->assertSame(0, $businessA->customers()->count());
    }

    public function test_concurrent_plan_changes_leave_one_current_subscription_and_a_whole_history(): void
    {
        $owner = $this->owner();
        $business = $this->businessOf($owner);
        $planA = Plan::factory()->monthly('10.00')->unlimited()->create();
        $planB = Plan::factory()->yearly('100.00')->unlimited()->create();

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'activate', 'business_id' => $business->getKey(), 'plan_id' => $planA->getKey()],
            ['action' => 'activate', 'business_id' => $business->getKey(), 'plan_id' => $planB->getKey()],
        ]);

        $this->assertSame(['activated', 'activated'], array_column($outcomes, 'status'), json_encode($outcomes));
        $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count());
        $this->assertSame(3, $business->subscriptions()->count(), 'legacy, then the two plans, each ended by the next');
        $this->assertSame(2, $business->subscriptions()->where('status', 'replaced')->count());
    }

    public function test_two_identical_self_service_changes_end_with_one_current_row(): void
    {
        $owner = $this->owner();
        $business = $this->businessOf($owner);
        $free = $this->freePlan();
        app(SubscriptionService::class)->activate($business, Plan::factory()->monthly()->unlimited()->create());
        app(SubscriptionService::class)->changePlan($business, Plan::factory()->create(['price' => '0.00']));

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'change_plan', 'business_id' => $business->getKey(), 'plan_id' => $free->getKey()],
            ['action' => 'change_plan', 'business_id' => $business->getKey(), 'plan_id' => $free->getKey()],
        ]);

        foreach ($outcomes as $outcome) {
            $this->assertContains($outcome['status'], ['changed', 'refused'], json_encode($outcomes));
        }
        $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count());
        $this->assertSame($free->getKey(), $business->currentSubscription()->sole()->pending_plan_id);
    }

    public function test_a_renewal_racing_a_plan_change_never_corrupts_the_history(): void
    {
        foreach (range(1, 3) as $round) {
            $owner = $this->owner();
            $business = $this->businessOf($owner);
            $paid = app(SubscriptionService::class)->activate($business, Plan::factory()->monthly()->unlimited()->create());
            $free = $this->freePlan();

            $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
                ['action' => 'renew', 'business_id' => $business->getKey(), 'subscription_id' => $paid->getKey()],
                ['action' => 'activate', 'business_id' => $business->getKey(), 'plan_id' => $free->getKey()],
            ]);

            $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count(), "round {$round}: ".json_encode($outcomes));
            $this->assertSame('activated', $outcomes[1]['status'], json_encode($outcomes));
            $this->assertContains($outcomes[0]['status'], ['renewed', 'refused'], json_encode($outcomes));
            $this->assertSame($free->getKey(), $business->currentSubscription()->sole()->plan_id, 'the plan change always wins in the end');
            $this->assertSame('replaced', $paid->fresh()->status->value);
        }
    }

    public function test_a_stale_renewal_after_a_plan_change_is_refused(): void
    {
        $business = $this->businessOf($this->owner());
        $service = app(SubscriptionService::class);
        $paid = $service->activate($business, Plan::factory()->monthly()->unlimited()->create());
        $service->activate($business, $this->freePlan());

        $outcome = $this->finishWorker($this->startWorker(['action' => 'renew', 'business_id' => $business->getKey(), 'subscription_id' => $paid->getKey()]));

        $this->assertSame('refused', $outcome['status'], json_encode($outcome));
        $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count());
    }

    public function test_two_raw_current_inserts_at_once_leave_exactly_one(): void
    {
        $business = $this->businessOf($this->owner());
        Subscription::query()->where('business_id', $business->getKey())->update(['is_current' => null, 'status' => 'replaced']);
        $plan = Plan::query()->where('code', 'free')->value('id');

        $outcomes = $this->raceBehindBusinessLock($business->getKey(), [
            ['action' => 'insert_current_raw', 'business_id' => $business->getKey(), 'plan_id' => $plan],
            ['action' => 'insert_current_raw', 'business_id' => $business->getKey(), 'plan_id' => $plan],
        ], null, 1500);

        $statuses = array_column($outcomes, 'status');
        sort($statuses);
        $this->assertSame(['error', 'inserted'], $statuses, json_encode($outcomes));
        $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count());
        $this->assertStringContainsString('Duplicate entry', collect($outcomes)->firstWhere('status', 'error')['message']);
    }

    public function test_a_failing_insert_inside_the_guard_rolls_back_on_mysql(): void
    {
        $owner = $this->owner();
        $this->limitTo($owner, ['customers.max' => 5]);
        $business = $this->businessOf($owner);

        try {
            app(EntitlementGuard::class)->create($business, Entitlement::Customers, function () use ($business) {
                $business->customers()->create(['name' => 'Half done']);

                throw new LogicException('boom');
            });
        } catch (LogicException) {
        }

        $this->assertSame(0, $business->customers()->count());
        $this->assertSame(0, DB::transactionLevel());
    }
}
