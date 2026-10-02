<?php

namespace Tests\Feature\Billing;

use App\Billing\EntitlementService;
use App\Enums\AccessState;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Database\Factories\PlanFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use ManagesSubscriptions;
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-10 12:00:00');
        $this->service = app(SubscriptionService::class);
    }

    private function business(): Business
    {
        return $this->businessOf(User::factory()->create());
    }

    private function bareBusiness(): Business
    {
        return Business::factory()->withoutSubscription()->create();
    }

    private function access(Business $business): AccessState
    {
        return app(EntitlementService::class)->fresh($business)->access();
    }

    private function current(Business $business): Subscription
    {
        return $business->currentSubscription()->with('plan')->sole();
    }

    private function assertExactlyOneCurrent(Business $business): void
    {
        $this->assertSame(1, $business->subscriptions()->where('is_current', 1)->count());
    }

    private function paidPlan(string $interval = 'monthly'): Plan
    {
        return $interval === 'yearly' ? Plan::factory()->yearly()->unlimited()->create() : Plan::factory()->monthly()->unlimited()->create();
    }

    // ---- trial ----------------------------------------------------------------------------

    public function test_a_trial_lasts_the_trial_plans_14_days_and_has_full_access(): void
    {
        $business = $this->bareBusiness();

        $subscription = $this->service->startTrial($business);

        $this->assertSame('trial', $subscription->plan->code);
        $this->assertSame(SubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($subscription->isCurrent());
        $this->assertSame('2026-10-24 12:00:00', $subscription->trial_ends_at->toDateTimeString());
        $this->assertSame(AccessState::Full, $this->access($business));
    }

    public function test_a_trial_is_read_only_the_instant_it_ends_with_no_grace(): void
    {
        $business = $this->bareBusiness();
        $this->service->startTrial($business);

        $this->travelTo('2026-10-24 11:59:59');
        $this->assertSame(AccessState::Full, $this->access($business));

        $this->travelTo('2026-10-24 12:00:00');
        $this->assertSame(AccessState::ReadOnly, $this->access($business));

        $this->travelTo('2026-10-25 12:00:00');
        $this->assertSame(AccessState::ReadOnly, $this->access($business));
    }

    public function test_a_trial_cannot_start_for_a_business_that_already_has_a_subscription(): void
    {
        $this->expectException(SubscriptionException::class);

        $this->service->startTrial($this->business());
    }

    public function test_a_business_gets_only_one_trial_ever_even_with_no_current_subscription(): void
    {
        $business = $this->bareBusiness();
        Subscription::factory()->for($business)->trial(now()->subMonths(3))->ended('expired', now()->subMonths(3))->create();

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('already used its free trial');

        $this->service->startTrial($business);
    }

    public function test_a_trial_is_not_earned_again_by_changing_plans(): void
    {
        $business = $this->bareBusiness();
        $this->service->startTrial($business);
        $this->service->changePlan($business, $this->freePlan());

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('already used its free trial');

        $this->service->activate($business, PlanFactory::seeded('trial'));
    }

    public function test_the_operator_cannot_hand_out_a_second_trial_either(): void
    {
        $business = $this->business();
        $this->service->activate($business, PlanFactory::seeded('trial'));
        $this->service->activate($business, $this->paidPlan());

        $this->expectException(SubscriptionException::class);

        $this->service->activate($business, PlanFactory::seeded('trial'));
    }

    public function test_trial_history_is_read_from_subscription_rows_so_it_survives_other_changes(): void
    {
        $business = $this->bareBusiness();
        $this->service->startTrial($business);
        $this->service->activate($business, $this->paidPlan());
        $this->service->activate($business, $this->freePlan());

        $this->assertSame(1, $business->subscriptions()->whereNotNull('trial_ends_at')->count());
    }

    // ---- activate (operator) --------------------------------------------------------------

    public function test_activating_a_monthly_plan_starts_a_period_and_a_grace_window(): void
    {
        $business = $this->business();
        $plan = $this->paidPlan();

        $subscription = $this->service->activate($business, $plan);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame('2026-10-10 12:00:00', $subscription->current_period_starts_at->toDateTimeString());
        $this->assertSame('2026-11-10 12:00:00', $subscription->current_period_ends_at->toDateTimeString());
        $this->assertSame('2026-11-17 12:00:00', $subscription->grace_ends_at->toDateTimeString());
        $this->assertNull($subscription->trial_ends_at);
    }

    public function test_activating_a_yearly_plan_runs_for_a_year(): void
    {
        $subscription = $this->service->activate($this->business(), $this->paidPlan('yearly'));

        $this->assertSame('2027-10-10 12:00:00', $subscription->current_period_ends_at->toDateTimeString());
    }

    public function test_activating_ends_the_old_subscription_as_replaced_and_keeps_its_dates(): void
    {
        $business = $this->business();
        $old = $this->current($business);

        $new = $this->service->activate($business, $this->paidPlan());

        $old->refresh();
        $this->assertSame(SubscriptionStatus::Replaced, $old->status);
        $this->assertNull($old->is_current);
        $this->assertSame('replaced', $old->end_reason);
        $this->assertSame('2026-10-10 12:00:00', $old->ended_at->toDateTimeString());
        $this->assertSame($old->started_at->toDateTimeString(), $old->fresh()->started_at->toDateTimeString());
        $this->assertTrue($new->isCurrent());
        $this->assertExactlyOneCurrent($business);
    }

    public function test_an_old_row_is_never_edited_into_a_different_plan(): void
    {
        $business = $this->business();
        $legacy = PlanFactory::seeded('legacy');

        $this->service->activate($business, $this->paidPlan());

        $this->assertSame($legacy->getKey(), $business->subscriptions()->orderBy('id')->first()->plan_id);
        $this->assertSame(2, $business->subscriptions()->count());
    }

    public function test_history_keeps_every_plan_in_order(): void
    {
        $business = $this->business();
        $paid = $this->paidPlan();

        $this->service->activate($business, $paid);
        $this->service->activate($business, $this->freePlan());
        $this->service->activate($business, $paid);

        $this->assertSame(
            [PlanFactory::seeded('legacy')->getKey(), $paid->getKey(), $this->freePlan()->getKey(), $paid->getKey()],
            $business->subscriptions()->orderBy('id')->pluck('plan_id')->all(),
        );
        $this->assertExactlyOneCurrent($business);
    }

    public function test_activating_the_plan_the_business_is_already_on_is_refused(): void
    {
        $business = $this->business();
        $paid = $this->paidPlan();
        $this->service->activate($business, $paid);

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('already on');

        $this->service->activate($business, $paid);
    }

    public function test_the_same_plan_can_be_activated_again_after_it_has_lapsed(): void
    {
        $business = $this->business();
        $paid = $this->paidPlan();
        $this->service->activate($business, $paid);

        $this->travelTo('2026-12-31 12:00:00');
        $again = $this->service->activate($business, $paid);

        $this->assertTrue($again->isCurrent());
        $this->assertSame(AccessState::Full, $this->access($business));
        $this->assertSame(2, $business->subscriptions()->where('plan_id', $paid->getKey())->count());
    }

    public function test_a_retired_plan_cannot_be_activated(): void
    {
        $retired = Plan::factory()->monthly()->unlimited()->retired()->create();

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('not available');

        $this->service->activate($this->business(), $retired);
    }

    public function test_a_paid_plan_without_a_billing_interval_is_refused(): void
    {
        $broken = Plan::factory()->create(['price' => '10.00', 'billing_interval' => null]);

        $this->expectException(SubscriptionException::class);

        $this->service->activate($this->business(), $broken);
    }

    public function test_a_plan_that_was_never_saved_cannot_be_activated(): void
    {
        $this->expectException(SubscriptionException::class);

        $this->service->activate($this->business(), Plan::factory()->make());
    }

    public function test_the_operator_can_move_a_business_off_and_back_onto_legacy(): void
    {
        $business = $this->business();
        $legacy = PlanFactory::seeded('legacy');

        $this->service->activate($business, $this->freePlan());
        $back = $this->service->activate($business, $legacy);

        $this->assertSame($legacy->getKey(), $back->plan_id);
        $this->assertNull($back->current_period_ends_at);
        $this->assertSame(AccessState::Full, $this->access($business));
    }

    public function test_activate_records_the_actor_as_audit_metadata_only(): void
    {
        $operator = User::factory()->create();
        $business = $this->business();

        $subscription = $this->service->activate($business, $this->paidPlan(), $operator);

        $this->assertSame($operator->getKey(), $subscription->created_by);
        $this->assertFalse($business->members()->whereKey($operator->getKey())->exists());
    }

    // ---- self-service changes -------------------------------------------------------------

    public function test_a_paid_plan_cannot_be_chosen_in_self_service(): void
    {
        $business = $this->bareBusiness();
        $this->service->startTrial($business);

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('Contact us');

        $this->service->changePlan($business, $this->paidPlan());
    }

    public function test_the_trial_plan_and_legacy_cannot_be_chosen_in_self_service(): void
    {
        $business = $this->bareBusiness();
        $this->service->startTrial($business);
        $this->service->changePlan($business, $this->freePlan());

        foreach (['trial', 'legacy'] as $code) {
            try {
                $this->service->changePlan($business, PlanFactory::seeded($code));
                $this->fail("{$code} should be refused");
            } catch (SubscriptionException) {
                $this->assertSame($this->freePlan()->getKey(), $this->current($business)->plan_id);
            }
        }
    }

    public function test_a_legacy_business_cannot_change_plan_in_self_service(): void
    {
        $business = $this->business();

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('contacting us');

        $this->service->changePlan($business, $this->freePlan());
    }

    public function test_a_trial_can_switch_to_free_immediately(): void
    {
        $business = $this->bareBusiness();
        $trial = $this->service->startTrial($business);

        $free = $this->service->changePlan($business, $this->freePlan());

        $this->assertSame($this->freePlan()->getKey(), $free->plan_id);
        $this->assertNull($free->pending_plan_id);
        $this->assertSame(SubscriptionStatus::Replaced, $trial->fresh()->status);
        $this->assertExactlyOneCurrent($business);
        $this->assertSame(AccessState::Full, $this->access($business));
    }

    public function test_an_expired_business_can_activate_free_normally(): void
    {
        $business = $this->business();
        $this->makeReadOnly($business);
        $this->assertSame(AccessState::ReadOnly, $this->access($business));

        $this->service->changePlan($business, $this->freePlan());

        $this->assertSame(AccessState::Full, $this->access($business));
        $this->assertExactlyOneCurrent($business);
    }

    public function test_a_lapsed_paid_subscription_in_grace_switches_to_free_immediately(): void
    {
        $business = $this->business();
        $this->lapsedPaid($business, 2);

        $this->service->changePlan($business, $this->freePlan());

        $this->assertSame($this->freePlan()->getKey(), $this->current($business)->plan_id);
        $this->assertNull($this->current($business)->pending_plan_id);
    }

    public function test_choosing_the_plan_you_are_on_is_refused(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->freePlan());

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('already on');

        $this->service->changePlan($business, $this->freePlan());
    }

    public function test_a_downgrade_in_a_paid_period_is_deferred_to_the_period_end(): void
    {
        $business = $this->business();
        $paid = $this->service->activate($business, $this->paidPlan());

        $result = $this->service->changePlan($business, $this->freePlan());

        $this->assertTrue($result->is($paid));
        $this->assertSame($this->freePlan()->getKey(), $result->pending_plan_id);
        $this->assertSame($paid->plan_id, $this->current($business)->plan_id);
        $this->assertSame(2, $business->subscriptions()->count(), 'no replacement row is created early');
        $this->assertSame(SubscriptionStatus::Active, $paid->fresh()->status);
    }

    public function test_the_current_plan_keeps_applying_until_the_period_ends(): void
    {
        $business = $this->business();
        $paid = $this->paidPlan();
        $this->service->activate($business, $paid);
        $this->service->changePlan($business, $this->freePlan());

        $this->travelTo('2026-11-10 11:59:59');
        $this->assertSame($paid->getKey(), app(EntitlementService::class)->fresh($business)->plan()->getKey());

        $this->travelTo('2026-11-10 12:00:00');
        $entitlements = app(EntitlementService::class)->fresh($business);
        $this->assertSame($this->freePlan()->getKey(), $entitlements->plan()->getKey());
        $this->assertSame(AccessState::Full, $entitlements->access());
    }

    public function test_choosing_the_same_pending_plan_twice_changes_nothing(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());

        $first = $this->service->changePlan($business, $this->freePlan());
        $second = $this->service->changePlan($business, $this->freePlan());

        $this->assertTrue($first->is($second));
        $this->assertSame(2, $business->subscriptions()->count());
    }

    public function test_only_one_downgrade_is_pending_at_a_time(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $otherFree = Plan::factory()->create();

        $this->service->changePlan($business, $this->freePlan());
        $this->service->changePlan($business, $otherFree);

        $this->assertSame($otherFree->getKey(), $this->current($business)->pending_plan_id);
        $this->assertSame(1, $business->subscriptions()->whereNotNull('pending_plan_id')->count());
    }

    public function test_settling_a_downgrade_starts_the_new_subscription_exactly_at_the_old_period_end(): void
    {
        $business = $this->business();
        $paid = $this->service->activate($business, $this->paidPlan());
        $this->service->changePlan($business, $this->freePlan());

        $this->travelTo('2026-11-20 09:00:00');
        $new = $this->service->settle($business);

        $paid->refresh();
        $this->assertSame('2026-11-10 12:00:00', $paid->ended_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Replaced, $paid->status);
        $this->assertNull($paid->is_current);
        $this->assertSame('2026-11-10 12:00:00', $new->started_at->toDateTimeString());
        $this->assertSame($this->freePlan()->getKey(), $new->plan_id);
        $this->assertTrue($new->isCurrent());
        $this->assertNull($new->current_period_ends_at);
        $this->assertExactlyOneCurrent($business);
    }

    public function test_settling_does_nothing_before_the_period_ends(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->changePlan($business, $this->freePlan());

        $this->assertNull($this->service->settle($business));
        $this->assertSame(2, $business->subscriptions()->count());
    }

    public function test_settling_twice_is_harmless(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->changePlan($business, $this->freePlan());
        $this->travelTo('2026-12-01 00:00:00');

        $this->assertNotNull($this->service->settle($business));
        $this->assertNull($this->service->settle($business));
        $this->assertSame(3, $business->subscriptions()->count());
        $this->assertExactlyOneCurrent($business);
    }

    public function test_every_change_settles_a_due_downgrade_first(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->changePlan($business, $this->freePlan());
        $this->travelTo('2026-12-01 00:00:00');

        $other = Plan::factory()->create();
        $this->service->changePlan($business, $other);

        $this->assertSame(
            [PlanFactory::seeded('legacy')->getKey(), $this->paidPlanIdOf($business), $this->freePlan()->getKey(), $other->getKey()],
            $business->subscriptions()->orderBy('id')->pluck('plan_id')->all(),
        );
        $this->assertExactlyOneCurrent($business);
    }

    private function paidPlanIdOf(Business $business): int
    {
        return $business->subscriptions()->orderBy('id')->get()[1]->plan_id;
    }

    public function test_a_deferred_downgrade_keeps_the_plan_referenced_so_it_cannot_be_edited(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $free = Plan::factory()->create(['price' => '0.00']);
        $this->service->changePlan($business, $free);

        $this->assertTrue($free->fresh()->isReferenced());
    }

    // ---- cancel / resume ------------------------------------------------------------------

    public function test_cancelling_sets_the_flag_and_keeps_full_access_to_the_period_end(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());

        $subscription = $this->service->cancelAtPeriodEnd($business);

        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame('2026-10-10 12:00:00', $subscription->canceled_at->toDateTimeString());
        $this->assertSame(AccessState::Full, $this->access($business));
        $this->assertExactlyOneCurrent($business);
    }

    public function test_a_cancelled_subscription_is_read_only_at_the_period_end_with_no_grace(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);

        $this->travelTo('2026-11-10 11:59:59');
        $this->assertSame(AccessState::Full, $this->access($business));

        $this->travelTo('2026-11-10 12:00:00');
        $this->assertSame(AccessState::ReadOnly, $this->access($business));
    }

    public function test_cancelling_twice_is_refused(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('already set to cancel');

        $this->service->cancelAtPeriodEnd($business);
    }

    public function test_only_a_paid_subscription_can_be_cancelled(): void
    {
        $trial = $this->bareBusiness();
        $this->service->startTrial($trial);
        $free = $this->business();
        $this->service->activate($free, $this->freePlan());

        foreach ([$trial, $free, $this->business()] as $business) {
            try {
                $this->service->cancelAtPeriodEnd($business);
                $this->fail('Expected a refusal');
            } catch (SubscriptionException $e) {
                $this->assertStringContainsString('no paid subscription', $e->getMessage());
            }
        }
    }

    public function test_cancelling_clears_a_pending_downgrade_and_a_downgrade_clears_a_cancellation(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());

        $this->service->changePlan($business, $this->freePlan());
        $cancelled = $this->service->cancelAtPeriodEnd($business);
        $this->assertNull($cancelled->pending_plan_id);
        $this->assertTrue($cancelled->cancel_at_period_end);

        $downgraded = $this->service->changePlan($business, $this->freePlan());
        $this->assertFalse($downgraded->cancel_at_period_end);
        $this->assertNull($downgraded->canceled_at);
        $this->assertSame($this->freePlan()->getKey(), $downgraded->pending_plan_id);
    }

    public function test_resume_undoes_a_cancellation(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);

        $resumed = $this->service->resume($business);

        $this->assertFalse($resumed->cancel_at_period_end);
        $this->assertNull($resumed->canceled_at);
        $this->travelTo('2026-11-12 12:00:00');
        $this->assertSame(AccessState::Grace, $this->access($business), 'a resumed subscription gets grace again');
    }

    public function test_resume_undoes_a_pending_downgrade(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->changePlan($business, $this->freePlan());

        $this->assertNull($this->service->resume($business)->pending_plan_id);
    }

    public function test_resume_with_nothing_to_resume_is_refused(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('nothing to resume');

        $this->service->resume($business);
    }

    public function test_a_cancellation_cannot_be_resumed_after_the_period_has_ended(): void
    {
        $business = $this->business();
        $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);
        $this->travelTo('2026-11-11 00:00:00');

        $this->expectException(SubscriptionException::class);

        $this->service->resume($business);
    }

    // ---- renew ----------------------------------------------------------------------------

    public function test_renewing_on_time_continues_from_the_old_period_end(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());

        $this->travelTo('2026-11-05 08:00:00');
        $renewed = $this->service->renew($business, $subscription);

        $this->assertSame('2026-11-10 12:00:00', $renewed->current_period_starts_at->toDateTimeString());
        $this->assertSame('2026-12-10 12:00:00', $renewed->current_period_ends_at->toDateTimeString());
        $this->assertSame('2026-12-17 12:00:00', $renewed->grace_ends_at->toDateTimeString());
        $this->assertSame(SubscriptionStatus::Active, $renewed->status);
        $this->assertSame(2, $business->subscriptions()->count(), 'renewal is the same row');
    }

    public function test_renewing_within_grace_continues_without_a_gap(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());

        $this->travelTo('2026-11-13 12:00:00');
        $renewed = $this->service->renew($business, $subscription);

        $this->assertSame('2026-11-10 12:00:00', $renewed->current_period_starts_at->toDateTimeString());
    }

    public function test_renewing_after_grace_starts_from_now(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());

        $this->travelTo('2026-12-30 12:00:00');
        $renewed = $this->service->renew($business, $subscription);

        $this->assertSame('2026-12-30 12:00:00', $renewed->current_period_starts_at->toDateTimeString());
        $this->assertSame('2027-01-30 12:00:00', $renewed->current_period_ends_at->toDateTimeString());
        $this->assertSame(AccessState::Full, $this->access($business));
    }

    public function test_renewing_recovers_a_past_due_subscription(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->service->markPastDue($business, $subscription);

        $this->assertSame(SubscriptionStatus::Active, $this->service->renew($business, $subscription)->status);
    }

    public function test_a_cancelled_subscription_must_be_resumed_before_renewing(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('Resume');

        $this->service->renew($business, $subscription);
    }

    public function test_a_trial_or_legacy_subscription_has_no_period_to_renew(): void
    {
        $trial = $this->bareBusiness();
        $trialRow = $this->service->startTrial($trial);
        $legacy = $this->business();
        $legacyRow = $this->current($legacy);

        foreach ([[$trial, $trialRow], [$legacy, $legacyRow]] as [$business, $row]) {
            try {
                $this->service->renew($business, $row);
                $this->fail('Expected a refusal');
            } catch (SubscriptionException $e) {
                $this->assertStringContainsString('no billing period', $e->getMessage());
            }
        }
    }

    public function test_renewing_a_subscription_that_was_replaced_meanwhile_is_refused(): void
    {
        $business = $this->business();
        $stale = $this->service->activate($business, $this->paidPlan());
        $this->service->activate($business, $this->freePlan());

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('has changed');

        $this->service->renew($business, $stale);
    }

    public function test_renewing_an_expired_subscription_is_refused(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->travelTo('2027-03-01 00:00:00');
        $this->service->expire($business, $subscription);

        $this->expectException(SubscriptionException::class);

        $this->service->renew($business, $subscription);
    }

    // ---- past due / expire ----------------------------------------------------------------

    public function test_past_due_extends_grace_from_the_later_of_now_and_the_period_end(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());

        $this->travelTo('2026-11-12 12:00:00');
        $pastDue = $this->service->markPastDue($business, $subscription);

        $this->assertSame(SubscriptionStatus::PastDue, $pastDue->status);
        $this->assertSame('2026-11-19 12:00:00', $pastDue->grace_ends_at->toDateTimeString());
        $this->assertSame(AccessState::Grace, $this->access($business));

        $this->travelTo('2026-11-19 12:00:00');
        $this->assertSame(AccessState::ReadOnly, $this->access($business));
    }

    public function test_past_due_in_the_middle_of_a_period_keeps_full_access_until_the_period_ends(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());

        $pastDue = $this->service->markPastDue($business, $subscription);

        $this->assertSame('2026-11-17 12:00:00', $pastDue->grace_ends_at->toDateTimeString());
        $this->assertSame(AccessState::Full, $this->access($business));
    }

    public function test_past_due_cannot_repeat_and_only_applies_to_paid_periods(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->service->markPastDue($business, $subscription);

        try {
            $this->service->markPastDue($business, $subscription);
            $this->fail('Expected a refusal');
        } catch (SubscriptionException $e) {
            $this->assertStringContainsString('cannot become past due', $e->getMessage());
        }

        $legacy = $this->business();
        $this->expectException(SubscriptionException::class);
        $this->service->markPastDue($legacy, $this->current($legacy));
    }

    public function test_expire_refuses_while_access_is_still_available(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->travelTo('2026-11-12 12:00:00');

        $this->expectException(SubscriptionException::class);
        $this->expectExceptionMessage('not ended yet');

        $this->service->expire($business, $subscription);
    }

    public function test_expiring_confirms_what_the_dates_say_and_changes_no_business_data(): void
    {
        $business = $this->business();
        Customer::factory()->count(3)->create(['business_id' => $business->getKey()]);
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->travelTo('2026-12-01 00:00:00');

        $expired = $this->service->expire($business, $subscription);

        $this->assertSame(SubscriptionStatus::Expired, $expired->status);
        $this->assertSame('expired', $expired->end_reason);
        $this->assertSame('2026-11-10 12:00:00', $expired->ended_at->toDateTimeString());
        $this->assertTrue($expired->fresh()->isCurrent(), 'the expired row stays current, read-only');
        $this->assertSame(AccessState::ReadOnly, $this->access($business));
        $this->assertSame(3, $business->customers()->count());
    }

    public function test_expiring_a_cancelled_subscription_records_why(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($business);
        $this->travelTo('2026-11-10 12:00:01');

        $this->assertSame('canceled', $this->service->expire($business, $subscription)->end_reason);
    }

    public function test_expiring_an_ended_trial(): void
    {
        $business = $this->bareBusiness();
        $trial = $this->service->startTrial($business);
        $this->travelTo('2026-10-30 00:00:00');

        $expired = $this->service->expire($business, $trial);

        $this->assertSame('2026-10-24 12:00:00', $expired->ended_at->toDateTimeString());
        $this->assertSame(AccessState::ReadOnly, $this->access($business));
    }

    public function test_expiring_twice_is_harmless(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->travelTo('2026-12-01 00:00:00');

        $this->service->expire($business, $subscription);
        $again = $this->service->expire($business, $subscription);

        $this->assertSame(SubscriptionStatus::Expired, $again->status);
    }

    public function test_an_expired_business_can_choose_a_plan_and_the_expired_row_becomes_history(): void
    {
        $business = $this->business();
        $subscription = $this->service->activate($business, $this->paidPlan());
        $this->travelTo('2026-12-01 00:00:00');
        $this->service->expire($business, $subscription);

        $this->service->changePlan($business, $this->freePlan());

        $expired = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::Expired, $expired->status, 'history keeps why it ended');
        $this->assertNull($expired->is_current);
        $this->assertSame('2026-11-10 12:00:00', $expired->ended_at->toDateTimeString());
        $this->assertExactlyOneCurrent($business);
    }

    // ---- integrity ------------------------------------------------------------------------

    public function test_the_database_refuses_a_second_current_subscription(): void
    {
        $business = $this->business();

        $this->expectException(QueryException::class);

        Subscription::factory()->for($business)->create();
    }

    public function test_many_ended_subscriptions_can_coexist(): void
    {
        $business = $this->business();

        Subscription::factory()->count(3)->for($business)->ended()->create();

        $this->assertSame(4, $business->subscriptions()->count());
    }

    public function test_the_entitlement_memo_is_dropped_after_a_change(): void
    {
        $business = $this->business();
        $entitlements = app(EntitlementService::class);
        $this->assertTrue($entitlements->for($business)->plan()->isLegacy());

        $this->service->activate($business, $this->freePlan());

        $this->assertSame($this->freePlan()->getKey(), $entitlements->for($business)->plan()->getKey());
    }

    public function test_changes_to_one_business_never_touch_another(): void
    {
        $a = $this->business();
        $b = $this->business();
        $before = $this->current($b)->only(['id', 'plan_id', 'status', 'is_current']);

        $this->service->activate($a, $this->paidPlan());
        $this->service->cancelAtPeriodEnd($a);

        $this->assertEquals($before, $this->current($b)->only(['id', 'plan_id', 'status', 'is_current']));
        $this->assertSame(1, $b->subscriptions()->count());
    }

    public function test_a_plan_cannot_be_deleted_while_a_subscription_uses_it(): void
    {
        $paid = $this->paidPlan();
        $this->service->activate($this->business(), $paid);

        $this->expectException(\LogicException::class);

        $paid->delete();
    }
}
