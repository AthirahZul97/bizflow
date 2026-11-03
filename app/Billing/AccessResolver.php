<?php

namespace App\Billing;

use App\Enums\AccessState;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * Derives what a business may do from its current subscription's dates and plan.
 *
 * It never looks at the stored status: that may lag (nothing sweeps it), so the dates are
 * the truth. It is a pure function of the subscription (with its plan and pending plan
 * loaded) and the moment, which keeps every rule unit-testable.
 *
 *  - no current subscription ........................... read-only
 *  - ended (ended_at has passed) ........................ read-only
 *  - trial: before trial_ends_at full, then read-only (no grace)
 *  - no period end (Legacy, Free) ....................... full
 *  - before current_period_ends_at ...................... full
 *  - period over, a free pending plan scheduled ......... full, under the pending plan
 *  - period over, not cancelled, before grace_ends_at ... grace (full access plus a warning)
 *  - otherwise .......................................... read-only
 *
 * The pending plan is not a second source of truth: it only substitutes the plan once the
 * current period has actually ended, exactly when settlement would start its own row.
 */
class AccessResolver
{
    public function resolve(?Subscription $subscription, ?CarbonImmutable $now = null): ResolvedAccess
    {
        $now ??= CarbonImmutable::now();

        if ($subscription === null) {
            return new ResolvedAccess(AccessState::ReadOnly, null, reason: 'no_subscription');
        }

        if ($subscription->ended_at !== null && $subscription->ended_at->lte($now)) {
            return new ResolvedAccess(AccessState::ReadOnly, $subscription, endsAt: $subscription->ended_at->toImmutable(), reason: 'ended');
        }

        if ($subscription->trial_ends_at !== null) {
            $ends = $subscription->trial_ends_at->toImmutable();

            return $now->lt($ends)
                ? new ResolvedAccess(AccessState::Full, $subscription, endsAt: $ends, reason: 'trial')
                : new ResolvedAccess(AccessState::ReadOnly, $subscription, endsAt: $ends, reason: 'trial_ended');
        }

        if ($subscription->current_period_ends_at === null) {
            return new ResolvedAccess(AccessState::Full, $subscription, reason: 'open_ended');
        }

        $end = $subscription->current_period_ends_at->toImmutable();

        if ($now->lt($end)) {
            return new ResolvedAccess(AccessState::Full, $subscription, endsAt: $end, reason: 'in_period');
        }

        $pending = $subscription->pendingPlan;

        if ($subscription->pending_plan_id !== null && $pending !== null) {
            // Only a plan without a billing period can be scheduled (the service guarantees it);
            // anything else cannot be trusted to be paid for, so it grants nothing.
            return $pending->isFree() && $pending->billing_interval === null
                ? new ResolvedAccess(AccessState::Full, $subscription, $pending, endsAt: $end, pendingApplied: true, reason: 'pending_plan')
                : new ResolvedAccess(AccessState::ReadOnly, $subscription, endsAt: $end, reason: 'pending_plan_unpaid');
        }

        $graceEnd = $subscription->grace_ends_at?->toImmutable();

        if (! $subscription->cancel_at_period_end && $graceEnd !== null && $now->lt($graceEnd)) {
            return new ResolvedAccess(AccessState::Grace, $subscription, endsAt: $end, graceEndsAt: $graceEnd, reason: 'grace');
        }

        return new ResolvedAccess(AccessState::ReadOnly, $subscription, endsAt: $end, reason: 'period_ended');
    }
}
