<?php

namespace App\Enums;

enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * Get the human-readable label for the frequency.
     */
    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * The noun for one period, e.g. "every month".
     */
    public function period(): string
    {
        return match ($this) {
            self::Weekly => 'week',
            self::Monthly => 'month',
            self::Yearly => 'year',
        };
    }
}
