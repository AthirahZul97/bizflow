<?php

namespace Tests\Unit\Billing;

use App\Billing\AccessResolver;
use App\Enums\AccessState;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Access is derived from dates, never from the stored status. Most tests deliberately store a
 * status that contradicts the dates to prove it is ignored.
 */
class AccessResolverTest extends TestCase
{
    private CarbonImmutable $now;

    private AccessResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-10 12:00:00');
        $this->resolver = new AccessResolver;
    }

    private function plan(string $price = '0.00', ?BillingInterval $interval = null): Plan
    {
        return (new Plan)->forceFill([
            'id' => random_int(1, 100000),
            'price' => $price,
            'billing_interval' => $interval,
            'entitlements' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(array $attributes, ?Plan $plan = null, ?Plan $pending = null): Subscription
    {
        $subscription = (new Subscription)->forceFill($attributes + [
            'status' => SubscriptionStatus::Active,
            'cancel_at_period_end' => false,
        ]);
        $subscription->setRelation('plan', $plan ?? $this->plan());
        $subscription->setRelation('pendingPlan', $pending);

        return $subscription;
    }

    private function resolve(?Subscription $subscription): AccessState
    {
        return $this->resolver->resolve($subscription, $this->now)->state;
    }

    public function test_no_subscription_is_read_only(): void
    {
        $access = $this->resolver->resolve(null, $this->now);

        $this->assertSame(AccessState::ReadOnly, $access->state);
        $this->assertNull($access->plan());
        $this->assertSame('no_subscription', $access->reason);
    }

    public function test_a_subscription_without_an_end_has_full_access(): void
    {
        $this->assertSame(AccessState::Full, $this->resolve($this->subscription([])));
    }

    public function test_a_running_trial_has_full_access(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $this->now->addDay()]);

        $this->assertSame(AccessState::Full, $this->resolve($subscription));
    }

    public function test_a_trial_is_read_only_the_moment_it_ends_with_no_grace(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $this->now,
            'grace_ends_at' => $this->now->addDays(7),
        ]);

        $access = $this->resolver->resolve($subscription, $this->now);

        $this->assertSame(AccessState::ReadOnly, $access->state);
        $this->assertSame('trial_ended', $access->reason);
    }

    public function test_a_trial_whose_stored_status_still_says_trialing_is_read_only_after_it_ends(): void
    {
        $subscription = $this->subscription(['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $this->now->subSecond()]);

        $this->assertSame(AccessState::ReadOnly, $this->resolve($subscription));
    }

    public function test_a_paid_period_in_progress_has_full_access_even_if_the_stored_status_is_expired(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::Expired,
            'current_period_ends_at' => $this->now->addDay(),
        ]);

        $this->assertSame(AccessState::Full, $this->resolve($subscription));
    }

    public function test_after_the_period_ends_grace_gives_full_access_with_a_warning_state(): void
    {
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now->subDay(),
            'grace_ends_at' => $this->now->addDays(6),
        ]);

        $access = $this->resolver->resolve($subscription, $this->now);

        $this->assertSame(AccessState::Grace, $access->state);
        $this->assertTrue($access->state->canWrite());
        $this->assertEquals($this->now->addDays(6), $access->graceEndsAt);
    }

    public function test_the_grace_end_instant_is_already_read_only(): void
    {
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now->subDays(7),
            'grace_ends_at' => $this->now,
        ]);

        $this->assertSame(AccessState::ReadOnly, $this->resolve($subscription));
    }

    public function test_a_period_that_ended_with_no_grace_recorded_is_read_only(): void
    {
        $this->assertSame(AccessState::ReadOnly, $this->resolve($this->subscription(['current_period_ends_at' => $this->now->subSecond()])));
    }

    public function test_the_period_end_instant_is_no_longer_in_the_period(): void
    {
        $subscription = $this->subscription(['current_period_ends_at' => $this->now, 'grace_ends_at' => $this->now->addDays(7)]);

        $this->assertSame(AccessState::Grace, $this->resolve($subscription));
    }

    public function test_a_cancelled_subscription_has_no_grace(): void
    {
        $subscription = $this->subscription([
            'cancel_at_period_end' => true,
            'current_period_ends_at' => $this->now->subHour(),
            'grace_ends_at' => $this->now->addDays(7),
        ]);

        $this->assertSame(AccessState::ReadOnly, $this->resolve($subscription));
    }

    public function test_a_cancelled_subscription_keeps_full_access_until_the_period_ends(): void
    {
        $subscription = $this->subscription(['cancel_at_period_end' => true, 'current_period_ends_at' => $this->now->addHour()]);

        $this->assertSame(AccessState::Full, $this->resolve($subscription));
    }

    public function test_a_stored_end_date_that_has_passed_is_read_only(): void
    {
        $subscription = $this->subscription(['ended_at' => $this->now->subSecond()]);

        $this->assertSame(AccessState::ReadOnly, $this->resolve($subscription));
    }

    public function test_a_future_end_date_does_not_restrict_yet(): void
    {
        $subscription = $this->subscription(['ended_at' => $this->now->addDay()]);

        $this->assertSame(AccessState::Full, $this->resolve($subscription));
    }

    public function test_a_past_due_status_alone_changes_nothing_inside_the_period(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => $this->now->addDays(3),
        ]);

        $this->assertSame(AccessState::Full, $this->resolve($subscription));
    }

    public function test_a_past_due_subscription_is_in_grace_then_read_only(): void
    {
        $subscription = $this->subscription([
            'status' => SubscriptionStatus::PastDue,
            'current_period_ends_at' => $this->now->subDay(),
            'grace_ends_at' => $this->now->addDays(2),
        ]);

        $this->assertSame(AccessState::Grace, $this->resolve($subscription));
        $this->assertSame(AccessState::ReadOnly, $this->resolver->resolve($subscription, $this->now->addDays(2))->state);
    }

    public function test_a_pending_free_plan_does_not_apply_before_the_period_ends(): void
    {
        $paid = $this->plan('10.00', BillingInterval::Month);
        $free = $this->plan();
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now->addDay(),
            'pending_plan_id' => $free->id,
        ], $paid, $free);

        $access = $this->resolver->resolve($subscription, $this->now);

        $this->assertSame($paid, $access->plan());
        $this->assertFalse($access->pendingApplied);
    }

    public function test_a_pending_free_plan_takes_effect_exactly_when_the_period_ends_with_full_access(): void
    {
        $paid = $this->plan('10.00', BillingInterval::Month);
        $free = $this->plan();
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now,
            'grace_ends_at' => $this->now->addDays(7),
            'pending_plan_id' => $free->id,
        ], $paid, $free);

        $access = $this->resolver->resolve($subscription, $this->now);

        $this->assertSame(AccessState::Full, $access->state);
        $this->assertSame($free, $access->plan());
        $this->assertTrue($access->pendingApplied);
    }

    public function test_a_pending_plan_that_is_not_free_is_never_trusted_to_grant_access(): void
    {
        $paid = $this->plan('10.00', BillingInterval::Month);
        $otherPaid = $this->plan('20.00', BillingInterval::Month);
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now->subDay(),
            'pending_plan_id' => $otherPaid->id,
        ], $paid, $otherPaid);

        $this->assertSame(AccessState::ReadOnly, $this->resolve($subscription));
    }

    public function test_a_pending_plan_id_without_a_loaded_plan_falls_back_to_the_normal_rules(): void
    {
        $subscription = $this->subscription([
            'current_period_ends_at' => $this->now->subDay(),
            'grace_ends_at' => $this->now->addDay(),
            'pending_plan_id' => 99,
        ]);

        $this->assertSame(AccessState::Grace, $this->resolve($subscription));
    }

    public function test_the_stored_status_is_never_what_decides(): void
    {
        foreach (SubscriptionStatus::cases() as $status) {
            $running = $this->subscription(['status' => $status, 'current_period_ends_at' => $this->now->addDay()]);
            $over = $this->subscription(['status' => $status, 'current_period_ends_at' => $this->now->subDays(30)]);

            $this->assertSame(AccessState::Full, $this->resolve($running), "running as {$status->value}");
            $this->assertSame(AccessState::ReadOnly, $this->resolve($over), "over as {$status->value}");
        }
    }

    public function test_only_full_and_grace_can_write(): void
    {
        $this->assertTrue(AccessState::Full->canWrite());
        $this->assertTrue(AccessState::Grace->canWrite());
        $this->assertFalse(AccessState::ReadOnly->canWrite());
    }
}
