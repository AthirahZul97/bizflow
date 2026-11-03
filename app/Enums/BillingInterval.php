<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum BillingInterval: string
{
    case Month = 'month';
    case Year = 'year';

    /**
     * Get the human-readable label for the interval.
     */
    public function label(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
        };
    }

    /**
     * The end of a period that starts at the given moment.
     */
    public function periodEnd(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Month => $start->addMonthNoOverflow(),
            self::Year => $start->addYearNoOverflow(),
        };
    }
}
