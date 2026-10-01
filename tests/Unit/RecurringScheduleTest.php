<?php

namespace Tests\Unit;

use App\Enums\RecurringFrequency;
use App\Enums\RecurringInvoiceStatus;
use App\Support\RecurringSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RecurringScheduleTest extends TestCase
{
    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function series(): array
    {
        return [
            'monthly from the 31st clamps to each month end' => ['monthly', '2026-01-31', [
                '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30',
            ]],
            'monthly from the 31st in a leap year' => ['monthly', '2028-01-31', ['2028-01-31', '2028-02-29', '2028-03-31']],
            'monthly from the 30th only clamps in February' => ['monthly', '2026-01-30', ['2026-01-30', '2026-02-28', '2026-03-30', '2026-04-30']],
            'monthly from the 29th' => ['monthly', '2026-01-29', ['2026-01-29', '2026-02-28', '2026-03-29']],
            'monthly from the 1st' => ['monthly', '2026-11-01', ['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01']],
            'monthly across a year end from the 31st' => ['monthly', '2026-12-31', ['2026-12-31', '2027-01-31', '2027-02-28', '2027-03-31']],
            'weekly' => ['weekly', '2026-10-05', ['2026-10-05', '2026-10-12', '2026-10-19', '2026-10-26', '2026-11-02']],
            'weekly across a year end' => ['weekly', '2026-12-28', ['2026-12-28', '2027-01-04', '2027-01-11']],
            'yearly' => ['yearly', '2026-03-15', ['2026-03-15', '2027-03-15', '2028-03-15']],
            'yearly from 29 February falls back to the 28th' => ['yearly', '2028-02-29', [
                '2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29',
            ]],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('series')]
    public function test_occurrences_are_calculated_from_the_start_date(string $frequency, string $start, array $expected): void
    {
        $schedule = RecurringSchedule::of(RecurringFrequency::from($frequency), $start);

        foreach ($expected as $index => $date) {
            $this->assertSame($date, $schedule->occurrence($index)->toDateString(), "occurrence {$index}");
        }
    }

    public function test_the_day_never_drifts_after_a_short_month(): void
    {
        $schedule = RecurringSchedule::of(RecurringFrequency::Monthly, '2026-01-31');

        // Calculated from the start date, not from February's 28th.
        $this->assertSame('2026-03-31', $schedule->after('2026-02-28')->toDateString());
        $this->assertSame('2027-01-31', $schedule->occurrence(12)->toDateString());
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function lookups(): array
    {
        return [
            // frequency, start, date, on-or-after, after
            'before the start' => ['monthly', '2026-01-31', '2025-06-01', '2026-01-31', '2026-01-31'],
            'on the start' => ['monthly', '2026-01-31', '2026-01-31', '2026-01-31', '2026-02-28'],
            'between occurrences' => ['monthly', '2026-01-31', '2026-02-10', '2026-02-28', '2026-02-28'],
            'on a clamped occurrence' => ['monthly', '2026-01-31', '2026-02-28', '2026-02-28', '2026-03-31'],
            'the day after a clamped occurrence' => ['monthly', '2026-01-31', '2026-03-01', '2026-03-31', '2026-03-31'],
            'many months later' => ['monthly', '2026-01-31', '2030-06-15', '2030-06-30', '2030-06-30'],
            'weekly on an occurrence' => ['weekly', '2026-10-05', '2026-10-19', '2026-10-19', '2026-10-26'],
            'weekly between occurrences' => ['weekly', '2026-10-05', '2026-10-20', '2026-10-26', '2026-10-26'],
            'yearly leap day in a non-leap year' => ['yearly', '2028-02-29', '2029-02-28', '2029-02-28', '2030-02-28'],
            'yearly after the clamped day' => ['yearly', '2028-02-29', '2029-03-01', '2030-02-28', '2030-02-28'],
        ];
    }

    #[DataProvider('lookups')]
    public function test_finding_the_next_occurrence(string $frequency, string $start, string $date, string $onOrAfter, string $after): void
    {
        $schedule = RecurringSchedule::of(RecurringFrequency::from($frequency), $start);

        $this->assertSame($onOrAfter, $schedule->onOrAfter($date)->toDateString());
        $this->assertSame($after, $schedule->after($date)->toDateString());
    }

    public function test_counting_occurrences_in_an_inclusive_range(): void
    {
        $monthly = RecurringSchedule::of(RecurringFrequency::Monthly, '2026-01-31');

        $this->assertSame(4, $monthly->countBetween('2026-01-31', '2026-04-30'));
        $this->assertSame(3, $monthly->countBetween('2026-01-31', '2026-04-29'));
        $this->assertSame(1, $monthly->countBetween('2026-02-28', '2026-02-28'));
        $this->assertSame(0, $monthly->countBetween('2026-02-01', '2026-02-27'));
        $this->assertSame(0, $monthly->countBetween('2026-05-01', '2026-04-01'));

        $weekly = RecurringSchedule::of(RecurringFrequency::Weekly, '2026-10-05');
        $this->assertSame(3, $weekly->countBetween('2026-10-05', '2026-10-19'));
        $this->assertSame(2, $weekly->countBetween('2026-10-05', '2026-10-18'));
    }

    public function test_status_transitions(): void
    {
        $allowed = [
            'active' => ['paused', 'cancelled'],
            'paused' => ['active', 'cancelled'],
            'cancelled' => [],
        ];

        foreach (RecurringInvoiceStatus::cases() as $from) {
            foreach (RecurringInvoiceStatus::cases() as $to) {
                $this->assertSame(
                    in_array($to->value, $allowed[$from->value], true),
                    $from->canTransitionTo($to),
                    "{$from->value} -> {$to->value}",
                );
            }
        }
    }
}
