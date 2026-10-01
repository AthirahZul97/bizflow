<?php

namespace App\Support;

use App\Enums\RecurringFrequency;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The dates of a recurring schedule.
 *
 * Occurrence k (k = 0, 1, 2, ...) is always calculated from the start date, never
 * from the previous occurrence, so the series cannot drift:
 *
 * - weekly:  start + 7k days
 * - monthly: start + k months, clamped to the month's last day
 *            (31 Jan -> 28/29 Feb -> 31 Mar -> 30 Apr)
 * - yearly:  start + k years, clamped (29 Feb -> 28 Feb in non-leap years)
 *
 * Dates only; the application timezone decides what "today" is.
 */
final class RecurringSchedule
{
    public function __construct(
        public readonly RecurringFrequency $frequency,
        public readonly CarbonImmutable $start,
    ) {}

    public static function of(RecurringFrequency $frequency, CarbonInterface|string $start): self
    {
        return new self($frequency, CarbonImmutable::parse($start)->startOfDay());
    }

    /**
     * The date of occurrence $index (0 is the start date).
     */
    public function occurrence(int $index): CarbonImmutable
    {
        return match ($this->frequency) {
            RecurringFrequency::Weekly => $this->start->addWeeks($index),
            RecurringFrequency::Monthly => $this->start->addMonthsNoOverflow($index),
            RecurringFrequency::Yearly => $this->start->addYearsNoOverflow($index),
        };
    }

    /**
     * The first occurrence on or after the given date.
     */
    public function onOrAfter(CarbonInterface|string $date): CarbonImmutable
    {
        return $this->occurrence($this->indexOnOrAfter(CarbonImmutable::parse($date)->startOfDay()));
    }

    /**
     * The first occurrence strictly after the given date.
     */
    public function after(CarbonInterface|string $date): CarbonImmutable
    {
        return $this->onOrAfter(CarbonImmutable::parse($date)->startOfDay()->addDay());
    }

    /**
     * How many occurrences fall between $from and $to, both inclusive.
     */
    public function countBetween(CarbonInterface|string $from, CarbonInterface|string $to): int
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();

        if ($to->lt($from)) {
            return 0;
        }

        // Index of the first occurrence after $to, minus the first on or after $from.
        return $this->indexOnOrAfter($to->addDay()) - $this->indexOnOrAfter($from);
    }

    /**
     * The index of the first occurrence on or after the date (0 if the date is before the start).
     */
    private function indexOnOrAfter(CarbonImmutable $date): int
    {
        if ($date->lte($this->start)) {
            return 0;
        }

        // A close estimate, then corrected in either direction (month and year lengths vary).
        $index = match ($this->frequency) {
            RecurringFrequency::Weekly => intdiv((int) $this->start->diffInDays($date), 7),
            RecurringFrequency::Monthly => ($date->year - $this->start->year) * 12 + $date->month - $this->start->month,
            RecurringFrequency::Yearly => $date->year - $this->start->year,
        };
        $index = max(0, $index);

        while ($this->occurrence($index)->lt($date)) {
            $index++;
        }
        while ($index > 0 && $this->occurrence($index - 1)->gte($date)) {
            $index--;
        }

        return $index;
    }
}
