<?php

namespace App\Billing;

use App\Enums\AccessState;
use App\Models\Plan;
use App\Models\Subscription;
use Carbon\CarbonImmutable;

/**
 * The outcome of AccessResolver: what a business may do at one moment and under which plan.
 * Read-only.
 */
final readonly class ResolvedAccess
{
    public function __construct(
        public AccessState $state,
        public ?Subscription $subscription,
        /** Set only when a scheduled downgrade has taken effect: the pending plan replaces the subscription's own. */
        private ?Plan $effectivePlan = null,
        /** When the trial or billing period ends, if it has an end. */
        public ?CarbonImmutable $endsAt = null,
        /** When full-access grace ends; set only while in grace. */
        public ?CarbonImmutable $graceEndsAt = null,
        /** True when a scheduled downgrade's effective period has begun, so plan() is the pending plan. */
        public bool $pendingApplied = false,
        public ?string $reason = null,
    ) {}

    /**
     * The plan whose entitlements apply; null when the business has no subscription.
     *
     * Loaded on demand: deciding access (and the banner on every page) needs only the
     * subscription row, so a page that never asks for entitlements never loads the plan.
     */
    public function plan(): ?Plan
    {
        return $this->effectivePlan ?? $this->subscription?->plan;
    }
}
