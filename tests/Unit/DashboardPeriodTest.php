<?php

namespace Tests\Unit;

use App\Support\DashboardPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Today is Tuesday 29 Sep 2026 in Asia/Kuala_Lumpur.
        $this->travelTo('2026-09-29 10:00:00');
    }

    private function assertPeriod(DashboardPeriod $period, string $key, string $start, string $end, string $endExclusive, string $label, bool $fellBack = false): void
    {
        $this->assertSame(
            [$key, $start, $end, $endExclusive, $label, $fellBack],
            [$period->key, $period->startDate(), $period->endDate(), $period->endExclusive(), $period->label(), $period->fellBack],
        );
    }

    public function test_default_is_this_month_up_to_today(): void
    {
        $this->assertPeriod(DashboardPeriod::fromInput(null), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
        $this->assertPeriod(DashboardPeriod::fromInput(''), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
        $this->assertPeriod(DashboardPeriod::fromInput('this_month'), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
    }

    public function test_last_month_is_the_whole_previous_month(): void
    {
        $this->assertPeriod(DashboardPeriod::fromInput('last_month'), 'last_month', '2026-08-01', '2026-08-31', '2026-09-01', '1–31 Aug 2026');
    }

    public function test_last_month_across_a_year_boundary(): void
    {
        $this->travelTo('2027-01-15 10:00:00');

        $this->assertPeriod(DashboardPeriod::fromInput('last_month'), 'last_month', '2026-12-01', '2026-12-31', '2027-01-01', '1–31 Dec 2026');
    }

    public function test_last_month_from_the_31st_does_not_overflow(): void
    {
        $this->travelTo('2026-03-31 10:00:00');
        $this->assertPeriod(DashboardPeriod::fromInput('last_month'), 'last_month', '2026-02-01', '2026-02-28', '2026-03-01', '1–28 Feb 2026');

        $this->travelTo('2028-03-31 10:00:00'); // leap year
        $this->assertPeriod(DashboardPeriod::fromInput('last_month'), 'last_month', '2028-02-01', '2028-02-29', '2028-03-01', '1–29 Feb 2028');
    }

    public function test_this_year_runs_from_january_to_today(): void
    {
        $this->assertPeriod(DashboardPeriod::fromInput('this_year'), 'this_year', '2026-01-01', '2026-09-29', '2026-09-30', '1 Jan – 29 Sep 2026');
    }

    public function test_valid_custom_ranges(): void
    {
        $this->assertPeriod(DashboardPeriod::fromInput('custom', '2025-12-15', '2026-01-10'), 'custom', '2025-12-15', '2026-01-10', '2026-01-11', '15 Dec 2025 – 10 Jan 2026');
        $this->assertPeriod(DashboardPeriod::fromInput('custom', '2026-09-05', '2026-09-05'), 'custom', '2026-09-05', '2026-09-05', '2026-09-06', '5 Sep 2026');
        $this->assertPeriod(DashboardPeriod::fromInput('custom', '2026-08-01', '2026-09-29'), 'custom', '2026-08-01', '2026-09-29', '2026-09-30', '1 Aug – 29 Sep 2026');
    }

    /**
     * @return array<string, array{mixed, mixed, mixed}>
     */
    public static function invalidInput(): array
    {
        return [
            'custom without dates' => ['custom', null, null],
            'missing from' => ['custom', null, '2026-09-10'],
            'missing to' => ['custom', '2026-09-01', null],
            'from after to' => ['custom', '2026-09-20', '2026-09-10'],
            'to in the future' => ['custom', '2026-09-01', '2026-09-30'],
            'malformed date' => ['custom', '2026/09/01', '2026-09-10'],
            'impossible date' => ['custom', '2026-02-30', '2026-03-10'],
            'text date' => ['custom', 'yesterday', 'today'],
            'array input' => ['custom', ['2026-09-01'], '2026-09-10'],
            'unknown period' => ['decade', null, null],
            'array period' => [['this_year'], null, null],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_falls_back_to_this_month(mixed $period, mixed $from, mixed $to): void
    {
        $this->assertPeriod(DashboardPeriod::fromInput($period, $from, $to), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026', fellBack: true);
    }

    public function test_today_follows_the_malaysian_timezone(): void
    {
        // 20:00 UTC on 30 Sep is already 04:00 on 1 Oct in Kuala Lumpur.
        $this->travelTo(now('UTC')->setDate(2026, 9, 30)->setTime(20, 0));

        $this->assertPeriod(DashboardPeriod::fromInput(null), 'this_month', '2026-10-01', '2026-10-01', '2026-10-02', '1 Oct 2026');
    }
}
