<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds subscription rows directly for tests that need one in a given state.
 * Real ones are always written through SubscriptionService.
 *
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * Define the model's default state: the current Legacy subscription, like a backfilled
     * business has (no expiry, no limits).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'plan_id' => fn () => PlanFactory::seeded('legacy')->getKey(),
            'status' => SubscriptionStatus::Active,
            'is_current' => 1,
            'started_at' => now(),
            'cancel_at_period_end' => false,
        ];
    }

    /**
     * A trial that ends at the given moment (default: 14 days from now).
     */
    public function trial(?CarbonInterface $endsAt = null): static
    {
        return $this->state(fn () => [
            'plan_id' => PlanFactory::seeded('trial')->getKey(),
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => $endsAt ?? now()->addDays(14),
        ]);
    }

    public function free(): static
    {
        return $this->state(fn () => ['plan_id' => PlanFactory::seeded('free')->getKey()]);
    }

    /**
     * An active subscription to the given plan with a billing period, and the grace
     * window that follows it.
     */
    public function onPlan(Plan $plan, ?CarbonInterface $periodStart = null, ?CarbonInterface $periodEnd = null): static
    {
        return $this->state(function () use ($plan, $periodStart, $periodEnd) {
            $start = $periodStart ?? now();
            $end = $periodEnd ?? ($plan->billing_interval?->periodEnd($start->toImmutable()));

            return [
                'plan_id' => $plan->getKey(),
                'status' => SubscriptionStatus::Active,
                'started_at' => $start,
                'current_period_starts_at' => $end === null ? null : $start,
                'current_period_ends_at' => $end,
                'grace_ends_at' => $end?->copy()->addDays(config('billing.grace_days')),
            ];
        });
    }

    /**
     * An ended history row (not current).
     */
    public function ended(string $reason = 'replaced', ?CarbonInterface $at = null): static
    {
        return $this->state(fn () => [
            'status' => $reason === 'replaced' ? SubscriptionStatus::Replaced : SubscriptionStatus::Expired,
            'is_current' => null,
            'ended_at' => $at ?? now(),
            'end_reason' => $reason,
        ]);
    }
}
