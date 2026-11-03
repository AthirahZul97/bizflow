<?php

namespace Tests\Feature\Billing;

use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\PlanFactory;
use Database\Factories\SubscriptionFactory;

/**
 * Helpers for tests that need a business in a given subscription state. They write rows
 * directly (like the factories) so a test can build any state, including ones the service
 * would never produce, such as a stale stored status.
 */
trait ManagesSubscriptions
{
    protected function businessFor(User|Business $owner): Business
    {
        return $owner instanceof Business ? $owner : $this->businessOf($owner);
    }

    /**
     * Replace the business's current subscription with a new one built by the callback
     * (which receives a SubscriptionFactory for that business).
     *
     * @param  callable(SubscriptionFactory): SubscriptionFactory  $build
     */
    protected function subscribe(User|Business $owner, callable $build): Subscription
    {
        $business = $this->businessFor($owner);

        Subscription::query()->where('business_id', $business->getKey())->where('is_current', 1)
            ->update(['is_current' => null, 'status' => 'replaced', 'ended_at' => now(), 'end_reason' => 'replaced']);

        return $build(Subscription::factory()->for($business))->create();
    }

    /**
     * A plan with the given entitlements (any not listed are absent, so denied).
     *
     * @param  array<string, int|bool|null>  $entitlements
     */
    protected function planWith(array $entitlements, string $price = '0.00'): Plan
    {
        $factory = Plan::factory()->entitlements($entitlements);

        return ($price === '0.00' ? $factory : $factory->monthly($price))->create();
    }

    /**
     * Put the business on a free-style plan with the given limits and no period end.
     *
     * @param  array<string, int|bool|null>  $entitlements
     */
    protected function limitTo(User|Business $owner, array $entitlements): Plan
    {
        $plan = $this->planWith($entitlements + [
            'customers.max' => null,
            'products.max' => null,
            'invoices.monthly_max' => null,
            'recurring_invoices.max' => null,
            'team.seats' => null,
            'invoices.email' => true,
            'expenses.ocr_monthly_max' => null,
        ]);
        $this->subscribe($owner, fn (SubscriptionFactory $f) => $f->state(['plan_id' => $plan->getKey()]));

        return $plan;
    }

    /**
     * A trial that ended a day ago: read-only, with no grace.
     */
    protected function makeReadOnly(User|Business $owner): Subscription
    {
        return $this->subscribe($owner, fn (SubscriptionFactory $f) => $f->trial(now()->subDay()));
    }

    /**
     * A paid period that ended $daysAgo days ago on a monthly plan (grace is 7 days).
     */
    protected function lapsedPaid(User|Business $owner, int $daysAgo): Subscription
    {
        $plan = Plan::factory()->monthly()->unlimited()->create();
        $end = now()->subDays($daysAgo);

        return $this->subscribe($owner, fn (SubscriptionFactory $f) => $f->onPlan($plan, $end->copy()->subMonth(), $end));
    }

    /**
     * An active paid subscription with $daysLeft days left in its period.
     */
    protected function paidWithDaysLeft(User|Business $owner, int $daysLeft = 20, ?Plan $plan = null): Subscription
    {
        $plan ??= Plan::factory()->monthly()->unlimited()->create();
        $end = now()->addDays($daysLeft);

        return $this->subscribe($owner, fn (SubscriptionFactory $f) => $f->onPlan($plan, $end->copy()->subMonth(), $end));
    }

    protected function freePlan(): Plan
    {
        return PlanFactory::seeded('free');
    }

    /**
     * Move the clock; the subscription rows are judged by the dates, never by stored status.
     */
    protected function at(CarbonInterface|string $when): void
    {
        $this->travelTo($when);
    }
}
