<?php

namespace App\Enums;

/**
 * Where one uploaded receipt is.
 *
 * queued -> processing -> review      (extracted; waiting for the user)
 *                      -> unreadable  (the OCR ran but found no usable total)
 *                      -> failed      (technical failure; retryable)
 *                      -> queued      (temporary failure, retried automatically)
 * failed | unreadable | review -> queued   (user retry, bounded)
 * failed | unreadable -> review            (manual entry, no OCR)
 * review -> confirmed                      (the one transition that creates an expense)
 * queued | review | failed | unreadable -> discarded
 *
 * Every change is a conditional update (where status = ...), so a duplicated or retried
 * job or request can never repeat a side effect. confirmed and discarded are final.
 */
enum ExpenseReceiptStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Review = 'review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Unreadable = 'unreadable';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Reading receipt',
            self::Review => 'Ready to review',
            self::Confirmed => 'Expense created',
            self::Failed => 'Failed',
            self::Unreadable => 'Not readable',
            self::Discarded => 'Discarded',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Queued => 'text-bg-secondary',
            self::Processing => 'text-bg-primary',
            self::Review => 'text-bg-info',
            self::Confirmed => 'text-bg-success',
            self::Failed => 'text-bg-danger',
            self::Unreadable => 'text-bg-warning',
            self::Discarded => 'text-bg-light border',
        };
    }

    /**
     * Whether a worker has it or is about to.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    public function isFinal(): bool
    {
        return $this === self::Confirmed || $this === self::Discarded;
    }

    /**
     * States the user may retry the OCR from.
     *
     * @return list<self>
     */
    public static function retryable(): array
    {
        return [self::Failed, self::Unreadable, self::Review];
    }

    /**
     * States the user may discard from.
     *
     * @return list<self>
     */
    public static function discardable(): array
    {
        return [self::Queued, self::Review, self::Failed, self::Unreadable];
    }

    /**
     * States an unconfirmed receipt can be left in; the pruning command cleans these up.
     *
     * @return list<self>
     */
    public static function unconfirmed(): array
    {
        return [self::Queued, self::Review, self::Failed, self::Unreadable];
    }
}
