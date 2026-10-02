<?php

namespace App\Enums;

/**
 * The stored lifecycle label of a subscription row. It may lag the dates: access is
 * always derived from the dates by App\Billing\AccessResolver, never from this value.
 *
 * trialing -> active | expired | replaced
 * active   -> active (renewal) | past_due | expired | replaced
 * past_due -> active | expired | replaced
 * expired and replaced are terminal; a new subscription row is created instead.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Expired = 'expired';
    case Replaced = 'replaced';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Trialing => 'Trial',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Expired => 'Expired',
            self::Replaced => 'Replaced',
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::Expired || $this === self::Replaced;
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Trialing => in_array($to, [self::Active, self::Expired, self::Replaced], true),
            self::Active => in_array($to, [self::Active, self::PastDue, self::Expired, self::Replaced], true),
            self::PastDue => in_array($to, [self::Active, self::Expired, self::Replaced], true),
            self::Expired, self::Replaced => false,
        };
    }
}
