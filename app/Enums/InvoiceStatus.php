<?php

namespace App\Enums;

/**
 * Stored invoice lifecycle states. "Overdue" is deliberately not a case: it is
 * derived from an issued invoice's due date (see Invoice::isOverdue()).
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * Get the human-readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The single source of truth for allowed lifecycle transitions.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Draft => $to === self::Issued,
            self::Issued => $to === self::Paid || $to === self::Cancelled,
            self::Paid => $to === self::Issued,
            self::Cancelled => false,
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isIssued(): bool
    {
        return $this === self::Issued;
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isCancelled(): bool
    {
        return $this === self::Cancelled;
    }
}
