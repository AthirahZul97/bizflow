<?php

namespace Tests\Feature\Billing;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BusinessRegistration;
use Database\Factories\PlanFactory;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillingPagesTest extends TestCase
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

    private function registered(): User
    {
        return app(BusinessRegistration::class)->register([
            'name' => 'Trial Owner',
            'email' => 'trial@example.test',
            'password' => 'password-123',
            'business_name' => 'Trial Co',
        ]);
    }

    /**
     * A member of the owner's business who is not its owner.
     */
    private function nonOwner(): User
    {
        $member = User::factory()->withoutBusiness()->create();
        DB::table('business_user')->insert([
            'business_id' => $this->businessOf($this->owner)->getKey(),
            'user_id' => $member->getKey(),
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $member;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function billingRoutes(): array
    {
        return [
            'show' => ['get', 'billing.show'],
            'plans' => ['get', 'billing.plans'],
            'change' => ['post', 'billing.change'],
            'cancel confirmation' => ['get', 'billing.cancel.confirm'],
            'cancel' => ['post', 'billing.cancel'],
            'resume' => ['post', 'billing.resume'],
        ];
    }

    #[DataProvider('billingRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route): void
    {
        $this->{$method}(route($route))->assertRedirect(route('login'));
    }

    public function test_no_billing_route_takes_an_id(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with((string) $route->getName(), 'billing.')) {
                $this->assertSame([], $route->parameterNames(), $route->uri());
                $this->assertStringNotContainsString('{', $route->uri());
            }
        }
    }

    // ---- overview -------------------------------------------------------------------------

    public function test_the_overview_for_a_legacy_business(): void
    {
        $response = $this->actingAs($this->owner)->get(route('billing.show'));

        $response->assertOk()
            ->assertSee('Legacy')
            ->assertSee('Full access')
            ->assertSee('No end date')
            ->assertSee('Subscription history')
            ->assertDontSee('Cancel subscription');
        $this->assertSame(1, substr_count($response->getContent(), 'badge text-bg-primary">Current'));
    }

    public function test_the_overview_shows_usage_against_limits(): void
    {
        $this->limitTo($this->owner, ['customers.max' => 5, 'recurring_invoices.max' => 0, 'invoices.email' => false]);
        Customer::factory()->count(3)->ownedBy($this->owner)->create();

        $html = $this->actingAs($this->owner)->get(route('billing.show'))->assertOk()->getContent();

        $this->assertStringContainsString('3 / 5', $html);
        $this->assertStringContainsString('Not included', $html);
        $this->assertStringContainsString('data-entitlement="customers.max"', $html);
        $this->assertStringContainsString('aria-valuenow="60"', $html);
    }

    public function test_a_business_over_its_limit_is_told_nothing_was_deleted(): void
    {
        Customer::factory()->count(4)->ownedBy($this->owner)->create();
        $this->limitTo($this->owner, ['customers.max' => 2]);

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertSee('4 / 2')
            ->assertSee('Over the limit')
            ->assertSee('records are kept');
    }

    public function test_unlimited_entitlements_say_so(): void
    {
        $this->actingAs($this->owner)->get(route('billing.show'))->assertSee('Unlimited');
    }

    public function test_a_new_business_sees_its_trial_and_the_banner(): void
    {
        $user = $this->registered();

        $this->actingAs($user)->get(route('billing.show'))
            ->assertOk()
            ->assertSee('Trial')
            ->assertSee('Trial ends')
            ->assertSee('24 Oct 2026')
            ->assertSee('Free trial: 14 days left');
    }

    public function test_the_trial_banner_warns_in_the_last_days_and_then_reports_read_only(): void
    {
        $user = $this->registered();

        $this->travelTo('2026-10-22 12:00:00');
        $this->actingAs($user)->get(route('dashboard'))->assertSee('alert-warning', false)->assertSee('2 days left');

        $this->travelTo('2026-10-25 12:00:00');
        $this->actingAs($user)->get(route('dashboard'))->assertSee('alert-danger', false)->assertSee('free trial has ended');
        $this->actingAs($user)->get(route('billing.show'))->assertSee('Read-only')->assertSee('Trial ended');
    }

    public function test_a_paid_subscription_shows_its_period_and_offers_cancellation(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertSee('30 Oct 2026')
            ->assertSee('Cancel subscription')
            ->assertDontSee('Keep my subscription');
    }

    public function test_grace_is_explained(): void
    {
        $this->lapsedPaid($this->owner, 2);

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertSee('Grace period')
            ->assertSee('Grace ends')
            ->assertSee('15 Oct 2026');
    }

    public function test_the_history_lists_every_plan_without_exposing_ids(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);

        $response = $this->actingAs($this->owner)->get(route('billing.show'));

        $this->assertSame(2, substr_count($response->getContent(), '<tr>') - 1);
        $response->assertSee('Legacy')->assertSee('Replaced')->assertSee('Current');
        $this->assertStringNotContainsString('/billing/1', $response->getContent());
    }

    // ---- plans ----------------------------------------------------------------------------

    public function test_the_comparison_lists_free_hides_the_trial_plan_and_other_businesses_legacy(): void
    {
        $trialUser = $this->registered();

        $response = $this->actingAs($trialUser)->get(route('billing.plans'))->assertOk();

        $response->assertSee('data-plan="free"', false)
            ->assertDontSee('data-plan="trial"', false)
            ->assertDontSee('data-plan="legacy"', false)
            ->assertSee('Free trial: 14 days left');
    }

    public function test_a_legacy_business_sees_its_own_plan_but_no_free_switch(): void
    {
        $this->actingAs($this->owner)->get(route('billing.plans'))
            ->assertSee('data-plan="legacy"', false)
            ->assertSee('Your plan')
            ->assertSee('Contact us to upgrade')
            ->assertDontSee('Switch to Free');
    }

    public function test_the_comparison_shows_each_plans_entitlements(): void
    {
        $this->actingAs($this->registered())->get(route('billing.plans'))
            ->assertSee('Customers')->assertSee('Up to 10')
            ->assertSee('Recurring invoices')->assertSee('Email invoices');
    }

    public function test_paid_plans_say_contact_us_and_have_no_buy_button(): void
    {
        $this->seed(PlanSeeder::class);
        $user = $this->registered();

        $response = $this->actingAs($user)->get(route('billing.plans'))->assertOk();

        $response->assertSee('Contact us to upgrade')->assertSee('Paid (monthly)')->assertSee('Paid (yearly)');
        // Money::format already carries the currency symbol; it must not be shown twice.
        $response->assertSee('RM 29.00 / month')->assertSee('RM 290.00 / year')->assertDontSee('MYR RM');
        $this->assertSame(1, substr_count($response->getContent(), 'Switch to '), 'only the free plan can be switched to');
        $this->assertStringNotContainsString('checkout', strtolower($response->getContent()));
    }

    public function test_only_the_newest_active_version_of_a_plan_is_listed_and_retired_ones_are_hidden(): void
    {
        Plan::factory()->create(['code' => 'growth', 'version' => 1, 'name' => 'Growth OLD', 'price' => '10.00', 'billing_interval' => 'month']);
        Plan::factory()->create(['code' => 'growth', 'version' => 2, 'name' => 'Growth NEW', 'price' => '12.00', 'billing_interval' => 'month']);
        Plan::factory()->retired()->create(['code' => 'old', 'name' => 'Old Retired']);

        $this->actingAs($this->registered())->get(route('billing.plans'))
            ->assertSee('Growth NEW')
            ->assertDontSee('Growth OLD')
            ->assertDontSee('Old Retired');
    }

    public function test_development_prices_are_labelled_as_placeholders(): void
    {
        $this->seed(PlanSeeder::class);

        $this->actingAs($this->registered())->get(route('billing.plans'))->assertSee('placeholders for development');
    }

    // ---- switching to Free ----------------------------------------------------------------

    public function test_a_trial_owner_can_switch_to_free_immediately(): void
    {
        $user = $this->registered();

        $this->actingAs($user)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', "You're now on the Free plan.");

        $this->assertSame($this->freePlan()->getKey(), $this->businessOf($user)->currentSubscription()->sole()->plan_id);
    }

    public function test_a_downgrade_from_a_paid_period_is_scheduled_and_can_be_undone(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);

        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])
            ->assertSessionHas('status', "You'll move to the Free plan when your current period ends.");

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertSee('id="pending-plan"', false)
            ->assertSee('Free, from 30 Oct 2026')
            ->assertSee('Keep my subscription');
        $this->actingAs($this->owner)->get(route('billing.plans'))->assertSee('Starts when your period ends');

        $this->actingAs($this->owner)->post(route('billing.resume'))->assertRedirect(route('billing.show'));
        $this->assertNull($this->businessOf($this->owner)->currentSubscription()->sole()->pending_plan_id);
    }

    public function test_a_paid_plan_id_never_activates_a_paid_plan(): void
    {
        $paid = Plan::factory()->monthly()->unlimited()->create();
        $user = $this->registered();

        $this->actingAs($user)->from(route('billing.plans'))->post(route('billing.change'), ['plan_id' => $paid->getKey()])
            ->assertRedirect(route('billing.plans'))
            ->assertSessionHas('error');

        $this->assertStringContainsString('Contact us', session('error'));
        $this->assertSame('trial', $this->businessOf($user)->currentSubscription()->with('plan')->sole()->plan->code);
    }

    public function test_extra_posted_fields_cannot_make_a_paid_plan_free_or_change_another_business(): void
    {
        $paid = Plan::factory()->monthly()->unlimited()->create();
        $victim = User::factory()->create();
        $user = $this->registered();

        $this->actingAs($user)->post(route('billing.change'), [
            'plan_id' => $paid->getKey(), 'price' => '0.00', 'business_id' => $this->businessOf($victim)->getKey(),
            'status' => 'active', 'is_current' => 1, 'created_by' => $victim->getKey(),
        ]);

        $this->assertSame('trial', $this->businessOf($user)->currentSubscription()->with('plan')->sole()->plan->code);
        $this->assertTrue($this->businessOf($victim)->currentSubscription()->with('plan')->sole()->plan->isLegacy());
        $this->assertSame(1, $this->businessOf($victim)->subscriptions()->count());
        $this->assertSame('10.00', (string) $paid->fresh()->price, 'the plan itself is untouched');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badPlanIds(): array
    {
        return [
            'missing' => [null],
            'unknown' => [999999],
            'zero' => [0],
            'negative' => [-1],
            'text' => ['free'],
            'array' => [[1]],
            'decimal' => ['1.5'],
        ];
    }

    #[DataProvider('badPlanIds')]
    public function test_forged_plan_ids_are_rejected_without_changing_anything(mixed $planId): void
    {
        $user = $this->registered();
        $before = $this->businessOf($user)->subscriptions()->count();

        $this->actingAs($user)->post(route('billing.change'), $planId === null ? [] : ['plan_id' => $planId])
            ->assertSessionHasErrors('plan_id');

        $this->assertSame($before, $this->businessOf($user)->subscriptions()->count());
    }

    public function test_a_retired_plan_id_is_rejected(): void
    {
        $retired = Plan::factory()->retired()->create();

        $this->actingAs($this->registered())->post(route('billing.change'), ['plan_id' => $retired->getKey()])
            ->assertSessionHasErrors('plan_id');
    }

    public function test_the_trial_plan_and_legacy_plan_cannot_be_chosen(): void
    {
        $user = $this->registered();
        $this->actingAs($user)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()]);

        foreach (['trial', 'legacy'] as $code) {
            $this->actingAs($user)->post(route('billing.change'), ['plan_id' => PlanFactory::seeded($code)->getKey()])
                ->assertSessionHas('error');
        }

        $this->assertSame('free', $this->businessOf($user)->currentSubscription()->with('plan')->sole()->plan->code);
    }

    public function test_a_legacy_business_cannot_leave_legacy_by_itself(): void
    {
        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])
            ->assertSessionHas('error');

        $this->assertTrue($this->businessOf($this->owner)->currentSubscription()->with('plan')->sole()->plan->isLegacy());
    }

    // ---- cancel / resume ------------------------------------------------------------------

    public function test_cancelling_goes_through_a_confirmation_page(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);

        $this->actingAs($this->owner)->get(route('billing.cancel.confirm'))
            ->assertOk()->assertSee('30 Oct 2026')->assertSee('Nothing is deleted');
        $this->assertFalse($this->businessOf($this->owner)->currentSubscription()->sole()->cancel_at_period_end, 'GET changes nothing');

        $this->actingAs($this->owner)->post(route('billing.cancel'))
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('status', 'Your subscription will end on 30 Oct 2026.');
        $this->assertTrue($this->businessOf($this->owner)->currentSubscription()->sole()->cancel_at_period_end);

        $this->actingAs($this->owner)->get(route('billing.show'))
            ->assertSee('id="cancellation-state"', false)->assertSee('Keep my subscription');
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee('set to end on 30 Oct 2026');

        $this->actingAs($this->owner)->post(route('billing.resume'))->assertSessionHas('status');
        $this->assertFalse($this->businessOf($this->owner)->currentSubscription()->sole()->cancel_at_period_end);
    }

    public function test_the_cancel_page_redirects_when_there_is_nothing_to_cancel(): void
    {
        $this->actingAs($this->owner)->get(route('billing.cancel.confirm'))
            ->assertRedirect(route('billing.show'))->assertSessionHas('error');
        $this->actingAs($this->registered())->get(route('billing.cancel.confirm'))->assertRedirect(route('billing.show'));
    }

    public function test_cancelling_with_nothing_to_cancel_or_twice_gives_a_reason_not_an_error_page(): void
    {
        $this->actingAs($this->owner)->from(route('billing.show'))->post(route('billing.cancel'))
            ->assertRedirect(route('billing.show'))->assertSessionHas('error');

        $this->paidWithDaysLeft($this->owner, 20);
        $this->actingAs($this->owner)->post(route('billing.cancel'));
        $this->actingAs($this->owner)->from(route('billing.show'))->post(route('billing.cancel'))->assertSessionHas('error');
    }

    // ---- who may do what ------------------------------------------------------------------

    public function test_a_non_owner_can_look_but_gets_no_controls_and_cannot_manage(): void
    {
        $this->paidWithDaysLeft($this->owner, 20);
        $member = $this->nonOwner();

        $this->actingAs($member)->get(route('billing.show'))->assertOk()
            ->assertDontSee('Cancel subscription')
            ->assertDontSee('Keep my subscription');
        $this->actingAs($member)->get(route('billing.plans'))->assertOk()
            ->assertDontSee('Switch to Free')
            ->assertSee('Only the owner can change the plan');
        $this->actingAs($member)->get(route('dashboard'))->assertDontSee('>Billing<', false);

        $this->actingAs($member)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()])->assertForbidden();
        $this->actingAs($member)->get(route('billing.cancel.confirm'))->assertForbidden();
        $this->actingAs($member)->post(route('billing.cancel'))->assertForbidden();
        $this->actingAs($member)->post(route('billing.resume'))->assertForbidden();

        $subscription = $this->businessOf($this->owner)->currentSubscription()->sole();
        $this->assertFalse($subscription->cancel_at_period_end);
        $this->assertNull($subscription->pending_plan_id);
    }

    public function test_a_non_owner_gets_nothing_from_a_forbidden_plan_change_not_even_validation_hints(): void
    {
        $member = $this->nonOwner();

        $this->actingAs($member)->post(route('billing.change'), ['plan_id' => 999999])
            ->assertForbidden()
            ->assertSessionMissing('errors');
    }

    public function test_the_owner_sees_the_billing_link(): void
    {
        $this->actingAs($this->owner)->get(route('dashboard'))->assertSee(route('billing.show'), false)->assertSee('>Billing<', false);
    }

    public function test_the_owner_of_another_business_sees_nothing_of_this_ones_billing(): void
    {
        $this->paidWithDaysLeft($this->owner, 20, Plan::factory()->monthly('99.00')->unlimited()->create(['name' => 'Secret Enterprise Plan']));
        $other = User::factory()->create();

        $response = $this->actingAs($other)->get(route('billing.show'))->assertOk();

        // Another business's subscription, usage and history are never shown...
        $response->assertDontSee('Secret Enterprise Plan')->assertDontSee('99.00')->assertDontSee('Replaced');
        $this->assertSame(1, substr_count($response->getContent(), 'badge text-bg-primary">Current'));
    }

    public function test_the_plan_catalogue_is_shared_so_there_are_no_private_plans_yet(): void
    {
        // Documents a known limitation: every active plan is listed to every business, so a
        // negotiated plan would be visible too. A visibility flag would need a schema change.
        Plan::factory()->monthly('99.00')->unlimited()->create(['name' => 'Shared Catalogue Plan']);

        $this->actingAs(User::factory()->create())->get(route('billing.plans'))->assertSee('Shared Catalogue Plan');
    }

    public function test_an_owner_acting_never_changes_another_businesss_subscription(): void
    {
        $victim = User::factory()->create();
        $before = $this->businessOf($victim)->currentSubscription()->sole()->only(['id', 'plan_id', 'status']);
        $this->paidWithDaysLeft($this->owner, 20);

        $this->actingAs($this->owner)->post(route('billing.cancel'));
        $this->actingAs($this->owner)->post(route('billing.change'), ['plan_id' => $this->freePlan()->getKey()]);
        $this->actingAs($this->owner)->post(route('billing.resume'));

        $this->assertEquals($before, $this->businessOf($victim)->currentSubscription()->sole()->only(['id', 'plan_id', 'status']));
        $this->assertSame(1, $this->businessOf($victim)->subscriptions()->count());
    }

    public function test_the_current_business_comes_from_the_session_not_the_request(): void
    {
        $victim = User::factory()->create();

        $this->actingAs($this->owner)->get(route('billing.show').'?business_id='.$this->businessOf($victim)->getKey())
            ->assertOk()
            ->assertSee('Legacy');

        $this->assertSame(1, Subscription::query()->where('business_id', $this->businessOf($victim)->getKey())->count());
    }
}
