<?php

namespace Tests\Feature\Billing;

use App\Billing\Entitlements;
use App\Billing\EntitlementService;
use App\Enums\AccessState;
use App\Enums\DenyReason;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use Database\Factories\PlanFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Tests\TestCase;

class EntitlementsTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->owner = User::factory()->create();
        $this->business = $this->businessOf($this->owner);
    }

    private function entitlements(?Business $business = null): Entitlements
    {
        return app(EntitlementService::class)->fresh($business ?? $this->business);
    }

    private function customers(int $count, ?User $user = null): void
    {
        Customer::factory()->count($count)->ownedBy($user ?? $this->owner)->create();
    }

    public function test_the_legacy_plan_has_no_practical_limits(): void
    {
        $e = $this->entitlements();

        $this->assertSame(AccessState::Full, $e->access());
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertTrue($e->allows($entitlement), $entitlement->value);
            if ($entitlement->isLimit()) {
                $this->assertNull($e->limit($entitlement), $entitlement->value);
                $this->assertNull($e->remaining($entitlement));
            }
        }
        $this->customers(50);
        $this->assertTrue($e->check(Entitlement::Customers, 1000)->allowed);
    }

    public function test_a_limit_allows_up_to_and_including_the_limit(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 3]);
        $this->customers(2);

        $e = $this->entitlements();

        $this->assertTrue($e->check(Entitlement::Customers)->allowed);
        $this->assertSame(3, $e->limit(Entitlement::Customers));
        $this->assertSame(2, $e->used(Entitlement::Customers));
        $this->assertSame(1, $e->remaining(Entitlement::Customers));

        $this->customers(1);
        $reached = $this->entitlements()->check(Entitlement::Customers);

        $this->assertFalse($reached->allowed);
        $this->assertSame(DenyReason::LimitReached, $reached->reason);
        $this->assertSame(3, $reached->limit);
        $this->assertSame(3, $reached->used);
    }

    public function test_adding_several_at_once_is_checked_against_the_remaining_room(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 5]);
        $this->customers(3);

        $this->assertTrue($this->entitlements()->check(Entitlement::Customers, 2)->allowed);
        $this->assertFalse($this->entitlements()->check(Entitlement::Customers, 3)->allowed);
        $this->assertTrue($this->entitlements()->check(Entitlement::Customers, 0)->allowed);
    }

    public function test_zero_means_not_included(): void
    {
        $this->limitTo($this->owner, ['recurring_invoices.max' => 0]);

        $check = $this->entitlements()->check(Entitlement::RecurringInvoices);

        $this->assertFalse($check->allowed);
        $this->assertSame(DenyReason::NotIncluded, $check->reason);
        $this->assertFalse($this->entitlements()->allows(Entitlement::RecurringInvoices));
        $this->assertSame(0, $this->entitlements()->limit(Entitlement::RecurringInvoices));
    }

    public function test_an_entitlement_the_plan_does_not_mention_is_denied_never_allowed(): void
    {
        $plan = $this->planWith(['customers.max' => 10]);
        $this->subscribe($this->owner, fn ($f) => $f->state(['plan_id' => $plan->getKey()]));

        $e = $this->entitlements();

        foreach ([Entitlement::Products, Entitlement::InvoicesPerMonth, Entitlement::RecurringInvoices, Entitlement::TeamSeats] as $entitlement) {
            $this->assertFalse($e->allows($entitlement), $entitlement->value);
            $this->assertSame(DenyReason::NotIncluded, $e->check($entitlement)->reason);
            $this->assertSame(0, $e->limit($entitlement));
        }
        $this->assertFalse($e->allows(Entitlement::InvoiceEmail));
        $this->assertSame(DenyReason::NotIncluded, $e->check(Entitlement::InvoiceEmail)->reason);
    }

    public function test_a_flag_follows_the_plan(): void
    {
        $this->limitTo($this->owner, ['invoices.email' => false]);
        $this->assertFalse($this->entitlements()->allows(Entitlement::InvoiceEmail));

        $this->limitTo($this->owner, ['invoices.email' => true]);
        $this->assertTrue($this->entitlements()->allows(Entitlement::InvoiceEmail));
    }

    public function test_asking_a_flag_for_a_limit_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->entitlements()->limit(Entitlement::InvoiceEmail);
    }

    public function test_a_business_over_its_limit_keeps_its_records_but_cannot_add_more(): void
    {
        $this->customers(8);
        $this->limitTo($this->owner, ['customers.max' => 5]);

        $e = $this->entitlements();

        $this->assertSame(8, $e->used(Entitlement::Customers));
        $this->assertSame(0, $e->remaining(Entitlement::Customers));
        $this->assertSame(DenyReason::LimitReached, $e->check(Entitlement::Customers)->reason);
        $this->assertSame(8, $this->business->customers()->count(), 'nothing was deleted');
    }

    public function test_creation_reopens_when_usage_falls_below_the_limit(): void
    {
        $this->customers(6);
        $this->limitTo($this->owner, ['customers.max' => 5]);
        $this->assertFalse($this->entitlements()->check(Entitlement::Customers)->allowed);

        $this->business->customers()->limit(2)->get()->each->delete();
        $this->assertSame(4, $this->entitlements()->used(Entitlement::Customers));
        $this->assertTrue($this->entitlements()->check(Entitlement::Customers)->allowed);
    }

    public function test_read_only_denies_everything_even_what_the_plan_would_allow(): void
    {
        $this->makeReadOnly($this->owner);

        $e = $this->entitlements();

        $this->assertSame(AccessState::ReadOnly, $e->access());
        $this->assertFalse($e->canWrite());
        foreach (Entitlement::cases() as $entitlement) {
            $check = $e->check($entitlement);
            $this->assertFalse($check->allowed, $entitlement->value);
            $this->assertSame(DenyReason::ReadOnly, $check->reason, $entitlement->value);
            $this->assertFalse($e->allows($entitlement));
        }
    }

    public function test_grace_keeps_every_entitlement(): void
    {
        $this->lapsedPaid($this->owner, 3);

        $e = $this->entitlements();

        $this->assertSame(AccessState::Grace, $e->access());
        $this->assertTrue($e->canWrite());
        $this->assertTrue($e->allows(Entitlement::InvoiceEmail));
    }

    public function test_a_business_without_any_subscription_fails_closed_and_logs(): void
    {
        Log::spy();
        $bare = Business::factory()->withoutSubscription()->create();

        $e = $this->entitlements($bare);

        $this->assertSame(AccessState::ReadOnly, $e->access());
        $this->assertNull($e->plan());
        $this->assertFalse($e->check(Entitlement::Customers)->allowed);
        $this->assertSame(0, $e->limit(Entitlement::Customers));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_stored_status_does_not_decide_access(): void
    {
        $subscription = $this->paidWithDaysLeft($this->owner, 5);
        $subscription->forceFill(['status' => 'expired'])->save();
        $this->assertSame(AccessState::Full, $this->entitlements()->access());

        $this->travelTo('2026-12-30 12:00:00');
        $subscription->forceFill(['status' => 'active'])->save();
        $this->assertSame(AccessState::ReadOnly, $this->entitlements()->access());
    }

    public function test_a_pending_free_plan_governs_the_limits_from_the_period_end(): void
    {
        $paid = $this->planWith(['customers.max' => 100, 'invoices.email' => true], '20.00');
        $this->subscribe($this->owner, fn ($f) => $f->onPlan($paid, now()->subDays(20), now()->addDays(10)));
        $free = $this->freePlan();
        Subscription::query()->where('business_id', $this->business->getKey())->where('is_current', 1)->update(['pending_plan_id' => $free->getKey()]);

        $this->assertSame(100, $this->entitlements()->limit(Entitlement::Customers));

        $this->travelTo('2026-10-20 12:00:00');
        $after = $this->entitlements();
        $this->assertSame(10, $after->limit(Entitlement::Customers));
        $this->assertFalse($after->allows(Entitlement::InvoiceEmail));
        $this->assertSame(AccessState::Full, $after->access());
    }

    // ---- service --------------------------------------------------------------------------

    public function test_the_service_asks_the_database_once_per_request(): void
    {
        $service = app(EntitlementService::class);

        DB::enableQueryLog();
        $first = $service->for($this->business);
        $second = $service->for($this->business);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($first, $second);
        $this->assertSame(1, $queries);
    }

    public function test_the_plan_is_only_loaded_when_something_asks_for_it(): void
    {
        $service = app(EntitlementService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $e = $service->fresh($this->business);
        $e->access();
        $e->canWrite();
        $this->assertCount(1, DB::getQueryLog(), 'deciding access reads only the subscription row');

        $e->plan();
        $this->assertCount(2, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_fresh_ignores_and_replaces_the_memo(): void
    {
        $service = app(EntitlementService::class);
        $stale = $service->for($this->business);
        $this->makeReadOnly($this->owner);

        $this->assertSame(AccessState::Full, $service->for($this->business)->access());
        $this->assertSame(AccessState::ReadOnly, $service->fresh($this->business)->access());
        $this->assertSame(AccessState::ReadOnly, $service->for($this->business)->access());
        $this->assertNotSame($stale, $service->for($this->business));
    }

    public function test_forget_drops_one_business_from_the_memo(): void
    {
        $service = app(EntitlementService::class);
        $first = $service->for($this->business);

        $service->forget($this->business);

        $this->assertNotSame($first, $service->for($this->business));
    }

    public function test_a_new_request_starts_with_an_empty_memo(): void
    {
        $service = app(EntitlementService::class);
        $first = $service->for($this->business);
        $this->makeReadOnly($this->owner);

        $this->app->instance('request', Request::create('/next'));

        $this->assertSame(AccessState::ReadOnly, $service->for($this->business)->access());
        $this->assertNotSame($first, $service->for($this->business));
    }

    public function test_businesses_are_resolved_independently(): void
    {
        $other = User::factory()->create();
        $this->limitTo($this->owner, ['customers.max' => 1]);
        $this->customers(1);

        $this->assertFalse($this->entitlements()->check(Entitlement::Customers)->allowed);
        $this->assertTrue($this->entitlements($this->businessOf($other))->check(Entitlement::Customers)->allowed);
    }

    public function test_other_businesses_usage_never_counts_against_this_one(): void
    {
        $other = User::factory()->create();
        $this->limitTo($this->owner, ['customers.max' => 2]);
        $this->customers(10, $other);
        $this->customers(1);

        $this->assertSame(1, $this->entitlements()->used(Entitlement::Customers));
        $this->assertTrue($this->entitlements()->check(Entitlement::Customers)->allowed);
    }

    // ---- messages -------------------------------------------------------------------------

    public function test_denial_messages_point_to_upgrading_and_read_only_explains_itself(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 1, 'recurring_invoices.max' => 0]);
        $this->customers(1);
        $e = $this->entitlements();

        $this->assertSame('', $e->check(Entitlement::Products)->message());
        $this->assertStringContainsString('limit of 1 customers', $e->check(Entitlement::Customers)->message());
        $this->assertStringContainsString('upgrade', $e->check(Entitlement::Customers)->message());
        $this->assertStringContainsString("doesn't include recurring invoices", $e->check(Entitlement::RecurringInvoices)->message());

        $this->makeReadOnly($this->owner);
        $this->assertStringContainsString('read-only', $this->entitlements()->check(Entitlement::Customers)->message());
    }

    public function test_the_seeded_catalogue_matches_the_approved_plans(): void
    {
        $trial = PlanFactory::seeded('trial');
        $free = PlanFactory::seeded('free');
        $legacy = PlanFactory::seeded('legacy');

        $this->assertSame(14, $trial->trial_days);
        $this->assertTrue($trial->isFree() && $free->isFree() && $legacy->isFree());
        $this->assertNull($legacy->billing_interval);
        foreach (Entitlement::cases() as $entitlement) {
            $this->assertNull($legacy->valueOf($entitlement) === true ? null : $legacy->valueOf($entitlement), $entitlement->value);
        }
        $this->assertSame(0, $free->valueOf(Entitlement::RecurringInvoices));
        $this->assertFalse($free->valueOf(Entitlement::InvoiceEmail));
    }
}
