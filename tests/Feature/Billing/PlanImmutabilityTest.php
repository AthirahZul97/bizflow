<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementService;
use App\Enums\BillingInterval;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanImmutabilityTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private function referenced(): Plan
    {
        $plan = Plan::factory()->monthly('20.00')->unlimited()->create(['code' => 'frozen', 'trial_days' => 7]);
        app(SubscriptionService::class)->activate($this->businessOf(User::factory()->create()), $plan);

        return $plan->fresh();
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function commercialChanges(): array
    {
        return [
            'code' => ['code', 'renamed'],
            'version' => ['version', 9],
            'currency' => ['currency', 'USD'],
            'price' => ['price', '1.00'],
            'billing interval' => ['billing_interval', BillingInterval::Year],
            'trial length' => ['trial_days', 30],
            'entitlements' => ['entitlements', ['customers.max' => 1]],
        ];
    }

    #[DataProvider('commercialChanges')]
    public function test_a_commercial_field_of_a_referenced_plan_cannot_change(string $field, mixed $value): void
    {
        $plan = $this->referenced();
        $original = $plan->getRawOriginal($field);

        try {
            $plan->update([$field => $value]);
            $this->fail("{$field} should be frozen");
        } catch (LogicException $e) {
            $this->assertStringContainsString('create a new version', $e->getMessage());
        }

        $this->assertSame($original, $plan->fresh()->getRawOriginal($field));
    }

    #[DataProvider('commercialChanges')]
    public function test_the_same_change_is_fine_before_anything_references_the_plan(string $field, mixed $value): void
    {
        $plan = Plan::factory()->monthly('20.00')->unlimited()->create(['code' => 'draft-plan']);

        $plan->update([$field => $value]);

        $this->assertTrue($plan->fresh()->isDirty() === false);
    }

    public function test_a_plan_named_as_a_pending_downgrade_is_also_frozen(): void
    {
        $paid = Plan::factory()->monthly()->unlimited()->create();
        $business = $this->businessOf(User::factory()->create());
        $service = app(SubscriptionService::class);
        $service->activate($business, $paid);
        $free = Plan::factory()->create(['price' => '0.00']);

        $service->changePlan($business, $free);

        $this->assertThrows(fn () => $free->update(['entitlements' => ['customers.max' => 999]]), LogicException::class);
    }

    public function test_an_ended_subscription_still_freezes_the_plan(): void
    {
        $plan = Plan::factory()->monthly()->unlimited()->create();
        Subscription::factory()->for(Business::factory()->withoutSubscription()->create())
            ->state(['plan_id' => $plan->getKey()])->ended()->create();

        $this->assertThrows(fn () => $plan->update(['price' => '1.00']), LogicException::class);
    }

    public function test_saving_unchanged_values_is_not_a_change(): void
    {
        $plan = $this->referenced();

        $plan->update(['price' => '20.00', 'code' => 'frozen', 'entitlements' => $plan->entitlements]);

        $this->addToAssertionCount(1);
    }

    public function test_display_fields_and_retirement_can_change_on_a_referenced_plan(): void
    {
        $plan = $this->referenced();

        $plan->update(['name' => 'Renamed for display', 'description' => 'New blurb', 'sort_order' => 55]);
        $plan->update(['is_active' => false]);

        $fresh = $plan->fresh();
        $this->assertSame('Renamed for display', $fresh->name);
        $this->assertFalse($fresh->is_active);
        $this->assertSame('20.00', (string) $fresh->price);
    }

    public function test_a_retired_plan_keeps_serving_its_existing_subscribers(): void
    {
        $plan = Plan::factory()->monthly()->unlimited()->create();
        $business = $this->businessOf(User::factory()->create());
        app(SubscriptionService::class)->activate($business, $plan);

        $plan->update(['is_active' => false]);

        $e = app(EntitlementService::class)->fresh($business);
        $this->assertTrue($e->canWrite());
        $this->assertTrue($e->allows(Entitlement::InvoiceEmail));
    }

    public function test_a_new_version_is_a_new_row_and_leaves_old_subscribers_on_the_old_terms(): void
    {
        $v1 = Plan::factory()->monthly('10.00')->entitlements(['customers.max' => 5])->create(['code' => 'growth', 'version' => 1]);
        $business = $this->businessOf(User::factory()->create());
        app(SubscriptionService::class)->activate($business, $v1);

        $v2 = Plan::factory()->monthly('15.00')->entitlements(['customers.max' => 50])->create(['code' => 'growth', 'version' => 2]);

        $e = app(EntitlementService::class)->fresh($business);
        $this->assertSame($v1->getKey(), $e->plan()->getKey());
        $this->assertSame(5, $e->limit(Entitlement::Customers));
        $this->assertSame('10.00', (string) $v1->fresh()->price);
        $this->assertNotSame($v1->getKey(), $v2->getKey());
    }

    public function test_the_same_code_and_version_cannot_exist_twice(): void
    {
        Plan::factory()->create(['code' => 'dup', 'version' => 1]);

        $this->expectException(QueryException::class);

        Plan::factory()->create(['code' => 'dup', 'version' => 1]);
    }

    public function test_a_referenced_plan_cannot_be_deleted_through_the_model(): void
    {
        $plan = $this->referenced();

        $this->assertThrows(fn () => $plan->delete(), LogicException::class, 'retire it');
        $this->assertModelExists($plan);
    }

    public function test_the_database_also_refuses_to_delete_a_referenced_plan(): void
    {
        $plan = $this->referenced();

        $this->expectException(QueryException::class);

        DB::table('plans')->where('id', $plan->getKey())->delete();
    }

    public function test_an_unreferenced_plan_can_be_deleted(): void
    {
        $plan = Plan::factory()->create();

        $plan->delete();

        $this->assertModelMissing($plan);
    }

    public function test_the_database_also_refuses_to_delete_a_plan_only_named_as_pending(): void
    {
        $paid = Plan::factory()->monthly()->unlimited()->create();
        $free = Plan::factory()->create(['price' => '0.00']);
        $business = $this->businessOf(User::factory()->create());
        $service = app(SubscriptionService::class);
        $service->activate($business, $paid);
        $service->changePlan($business, $free);

        $this->expectException(QueryException::class);

        DB::table('plans')->where('id', $free->getKey())->delete();
    }

    public function test_deleting_a_business_with_subscriptions_is_refused_by_the_database(): void
    {
        $business = $this->businessOf(User::factory()->create());

        $this->expectException(QueryException::class);

        DB::table('businesses')->where('id', $business->getKey())->delete();
    }
}
