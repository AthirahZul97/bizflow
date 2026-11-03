<?php

namespace App\Billing;

use App\Enums\AccessState;
use App\Enums\DenyReason;
use App\Enums\Entitlement;
use App\Models\Business;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one business may do right now: its derived access state plus the entitlements of the
 * plan that applies. Built by EntitlementService; read-only.
 *
 * allows() and check() always include the access state, so a read-only business is denied
 * every write whatever its plan says. Usage is counted live from the business's own data on
 * each call, never cached here.
 */
final readonly class Entitlements
{
    public function __construct(
        private Business $business,
        private ResolvedAccess $resolved,
        private CarbonImmutable $evaluatedAt,
    ) {}

    public function access(): AccessState
    {
        return $this->resolved->state;
    }

    public function canWrite(): bool
    {
        return $this->resolved->state->canWrite();
    }

    public function resolved(): ResolvedAccess
    {
        return $this->resolved;
    }

    /**
     * The plan whose entitlements apply now; null when the business has no subscription.
     */
    public function plan(): ?Plan
    {
        return $this->resolved->plan();
    }

    public function evaluatedAt(): CarbonImmutable
    {
        return $this->evaluatedAt;
    }

    /**
     * Whether the entitlement is available at all: write access, and the plan grants it
     * (a flag set to true, or a limit that is not 0). Does not look at usage; see check().
     */
    public function allows(Entitlement $entitlement): bool
    {
        return $this->check($entitlement, 0)->allowed;
    }

    /**
     * The plan's limit for a limited entitlement: null is unlimited, 0 is not included, and
     * an entitlement the plan does not mention is 0.
     */
    public function limit(Entitlement $entitlement): ?int
    {
        $this->ensureLimit($entitlement);

        $plan = $this->resolved->plan();

        if ($plan === null) {
            return 0;
        }

        $value = $plan->valueOf($entitlement);

        return is_int($value) ? $value : null;
    }

    /**
     * What the business uses now, counted live.
     */
    public function used(Entitlement $entitlement): int
    {
        $this->ensureLimit($entitlement);

        return $entitlement->meter()->used($this->business);
    }

    /**
     * How many more the plan allows: null when unlimited, never negative (a business above
     * its limit after a downgrade has 0).
     */
    public function remaining(Entitlement $entitlement): ?int
    {
        $limit = $this->limit($entitlement);

        return $limit === null ? null : max(0, $limit - $this->used($entitlement));
    }

    /**
     * Whether the business may add $adding more of a limited entitlement, or use a flag.
     *
     * Denied when read-only, when the plan doesn't include it, or when it would pass the
     * limit. A business already above a limit (after a downgrade) keeps its records but can't
     * add more until usage falls below the limit.
     */
    public function check(Entitlement $entitlement, int $adding = 1): EntitlementCheck
    {
        if (! $this->canWrite()) {
            return EntitlementCheck::denied($entitlement, DenyReason::ReadOnly);
        }

        // Not "?->valueOf() ??": null is a real value (unlimited), and must not fall back to the default.
        $plan = $this->resolved->plan();
        $value = $plan === null ? $entitlement->default() : $plan->valueOf($entitlement);

        if ($entitlement->isFlag()) {
            return $value === true
                ? EntitlementCheck::allowed($entitlement)
                : EntitlementCheck::denied($entitlement, DenyReason::NotIncluded);
        }

        if ($value === null) {
            return EntitlementCheck::allowed($entitlement);
        }

        if ($value === 0) {
            return EntitlementCheck::denied($entitlement, DenyReason::NotIncluded, 0);
        }

        if ($adding <= 0) {
            return EntitlementCheck::allowed($entitlement, $value);
        }

        $used = $this->used($entitlement);

        return $used + $adding > $value
            ? EntitlementCheck::denied($entitlement, DenyReason::LimitReached, $value, $used)
            : EntitlementCheck::allowed($entitlement, $value, $used);
    }

    private function ensureLimit(Entitlement $entitlement): void
    {
        if (! $entitlement->isLimit()) {
            throw new InvalidArgumentException("{$entitlement->value} is a flag, not a limit.");
        }
    }
}
