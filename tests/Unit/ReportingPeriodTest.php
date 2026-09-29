<?php

namespace Tests\Unit;

use App\Support\ReportingPeriod;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportingPeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Today is Tuesday 29 Sep 2026 in Asia/Kuala_Lumpur.
        $this->travelTo('2026-09-29 10:00:00');
    }

    private function assertPeriod(ReportingPeriod $period, string $key, string $start, string $end, string $endExclusive, string $label, bool $fellBack = false): void
    {
        $this->assertSame(
            [$key, $start, $end, $endExclusive, $label, $fellBack],
            [$period->key, $period->startDate(), $period->endDate(), $period->endExclusive(), $period->label(), $period->fellBack],
        );
    }

    /**
     * Reports: its presets (with last_year) and its this_year default.
     */
    private function report(mixed $period, mixed $from = null, mixed $to = null): ReportingPeriod
    {
        return ReportingPeriod::fromInput($period, $from, $to, ReportingPeriod::REPORT_PRESETS, ReportingPeriod::THIS_YEAR);
    }

    // ---------------------------------------------------------------- Dashboard behaviour (unchanged)

    public function test_default_is_this_month_up_to_today(): void
    {
        $this->assertPeriod(ReportingPeriod::fromInput(null), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
        $this->assertPeriod(ReportingPeriod::fromInput(''), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
        $this->assertPeriod(ReportingPeriod::fromInput('this_month'), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
    }

    public function test_last_month_is_the_whole_previous_month(): void
    {
        $this->assertPeriod(ReportingPeriod::fromInput('last_month'), 'last_month', '2026-08-01', '2026-08-31', '2026-09-01', '1–31 Aug 2026');
    }

    public function test_last_month_across_a_year_boundary(): void
    {
        $this->travelTo('2027-01-15 10:00:00');

        $this->assertPeriod(ReportingPeriod::fromInput('last_month'), 'last_month', '2026-12-01', '2026-12-31', '2027-01-01', '1–31 Dec 2026');
    }

    public function test_last_month_from_the_31st_does_not_overflow(): void
    {
        $this->travelTo('2026-03-31 10:00:00');
        $this->assertPeriod(ReportingPeriod::fromInput('last_month'), 'last_month', '2026-02-01', '2026-02-28', '2026-03-01', '1–28 Feb 2026');

        $this->travelTo('2028-03-31 10:00:00'); // leap year
        $this->assertPeriod(ReportingPeriod::fromInput('last_month'), 'last_month', '2028-02-01', '2028-02-29', '2028-03-01', '1–29 Feb 2028');
    }

    public function test_this_year_runs_from_january_to_today(): void
    {
        $this->assertPeriod(ReportingPeriod::fromInput('this_year'), 'this_year', '2026-01-01', '2026-09-29', '2026-09-30', '1 Jan – 29 Sep 2026');
    }

    public function test_valid_custom_ranges(): void
    {
        $this->assertPeriod(ReportingPeriod::fromInput('custom', '2025-12-15', '2026-01-10'), 'custom', '2025-12-15', '2026-01-10', '2026-01-11', '15 Dec 2025 – 10 Jan 2026');
        $this->assertPeriod(ReportingPeriod::fromInput('custom', '2026-09-05', '2026-09-05'), 'custom', '2026-09-05', '2026-09-05', '2026-09-06', '5 Sep 2026');
        $this->assertPeriod(ReportingPeriod::fromInput('custom', '2026-08-01', '2026-09-29'), 'custom', '2026-08-01', '2026-09-29', '2026-09-30', '1 Aug – 29 Sep 2026');
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
            'from before 2000' => ['custom', '1999-12-31', '2026-09-10'],
            'both before 2000' => ['custom', '1990-01-01', '1999-12-31'],
            'unknown period' => ['decade', null, null],
            'array period' => [['this_year'], null, null],
            'last_year is not a dashboard preset' => ['last_year', null, null],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_dashboard_input_falls_back_to_this_month(mixed $period, mixed $from, mixed $to): void
    {
        $fallback = ReportingPeriod::fromInput($period, $from, $to);

        $this->assertPeriod($fallback, 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026', fellBack: true);
        $this->assertSame('this month', $fallback->defaultLabel());
    }

    public function test_today_follows_the_malaysian_timezone(): void
    {
        // 20:00 UTC on 30 Sep is already 04:00 on 1 Oct in Kuala Lumpur.
        $this->travelTo(now('UTC')->setDate(2026, 9, 30)->setTime(20, 0));

        $this->assertPeriod(ReportingPeriod::fromInput(null), 'this_month', '2026-10-01', '2026-10-01', '2026-10-02', '1 Oct 2026');
    }

    // ---------------------------------------------------------------- shared additions

    public function test_custom_range_may_start_on_the_earliest_date(): void
    {
        $this->assertPeriod(ReportingPeriod::fromInput('custom', '2000-01-01', '2000-01-31'), 'custom', '2000-01-01', '2000-01-31', '2000-02-01', '1–31 Jan 2000');
        $this->assertSame('2000-01-01', ReportingPeriod::EARLIEST_DATE);
    }

    public function test_query_parameters_reproduce_the_period(): void
    {
        $this->assertSame(['period' => 'last_month'], ReportingPeriod::fromInput('last_month')->query());
        $this->assertSame(
            ['period' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-15'],
            ReportingPeriod::fromInput('custom', '2026-07-01', '2026-07-15')->query(),
        );
    }

    // ---------------------------------------------------------------- Reports presets & default

    public function test_reports_default_to_this_year(): void
    {
        $this->assertPeriod($this->report(null), 'this_year', '2026-01-01', '2026-09-29', '2026-09-30', '1 Jan – 29 Sep 2026');
        $this->assertPeriod($this->report(''), 'this_year', '2026-01-01', '2026-09-29', '2026-09-30', '1 Jan – 29 Sep 2026');
    }

    public function test_reports_accept_last_year(): void
    {
        $this->assertPeriod($this->report('last_year'), 'last_year', '2025-01-01', '2025-12-31', '2026-01-01', '1 Jan – 31 Dec 2025');
    }

    public function test_last_year_on_january_first(): void
    {
        $this->travelTo('2027-01-01 00:30:00');

        $this->assertPeriod($this->report('last_year'), 'last_year', '2026-01-01', '2026-12-31', '2027-01-01', '1 Jan – 31 Dec 2026');
        $this->assertPeriod($this->report('this_year'), 'this_year', '2027-01-01', '2027-01-01', '2027-01-02', '1 Jan 2027');
    }

    public function test_last_year_from_a_leap_day_does_not_overflow(): void
    {
        $this->travelTo('2028-02-29 10:00:00');

        $this->assertPeriod($this->report('last_year'), 'last_year', '2027-01-01', '2027-12-31', '2028-01-01', '1 Jan – 31 Dec 2027');
    }

    public function test_reports_still_accept_the_shared_presets_and_custom(): void
    {
        $this->assertPeriod($this->report('this_month'), 'this_month', '2026-09-01', '2026-09-29', '2026-09-30', '1–29 Sep 2026');
        $this->assertPeriod($this->report('last_month'), 'last_month', '2026-08-01', '2026-08-31', '2026-09-01', '1–31 Aug 2026');
        $this->assertPeriod($this->report('custom', '2026-01-15', '2026-03-10'), 'custom', '2026-01-15', '2026-03-10', '2026-03-11', '15 Jan – 10 Mar 2026');
    }

    #[DataProvider('invalidReportInput')]
    public function test_invalid_report_input_falls_back_to_this_year(mixed $period, mixed $from, mixed $to): void
    {
        $fallback = $this->report($period, $from, $to);

        $this->assertPeriod($fallback, 'this_year', '2026-01-01', '2026-09-29', '2026-09-30', '1 Jan – 29 Sep 2026', fellBack: true);
        $this->assertSame('this year', $fallback->defaultLabel());
    }

    /**
     * @return array<string, array{mixed, mixed, mixed}>
     */
    public static function invalidReportInput(): array
    {
        return [
            'from after to' => ['custom', '2026-09-20', '2026-09-10'],
            'to in the future' => ['custom', '2026-09-01', '2026-09-30'],
            'malformed date' => ['custom', '2026/09/01', '2026-09-10'],
            'impossible date' => ['custom', '2026-02-30', '2026-03-10'],
            'array input' => ['custom', ['2026-09-01'], '2026-09-10'],
            'before 2000' => ['custom', '1999-12-31', '2000-01-10'],
            'unknown period' => ['decade', null, null],
        ];
    }
}
