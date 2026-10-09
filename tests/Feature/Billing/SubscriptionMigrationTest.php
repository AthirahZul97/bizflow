<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementService;
use App\Billing\SubscriptionNotice;
use App\Enums\AccessState;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BusinessRegistration;
use App\Services\SubscriptionService;
use Database\Factories\PlanFactory;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SubscriptionMigrationTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_10_02_100200_seed_base_plans_and_backfill_subscriptions.php');
        $migration->up();
    }

    // ---- schema ---------------------------------------------------------------------------

    public function test_the_plans_table_has_the_approved_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'code', 'version', 'name', 'description', 'currency', 'price', 'billing_interval',
            'trial_days', 'entitlements', 'is_active', 'sort_order', 'created_at', 'updated_at',
        ], Schema::getColumnListing('plans'));
    }

    public function test_the_subscriptions_table_has_the_approved_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'business_id', 'plan_id', 'pending_plan_id', 'status', 'is_current', 'started_at',
            'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'grace_ends_at',
            'cancel_at_period_end', 'canceled_at', 'ended_at', 'end_reason', 'created_by',
            'created_at', 'updated_at',
        ], Schema::getColumnListing('subscriptions'));
    }

    public function test_no_payment_or_webhook_tables_exist_yet(): void
    {
        foreach (['payments', 'subscription_payments', 'billing_events', 'plan_entitlements', 'subscription_items', 'usage_records'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
    }

    public function test_the_unique_keys_exist(): void
    {
        $unique = fn (string $table) => collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns')->all();

        $this->assertContains(['code', 'version'], $unique('plans'));
        $this->assertContains(['business_id', 'is_current'], $unique('subscriptions'));
    }

    public function test_the_foreign_keys_point_where_they_should(): void
    {
        $targets = collect(Schema::getForeignKeys('subscriptions'))->mapWithKeys(fn ($fk) => [$fk['columns'][0] => $fk['foreign_table']])->all();

        $this->assertEquals(['business_id' => 'businesses', 'plan_id' => 'plans', 'pending_plan_id' => 'plans', 'created_by' => 'users'], $targets);
    }

    public function test_the_lookup_indexes_exist(): void
    {
        $indexes = collect(Schema::getIndexes('plans'))->pluck('columns')->all();
        $this->assertContains(['is_active', 'sort_order'], $indexes);

        $sub = collect(Schema::getIndexes('subscriptions'))->pluck('columns')->all();
        $this->assertContains(['pending_plan_id'], $sub);
        $this->assertContains(['plan_id'], $sub);
    }

    // ---- seeded plans ---------------------------------------------------------------------

    public function test_only_the_three_production_plans_are_seeded_by_the_migration(): void
    {
        $this->assertSame(['free', 'legacy', 'trial'], Plan::query()->orderBy('code')->pluck('code')->all());
        $this->assertSame(0, Plan::query()->where('price', '>', 0)->count(), 'no paid plan is seeded by a migration');
    }

    public function test_the_seeded_plans_have_the_agreed_shape(): void
    {
        $legacy = PlanFactory::seeded('legacy');
        $trial = PlanFactory::seeded('trial');
        $free = PlanFactory::seeded('free');

        $this->assertSame([1, 1, 1], [$legacy->version, $trial->version, $free->version]);
        $this->assertSame('MYR', $legacy->currency);
        $this->assertSame(14, $trial->trial_days);
        $this->assertNull($legacy->trial_days);
        $this->assertNull($free->trial_days);
        $this->assertSame([true, true, true], [$legacy->is_active, $trial->is_active, $free->is_active]);
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertSame($entitlement->isFlag() ? true : null, $legacy->valueOf($entitlement), $entitlement->value);
        }
    }

    public function test_the_development_paid_plans_come_from_the_seeder_and_say_they_are_placeholders(): void
    {
        $this->seed(PlanSeeder::class);

        $paid = Plan::query()->where('price', '>', 0)->orderBy('price')->get();

        $this->assertCount(2, $paid);
        $this->assertSame(['month', 'year'], $paid->map(fn ($p) => $p->billing_interval->value)->all());
        foreach ($paid as $plan) {
            $this->assertStringContainsString('PLACEHOLDER', $plan->description);
            $this->assertStringContainsString('Not final', $plan->description);
        }
    }

    public function test_the_plan_seeder_is_idempotent_and_never_edits_an_existing_version(): void
    {
        $this->seed(PlanSeeder::class);
        $plan = Plan::query()->where('code', 'dev-paid-monthly')->sole();
        app(SubscriptionService::class)->activate($this->businessOf(User::factory()->create()), $plan);

        $this->seed(PlanSeeder::class);

        $this->assertSame(2, Plan::query()->where('price', '>', 0)->count());
        $this->assertSame('29.00', (string) $plan->fresh()->price);
    }

    // ---- backfill -------------------------------------------------------------------------

    public function test_every_existing_business_gets_exactly_one_current_legacy_subscription(): void
    {
        $businesses = Business::factory()->withoutSubscription()->count(3)->create();

        $this->runBackfill();

        foreach ($businesses as $business) {
            $subscription = $business->subscriptions()->with('plan')->sole();
            $this->assertTrue($subscription->isCurrent());
            $this->assertTrue($subscription->plan->isLegacy());
            $this->assertSame('active', $subscription->status->value);
            $this->assertNull($subscription->trial_ends_at, 'existing businesses do not enter a trial');
            $this->assertNull($subscription->current_period_ends_at, 'no expiry');
            $this->assertNull($subscription->grace_ends_at);
            $this->assertNull($subscription->created_by);
            $this->assertFalse($subscription->cancel_at_period_end);
        }
    }

    public function test_a_backfilled_business_has_full_unlimited_access_with_no_banner(): void
    {
        $business = Business::factory()->withoutSubscription()->create();
        $this->runBackfill();

        $e = app(EntitlementService::class)->fresh($business);

        $this->assertSame(AccessState::Full, $e->access());
        $this->assertNull(SubscriptionNotice::for($e));
        $this->assertNull($e->limit(Entitlement::Customers));
    }

    public function test_the_subscription_starts_when_the_business_was_created(): void
    {
        $business = Business::factory()->withoutSubscription()->create(['created_at' => '2025-03-04 05:06:07']);

        $this->runBackfill();

        $this->assertSame('2025-03-04 05:06:07', $business->subscriptions()->sole()->started_at->toDateTimeString());
    }

    public function test_running_it_again_creates_nothing_twice(): void
    {
        Business::factory()->withoutSubscription()->count(2)->create();

        $this->runBackfill();
        $subscriptions = Subscription::count();
        $plans = Plan::count();
        $this->runBackfill();
        $this->runBackfill();

        $this->assertSame($subscriptions, Subscription::count());
        $this->assertSame($plans, Plan::count());
        $this->assertSame(3, Plan::count());
    }

    public function test_businesses_that_already_have_a_subscription_are_left_alone(): void
    {
        $business = $this->businessOf(User::factory()->create());
        $this->paidWithDaysLeft($business, 10);
        $before = $business->subscriptions()->orderBy('id')->get()->map->only(['id', 'plan_id', 'status', 'is_current'])->all();

        $this->runBackfill();

        $this->assertEquals($before, $business->subscriptions()->orderBy('id')->get()->map->only(['id', 'plan_id', 'status', 'is_current'])->all());
    }

    public function test_a_business_with_only_ended_history_is_not_given_a_second_start(): void
    {
        $business = Business::factory()->withoutSubscription()->create();
        Subscription::factory()->for($business)->ended('expired')->create();

        $this->runBackfill();

        $this->assertSame(1, $business->subscriptions()->count());
        $this->assertSame(0, $business->subscriptions()->where('is_current', 1)->count());
    }

    public function test_it_does_not_change_any_business_data(): void
    {
        $business = Business::factory()->withoutSubscription()->create(['name' => 'Untouched Co', 'email' => 'x@example.test']);
        $before = DB::table('businesses')->where('id', $business->getKey())->first();

        $this->runBackfill();

        $this->assertEquals($before, DB::table('businesses')->where('id', $business->getKey())->first());
    }

    public function test_it_handles_more_businesses_than_one_chunk(): void
    {
        $now = now();
        DB::table('businesses')->insert(array_map(fn ($i) => ['name' => "Bulk {$i}", 'created_at' => $now, 'updated_at' => $now], range(1, 250)));

        $this->runBackfill();

        $this->assertSame(0, Business::query()->whereDoesntHave('subscriptions')->count());
        $this->assertSame(250, Subscription::query()->where('is_current', 1)->whereIn('business_id', Business::query()->where('name', 'like', 'Bulk %')->select('id'))->count());
    }

    public function test_an_empty_database_creates_no_subscriptions(): void
    {
        Subscription::query()->delete();
        DB::table('business_user')->delete();
        Business::query()->delete();

        $this->runBackfill();

        $this->assertSame(0, Subscription::count());
    }

    public function test_the_database_blocks_a_duplicate_current_row_even_if_the_backfill_were_raced(): void
    {
        $business = Business::factory()->withoutSubscription()->create();
        $this->runBackfill();

        // What a concurrent second run would try; INSERT IGNORE swallows the key violation.
        DB::table('subscriptions')->insertOrIgnore([
            'business_id' => $business->getKey(), 'plan_id' => PlanFactory::seeded('legacy')->getKey(), 'status' => 'active',
            'is_current' => 1, 'started_at' => now(), 'cancel_at_period_end' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_registration_after_the_migration_starts_a_trial_not_legacy(): void
    {
        $user = app(BusinessRegistration::class)->register([
            'name' => 'New Owner', 'email' => 'new@example.test', 'password' => 'secret-pass-1', 'business_name' => 'Newly Registered',
        ]);

        $subscription = $this->businessOf($user)->subscriptions()->with('plan')->sole();

        $this->assertSame('trial', $subscription->plan->code);
        $this->assertNotNull($subscription->trial_ends_at);
    }
}
