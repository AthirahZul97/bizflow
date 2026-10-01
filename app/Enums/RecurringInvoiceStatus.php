<?php

namespace App\Enums;

/**
 * active <-> paused; active or paused -> cancelled (terminal).
 * "Finished" (past the end date) is derived from the dates, not a status.
 */
enum RecurringInvoiceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Active => $to === self::Paused || $to === self::Cancelled,
            self::Paused => $to === self::Active || $to === self::Cancelled,
            self::Cancelled => false,
        };
    }
}
