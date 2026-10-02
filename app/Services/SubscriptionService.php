<?php

namespace App\Services;

use App\Billing\AccessResolver;
use App\Billing\EntitlementGuard;
use App\Billing\EntitlementService;
use App\Enums\AccessState;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionException;
use App\Models\Business;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only place subscriptions are written.
 *
 * Every method receives the Business explicitly and runs the same way as recurring invoice
 * generation: lock the business row first, then re-read the current subscription from the
 * database, validate against that, and change it, all in one transaction. The unique
 * (business_id, is_current) key is the final guarantee that a business never has two current
 * subscriptions. A subscription row is never edited into a different plan: a change ends
 * the old row and starts a new one, so history stays true.
 *
 * Methods that act on a particular subscription (renew, markPastDue, expire) take the row the
 * caller believes is current, and refuse if another change has replaced it since (a late
 * provider event or a lost race).
 *
 * Self-service rules (changePlan): only a move to Free is allowed, never to a paid plan, the
 * Trial plan or Legacy, and never away from Legacy. Paid and Legacy assignment is operator
 * (or, later, provider) controlled through activate().
 */
class SubscriptionService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly AccessResolver $resolver,
    ) {}

    /**
     * Start the business's one trial. Only for a business with no current subscription and
     * no earlier trial, which is registration. Trial history is read from the subscription
     * rows (trial_ends_at), so changing rows can't earn a second trial.
     */
    public function startTrial(Business $business, ?User $actor = null): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($business, $actor) {
            if ($current !== null) {
                throw new SubscriptionException('A trial can only start for a business without a subscription.');
            }

            $plan = Plan::latestActive(config('billing.plans.trial'))
                ?? throw new SubscriptionException('The trial plan is not available.');

            return $this->begin($business, $plan, null, $actor, CarbonImmutable::now());
        });
    }

    /**
     * Start a plan immediately, whatever it is: the operator (billing:assign) and, later, the
     * payment provider path. Ends the current subscription as replaced.
     */
    public function activate(Business $business, Plan $plan, ?User $actor = null): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($business, $plan, $actor) {
            $this->ensureChoosable($plan);

            if ($current !== null && $current->plan_id === $plan->getKey() && $this->hasAccess($current)) {
                throw new SubscriptionException("The business is already on the {$plan->name} plan.");
            }

            return $this->begin($business, $plan, $current, $actor, CarbonImmutable::now());
        });
    }

    /**
     * Self-service plan change. Only a move to a Free plan is allowed. From a paid plan whose
     * period is still running it is a downgrade and takes effect when the period ends (the
     * plan is recorded as pending and the current subscription stays current until then);
     * from a trial, an expired or lapsed subscription or another free plan it is immediate.
     */
    public function changePlan(Business $business, Plan $plan, ?User $actor = null): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($business, $plan, $actor) {
            $this->ensureChoosable($plan);

            if ($plan->isPaid() || $plan->isTrial() || $plan->isLegacy()) {
                throw new SubscriptionException('Contact us to upgrade to this plan.');
            }

            if ($current?->plan->isLegacy()) {
                throw new SubscriptionException('Your plan can only be changed by contacting us.');
            }

            $now = CarbonImmutable::now();

            if ($current !== null && $this->hasAccess($current) && $current->plan_id === $plan->getKey()) {
                throw new SubscriptionException("You're already on the {$plan->name} plan.");
            }

            if ($current !== null && $this->paidPeriodRunning($current, $now)) {
                if ($current->pending_plan_id === $plan->getKey()) {
                    return $current;
                }

                // Cancelling and downgrading are alternatives for the same period end.
                $current->forceFill([
                    'pending_plan_id' => $plan->getKey(),
                    'cancel_at_period_end' => false,
                    'canceled_at' => null,
                ])->save();

                return $current;
            }

            return $this->begin($business, $plan, $current, $actor, $now);
        });
    }

    /**
     * Stop a paid subscription renewing: full access continues to the period's end, then the
     * business is read-only (no grace). Reversible with resume() until then.
     */
    public function cancelAtPeriodEnd(Business $business): Subscription
    {
        return $this->locked($business, function (?Subscription $current) {
            $now = CarbonImmutable::now();

            if ($current === null || ! $this->paidPeriodRunning($current, $now)) {
                throw new SubscriptionException('There is no paid subscription to cancel.');
            }

            if ($current->cancel_at_period_end) {
                throw new SubscriptionException('This subscription is already set to cancel at the end of the period.');
            }

            $current->forceFill([
                'cancel_at_period_end' => true,
                'canceled_at' => $now,
                'pending_plan_id' => null,
            ])->save();

            return $current;
        });
    }

    /**
     * Undo a scheduled cancellation or downgrade, while the current period is still running.
     */
    public function resume(Business $business): Subscription
    {
        return $this->locked($business, function (?Subscription $current) {
            $now = CarbonImmutable::now();

            if ($current === null || ! $this->paidPeriodRunning($current, $now)
                || (! $current->cancel_at_period_end && $current->pending_plan_id === null)) {
                throw new SubscriptionException('There is nothing to resume.');
            }

            $current->forceFill([
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'pending_plan_id' => null,
            ])->save();

            return $current;
        });
    }

    /**
     * Start the next billing period (a payment was received): operator or provider path.
     * The period continues from the old end when renewed on time or within grace, otherwise
     * from now. A subscription set to cancel must be resumed first.
     */
    public function renew(Business $business, Subscription $subscription): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($subscription) {
            $this->ensureStillCurrent($current, $subscription);

            $plan = $current->plan;

            if ($plan->billing_interval === null || $current->current_period_ends_at === null || $current->trial_ends_at !== null) {
                throw new SubscriptionException('This subscription has no billing period to renew.');
            }

            if ($current->cancel_at_period_end) {
                throw new SubscriptionException('Resume the subscription before renewing it.');
            }

            if ($current->status->isTerminal() || ($current->ended_at !== null && $current->ended_at->lte(CarbonImmutable::now()))) {
                throw new SubscriptionException('An ended subscription cannot be renewed; start a new one.');
            }

            $now = CarbonImmutable::now();
            $oldEnd = $current->current_period_ends_at->toImmutable();
            $withinCoverage = $now->lt($oldEnd) || ($current->grace_ends_at !== null && $now->lt($current->grace_ends_at));
            $start = $withinCoverage ? $oldEnd : $now;
            $end = $plan->billing_interval->periodEnd($start);

            $current->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_starts_at' => $start,
                'current_period_ends_at' => $end,
                'grace_ends_at' => $end->addDays(config('billing.grace_days')),
            ])->save();

            return $current;
        });
    }

    /**
     * Record a failed payment: full access continues through the grace window.
     */
    public function markPastDue(Business $business, Subscription $subscription): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($subscription) {
            $this->ensureStillCurrent($current, $subscription);

            if ($current->current_period_ends_at === null || $current->trial_ends_at !== null) {
                throw new SubscriptionException('Only a paid subscription can be past due.');
            }

            if (! $current->status->canTransitionTo(SubscriptionStatus::PastDue)) {
                throw new SubscriptionException('This subscription cannot become past due.');
            }

            $now = CarbonImmutable::now();
            $from = $current->current_period_ends_at->toImmutable()->max($now);

            $current->forceFill([
                'status' => SubscriptionStatus::PastDue,
                'grace_ends_at' => $from->addDays(config('billing.grace_days')),
            ])->save();

            return $current;
        });
    }

    /**
     * Record that the subscription has ended (trial over, or period and grace over). It only
     * confirms what the dates already say: it refuses while access is still available. The
     * row stays current, now read-only, until a new plan is chosen; no business data changes.
     * A scheduled downgrade is settled instead.
     */
    public function expire(Business $business, Subscription $subscription): Subscription
    {
        return $this->locked($business, function (?Subscription $current) use ($subscription) {
            $this->ensureStillCurrent($current, $subscription);

            if ($current->status->isTerminal()) {
                return $current;
            }

            $access = $this->resolver->resolve($current->load('plan', 'pendingPlan'), CarbonImmutable::now());

            if ($access->state !== AccessState::ReadOnly) {
                throw new SubscriptionException('This subscription has not ended yet.');
            }

            $endedAt = ($current->trial_ends_at ?? $current->current_period_ends_at ?? CarbonImmutable::now());

            $current->forceFill([
                'status' => SubscriptionStatus::Expired,
                'ended_at' => $endedAt,
                'end_reason' => $current->cancel_at_period_end ? 'canceled' : 'expired',
            ])->save();

            return $current;
        });
    }

    /**
     * Persist a scheduled downgrade whose period has ended. Happens automatically inside every
     * other change; calling it directly is only needed to tidy history. Returns the new
     * current subscription, or null when there was nothing to settle.
     */
    public function settle(Business $business): ?Subscription
    {
        return DB::transaction(function () use ($business) {
            EntitlementGuard::lock($business);

            $current = $this->currentLocked($business);

            return $current !== null && $this->pendingDue($current, CarbonImmutable::now())
                ? $this->settleLocked($business, $current)
                : null;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /**
     * Run a change under the business row lock with the freshly re-read, settled current row.
     *
     * @template T
     *
     * @param  callable(?Subscription): T  $change
     * @return T
     */
    private function locked(Business $business, callable $change): mixed
    {
        try {
            return DB::transaction(function () use ($business, $change) {
                EntitlementGuard::lock($business);

                $current = $this->currentLocked($business);

                if ($current !== null && $this->pendingDue($current, CarbonImmutable::now())) {
                    $current = $this->settleLocked($business, $current);
                }

                return $change($current);
            }, self::TRANSACTION_ATTEMPTS);
        } finally {
            $this->entitlements->forget($business);
        }
    }

    private function currentLocked(Business $business): ?Subscription
    {
        return $business->currentSubscription()->with(['plan', 'pendingPlan'])->lockForUpdate()->first();
    }

    /**
     * End $current at its period end and start the pending plan exactly there.
     */
    private function settleLocked(Business $business, Subscription $current): Subscription
    {
        $pending = $current->pendingPlan;
        $boundary = $current->current_period_ends_at->toImmutable();

        $current->forceFill([
            'status' => SubscriptionStatus::Replaced,
            'is_current' => null,
            'ended_at' => $boundary,
            'end_reason' => 'replaced',
        ])->save();

        return $this->start($business, $pending, $boundary, null);
    }

    /**
     * End $old (if any) as replaced, now, and start $plan as the new current subscription.
     */
    private function begin(Business $business, Plan $plan, ?Subscription $old, ?User $actor, CarbonImmutable $now): Subscription
    {
        if ($plan->isTrial()) {
            if ($business->subscriptions()->whereNotNull('trial_ends_at')->exists()) {
                throw new SubscriptionException('This business has already used its free trial.');
            }

            if (! $plan->trial_days) {
                throw new SubscriptionException('The trial plan has no trial length.');
            }
        }

        if ($old !== null) {
            $old->forceFill($old->status->isTerminal()
                ? ['is_current' => null]
                : [
                    'status' => SubscriptionStatus::Replaced,
                    'is_current' => null,
                    'ended_at' => $now,
                    'end_reason' => 'replaced',
                ])->save();
        }

        return $this->start($business, $plan, $now, $actor);
    }

    /**
     * Insert a new current subscription on $plan starting at $at. The caller has already
     * cleared is_current on the previous row (the unique key would refuse otherwise).
     */
    private function start(Business $business, Plan $plan, CarbonImmutable $at, ?User $actor): Subscription
    {
        $values = ['plan_id' => $plan->getKey(), 'is_current' => 1, 'started_at' => $at, 'cancel_at_period_end' => false];

        if ($plan->isTrial()) {
            $values += [
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => $at->addDays($plan->trial_days),
            ];
        } elseif ($plan->billing_interval !== null) {
            $end = $plan->billing_interval->periodEnd($at);
            $values += [
                'status' => SubscriptionStatus::Active,
                'current_period_starts_at' => $at,
                'current_period_ends_at' => $end,
                'grace_ends_at' => $end->addDays(config('billing.grace_days')),
            ];
        } else {
            $values += ['status' => SubscriptionStatus::Active];
        }

        $subscription = $business->subscriptions()->make();
        $subscription->forceFill($values + ['created_by' => $actor?->getKey()])->save();

        return $subscription->setRelation('plan', $plan);
    }

    private function ensureChoosable(Plan $plan): void
    {
        if (! $plan->exists || ! $plan->is_active) {
            throw new SubscriptionException('That plan is not available.');
        }

        if ($plan->isPaid() && $plan->billing_interval === null) {
            throw new SubscriptionException('That plan has no billing interval.');
        }
    }

    private function ensureStillCurrent(?Subscription $current, Subscription $subscription): void
    {
        if ($current === null || $current->getKey() !== $subscription->getKey()) {
            throw new SubscriptionException('The subscription has changed; reload and try again.');
        }
    }

    /**
     * Whether the subscription still gives the business access, judged from its dates.
     */
    private function hasAccess(Subscription $subscription): bool
    {
        return $this->resolver->resolve($subscription, CarbonImmutable::now())->state !== AccessState::ReadOnly;
    }

    /**
     * A paid, non-trial subscription whose billing period has not ended yet.
     */
    private function paidPeriodRunning(Subscription $subscription, CarbonImmutable $now): bool
    {
        return $subscription->trial_ends_at === null
            && ! $subscription->status->isTerminal()
            && $subscription->current_period_ends_at !== null
            && $subscription->current_period_ends_at->gt($now);
    }

    private function pendingDue(Subscription $subscription, CarbonImmutable $now): bool
    {
        return $subscription->pending_plan_id !== null
            && $subscription->current_period_ends_at !== null
            && $subscription->current_period_ends_at->lte($now);
    }
}
