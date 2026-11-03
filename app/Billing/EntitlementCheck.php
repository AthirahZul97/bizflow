<?php

namespace App\Billing;

use App\Enums\DenyReason;
use App\Enums\Entitlement;

/**
 * The result of asking whether a business may use an entitlement (optionally "n more").
 * Read-only.
 */
final readonly class EntitlementCheck
{
    public function __construct(
        public Entitlement $entitlement,
        public bool $allowed,
        public ?DenyReason $reason = null,
        /** The plan's limit: null for unlimited, and for flags. */
        public ?int $limit = null,
        public int $used = 0,
    ) {}

    public static function allowed(Entitlement $entitlement, ?int $limit = null, int $used = 0): self
    {
        return new self($entitlement, true, null, $limit, $used);
    }

    public static function denied(Entitlement $entitlement, DenyReason $reason, ?int $limit = null, int $used = 0): self
    {
        return new self($entitlement, false, $reason, $limit, $used);
    }

    /**
     * An upgrade-oriented explanation for the user; empty when allowed.
     */
    public function message(): string
    {
        return match ($this->reason) {
            null => '',
            DenyReason::ReadOnly => 'Your subscription is read-only, so changes are turned off. Review your plan to continue.',
            DenyReason::NotIncluded => "Your plan doesn't include {$this->entitlement->noun()}. Contact us to upgrade your plan.",
            DenyReason::LimitReached => "You've reached your plan's limit of {$this->limit} {$this->entitlement->noun()}. Contact us to upgrade your plan.",
        };
    }
}
