<?php

namespace Tests\Feature\Billing;

use App\Billing\BillingProvider;
use App\Billing\BillingProviderException;
use App\Billing\CheckoutRequest;
use App\Billing\EntitlementService;
use App\Billing\ManualBillingProvider;
use App\Enums\AccessState;
use App\Models\Plan;
use App\Models\User;
use Database\Factories\PlanFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

class AssignPlanCommandTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->owner = User::factory()->create();
    }

    private function assign(string|int $business, string $plan): int
    {
        return Artisan::call('billing:assign', ['business' => (string) $business, 'plan' => $plan]);
    }

    public function test_it_assigns_a_paid_plan_and_keeps_history(): void
    {
        $paid = Plan::factory()->monthly('49.00')->unlimited()->create(['code' => 'ops-paid']);
        $business = $this->businessOf($this->owner);

        $this->assertSame(0, $this->assign($business->getKey(), 'ops-paid'));

        $current = $business->currentSubscription()->with('plan')->sole();
        $this->assertSame($paid->getKey(), $current->plan_id);
        $this->assertSame('2026-11-10 12:00:00', $current->current_period_ends_at->toDateTimeString());
        $this->assertSame(2, $business->subscriptions()->count());
        $this->assertSame('replaced', $business->subscriptions()->where('is_current', null)->sole()->end_reason);
        $this->assertStringContainsString('now on', Artisan::output());
    }

    public function test_it_uses_the_newest_active_version_by_default_and_an_exact_one_on_request(): void
    {
        Plan::factory()->monthly('10.00')->unlimited()->create(['code' => 'versioned', 'version' => 1]);
        $v2 = Plan::factory()->monthly('12.00')->unlimited()->create(['code' => 'versioned', 'version' => 2]);
        $business = $this->businessOf($this->owner);

        $this->assign($business->getKey(), 'versioned');
        $this->assertSame($v2->getKey(), $business->currentSubscription()->sole()->plan_id);

        $this->assign($business->getKey(), 'versioned:1');
        $this->assertSame(1, $business->currentSubscription()->with('plan')->sole()->plan->version);
    }

    public function test_it_can_move_a_legacy_business_to_free_which_self_service_cannot(): void
    {
        $business = $this->businessOf($this->owner);

        $this->assertSame(0, $this->assign($business->getKey(), 'free'));

        $this->assertSame($this->freePlan()->getKey(), $business->currentSubscription()->sole()->plan_id);
    }

    public function test_it_can_return_a_business_to_legacy(): void
    {
        $business = $this->businessOf($this->owner);
        $this->assign($business->getKey(), 'free');

        $this->assign($business->getKey(), 'legacy');

        $this->assertTrue($business->currentSubscription()->with('plan')->sole()->plan->isLegacy());
    }

    public function test_it_rejects_an_unknown_business(): void
    {
        $this->assertSame(1, $this->assign(999999, 'free'));
        $this->assertStringContainsString('No business', Artisan::output());
        $this->assertSame(1, $this->assign('abc', 'free'));
        $this->assertSame(1, $this->assign('-1', 'free'));
    }

    public function test_it_rejects_unknown_retired_and_malformed_plans(): void
    {
        Plan::factory()->retired()->monthly()->create(['code' => 'gone']);
        $business = $this->businessOf($this->owner);

        foreach (['nope', 'gone', 'free:9', 'free:x', ':1', ''] as $plan) {
            $this->assertSame(1, $this->assign($business->getKey(), $plan), $plan);
        }

        $this->assertSame(1, $business->subscriptions()->count());
        $this->assertTrue($business->currentSubscription()->with('plan')->sole()->plan->isLegacy());
    }

    public function test_it_refuses_the_plan_the_business_is_already_on(): void
    {
        $business = $this->businessOf($this->owner);

        $this->assertSame(1, $this->assign($business->getKey(), 'legacy'));
        $this->assertStringContainsString('already on', Artisan::output());
        $this->assertSame(1, $business->subscriptions()->count());
    }

    public function test_it_will_not_give_a_second_trial(): void
    {
        $business = $this->businessOf($this->owner);
        $this->assertSame(0, $this->assign($business->getKey(), 'trial'));
        $this->assign($business->getKey(), 'free');

        $this->assertSame(1, $this->assign($business->getKey(), 'trial'));
        $this->assertStringContainsString('already used its free trial', Artisan::output());
    }

    public function test_assigning_restores_access_to_a_read_only_business(): void
    {
        $business = $this->businessOf($this->owner);
        $this->makeReadOnly($this->owner);
        Plan::factory()->monthly()->unlimited()->create(['code' => 'restore']);

        $this->assign($business->getKey(), 'restore');

        $this->assertSame(AccessState::Full, app(EntitlementService::class)->fresh($business)->access());
    }

    public function test_it_only_touches_the_named_business(): void
    {
        $other = User::factory()->create();
        Plan::factory()->monthly()->unlimited()->create(['code' => 'only-one']);

        $this->assign($this->businessOf($this->owner)->getKey(), 'only-one');

        $this->assertTrue($this->businessOf($other)->currentSubscription()->with('plan')->sole()->plan->isLegacy());
        $this->assertSame(1, $this->businessOf($other)->subscriptions()->count());
    }

    public function test_it_records_no_actor_because_it_is_an_operator_tool(): void
    {
        $this->assign($this->businessOf($this->owner)->getKey(), 'free');

        $this->assertNull($this->businessOf($this->owner)->currentSubscription()->sole()->created_by);
    }

    // ---- the provider boundary ------------------------------------------------------------

    public function test_the_manual_provider_is_the_configured_provider_and_takes_no_payments(): void
    {
        $provider = app(BillingProvider::class);

        $this->assertInstanceOf(ManualBillingProvider::class, $provider);
        $this->assertSame('manual', $provider->name());
        $this->assertFalse($provider->supportsCheckout());
    }

    public function test_the_manual_provider_refuses_checkout_webhooks_and_refunds(): void
    {
        $provider = new ManualBillingProvider;
        $request = new CheckoutRequest($this->businessOf($this->owner), PlanFactory::seeded('free'));

        foreach ([
            fn () => $provider->createCheckout($request),
            fn () => $provider->parseWebhook(Request::create('/webhook', 'POST')),
            fn () => $provider->refund('pay_1', '10.00'),
        ] as $call) {
            $this->assertThrows($call, BillingProviderException::class);
        }
    }

    public function test_cancelling_at_the_manual_provider_is_a_harmless_no_op(): void
    {
        $subscription = $this->businessOf($this->owner)->currentSubscription()->sole();

        (new ManualBillingProvider)->cancel($subscription);

        $this->assertTrue($subscription->fresh()->isCurrent());
    }

    public function test_an_unknown_configured_provider_fails_loudly_instead_of_guessing(): void
    {
        config(['billing.provider' => 'mystery']);

        $this->expectException(InvalidArgumentException::class);

        app(BillingProvider::class);
    }
}
