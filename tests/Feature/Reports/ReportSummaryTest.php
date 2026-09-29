<?php

namespace Tests\Feature\Reports;

use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportSummaryTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private function summary(array $query = []): TestResponse
    {
        return $this->report('reports.summary', $query);
    }

    // ---------------------------------------------------------------- definitions

    public function test_received_uses_paid_at_and_invoiced_uses_issue_date(): void
    {
        // Issued last year, paid this year.
        $this->invoice('paid', ['issue_date' => '2025-12-10', 'paid_at' => '2026-01-05', 'total' => '100.00']);

        $thisYear = $this->summary();
        $this->assertSame('RM 100.00', $this->card($thisYear, 'received'));
        $this->assertSame('RM 0.00', $this->card($thisYear, 'invoiced'));

        $lastYear = $this->summary(['period' => 'last_year']);
        $this->assertSame('RM 0.00', $this->card($lastYear, 'received'));
        $this->assertSame('RM 100.00', $this->card($lastYear, 'invoiced'));
    }

    public function test_drafts_cancelled_and_unpaid_invoices_are_not_received(): void
    {
        $this->invoice('draft', ['issue_date' => '2026-03-01', 'total' => '1000.00']);
        $this->invoice('cancelled', ['issue_date' => '2026-03-01', 'total' => '2000.00']);
        $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => '2026-12-31', 'total' => '30.00']);
        $unpaid = $this->invoice('paid', ['issue_date' => '2026-03-01', 'paid_at' => '2026-03-02', 'total' => '40.00']);
        app(InvoiceService::class)->markUnpaid($unpaid);

        $response = $this->summary();

        $this->assertSame('RM 0.00', $this->card($response, 'received'));
        $this->assertSame('RM 70.00', $this->card($response, 'invoiced'));
        $response->assertSee('2 invoices issued')->assertSee('0 invoices marked paid');
    }

    public function test_expenses_use_expense_date_with_both_ends_included(): void
    {
        $this->expense(['expense_date' => '2025-12-31', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-01-01', 'amount' => '10.00']);
        $this->expense(['expense_date' => '2026-09-29', 'amount' => '100.00']);

        $this->assertSame('RM 110.00', $this->card($this->summary(), 'expenses'));
        $this->assertSame('RM 1.00', $this->card($this->summary(['period' => 'last_year']), 'expenses'));
    }

    public function test_net_cash_is_exact_and_negative_values_show_a_red_minus(): void
    {
        $this->invoice('paid', ['paid_at' => '2026-02-01', 'total' => '0.10']);
        $this->invoice('paid', ['paid_at' => '2026-02-02', 'total' => '0.20']);
        $this->expense(['expense_date' => '2026-02-03', 'amount' => '0.05']);

        $response = $this->summary();
        $this->assertSame('RM 0.30', $this->card($response, 'received'));
        $this->assertSame('RM 0.25', $this->card($response, 'net-cash'));

        $this->expense(['expense_date' => '2026-02-04', 'amount' => '1234.75']);
        $response = $this->summary();
        $this->assertSame('-RM 1,234.50', $this->card($response, 'net-cash'));
        $this->assertMatchesRegularExpression('/data-report-card="net-cash".*?class="fs-4 fw-semibold text-danger"/s', $response->getContent());
    }

    // ---------------------------------------------------------------- monthly table

    public function test_monthly_rows_cover_every_month_oldest_first_with_empty_months(): void
    {
        $this->invoice('paid', ['issue_date' => '2026-03-02', 'paid_at' => '2026-03-20', 'total' => '100.00']);
        $this->invoice('issued', ['issue_date' => '2026-03-05', 'due_date' => '2026-12-31', 'total' => '200.00']);
        $this->expense(['expense_date' => '2026-03-10', 'amount' => '15.00']);
        $this->expense(['expense_date' => '2026-03-11', 'amount' => '25.00']);
        $this->expense(['expense_date' => '2026-06-01', 'amount' => '75.00']);

        $response = $this->summary();

        $response->assertSeeInOrder(array_map(fn ($m) => 'data-month="2026-'.$m.'"', ['01', '02', '03', '04', '05', '06', '07', '08', '09']), false);
        $this->assertSame('Jan 2026 RM 0.00 (0) RM 0.00 (0) RM 0.00 (0) RM 0.00', $this->block($response, 'data-month="2026-01"'));
        $this->assertSame('Mar 2026 RM 100.00 (1) RM 300.00 (2) RM 40.00 (2) RM 60.00', $this->block($response, 'data-month="2026-03"'));
        $this->assertSame('Jun 2026 RM 0.00 (0) RM 0.00 (0) RM 75.00 (1) -RM 75.00', $this->block($response, 'data-month="2026-06"'));
        $this->assertSame('1–29 Sep 2026 RM 0.00 (0) RM 0.00 (0) RM 0.00 (0) RM 0.00', $this->block($response, 'data-month="2026-09"'));
        $this->assertSame('Total RM 100.00 (1) RM 300.00 (2) RM 115.00 (3) -RM 15.00', $this->block($response, 'data-month="total"'));
        $this->assertMatchesRegularExpression('/data-month="2026-06".*?text-danger/s', $response->getContent());
    }

    public function test_custom_range_clips_the_first_and_last_months(): void
    {
        $this->expense(['expense_date' => '2026-01-14', 'amount' => '1.00']);   // before the range
        $this->expense(['expense_date' => '2026-01-15', 'amount' => '10.00']);  // first day
        $this->expense(['expense_date' => '2026-02-20', 'amount' => '20.00']);
        $this->expense(['expense_date' => '2026-03-10', 'amount' => '30.00']);  // last day
        $this->expense(['expense_date' => '2026-03-11', 'amount' => '2.00']);   // after the range

        $response = $this->summary(['period' => 'custom', 'from' => '2026-01-15', 'to' => '2026-03-10']);

        $this->assertStringStartsWith('15–31 Jan 2026', $this->block($response, 'data-month="2026-01"'));
        $this->assertStringStartsWith('Feb 2026', $this->block($response, 'data-month="2026-02"'));
        $this->assertStringStartsWith('1–10 Mar 2026', $this->block($response, 'data-month="2026-03"'));
        $response->assertDontSee('data-month="2026-04"', false)->assertDontSee('data-month="2025-12"', false);
        $this->assertSame('RM 60.00', $this->card($response, 'expenses'));
        $this->assertStringContainsString('RM 10.00 (1)', $this->block($response, 'data-month="2026-01"'));
        $this->assertStringContainsString('RM 30.00 (1)', $this->block($response, 'data-month="2026-03"'));
    }

    public function test_totals_row_equals_the_cards(): void
    {
        foreach (['2026-01-31', '2026-02-01', '2026-05-15', '2026-09-29'] as $i => $date) {
            $this->invoice('paid', ['issue_date' => $date, 'paid_at' => $date, 'total' => '1'.$i.'.1'.$i]);
            $this->invoice('issued', ['issue_date' => $date, 'due_date' => '2026-12-31', 'total' => '2'.$i.'.0'.$i]);
            $this->expense(['expense_date' => $date, 'amount' => '0.3'.$i]);
        }

        $response = $this->summary();
        $total = $this->block($response, 'data-month="total"');

        foreach (['received', 'invoiced', 'expenses', 'net-cash'] as $card) {
            $this->assertStringContainsString($this->card($response, $card), $total, $card);
        }
    }

    public function test_months_cross_a_year_boundary(): void
    {
        $this->invoice('paid', ['issue_date' => '2025-12-31', 'paid_at' => '2025-12-31', 'total' => '5.00']);
        $this->invoice('paid', ['issue_date' => '2026-01-01', 'paid_at' => '2026-01-01', 'total' => '7.00']);

        $response = $this->summary(['period' => 'custom', 'from' => '2025-11-15', 'to' => '2026-02-10']);

        $response->assertSeeInOrder(['data-month="2025-11"', 'data-month="2025-12"', 'data-month="2026-01"', 'data-month="2026-02"'], false);
        $this->assertStringStartsWith('Dec 2025 RM 5.00 (1)', $this->block($response, 'data-month="2025-12"'));
        $this->assertStringStartsWith('Jan 2026 RM 7.00 (1)', $this->block($response, 'data-month="2026-01"'));
    }

    public function test_leap_february_is_one_full_month_with_a_hint(): void
    {
        $this->travelTo('2028-03-15 10:00:00');
        $this->expense(['expense_date' => '2028-02-29', 'amount' => '29.00']);

        $response = $this->summary(['period' => 'custom', 'from' => '2028-02-01', 'to' => '2028-02-29']);

        $this->assertSame('Feb 2028 RM 0.00 (0) RM 0.00 (0) RM 29.00 (1) -RM 29.00', $this->block($response, 'data-month="2028-02"'));
        $response->assertSee('Choose This year or a custom range to compare months.');
    }

    // ---------------------------------------------------------------- as of today

    public function test_outstanding_and_overdue_ignore_the_period(): void
    {
        $this->invoice('issued', ['issue_date' => '2020-01-01', 'due_date' => '2026-09-28', 'total' => '20.00']);  // due yesterday: overdue
        $this->invoice('issued', ['issue_date' => '2026-09-01', 'due_date' => '2026-09-29', 'total' => '30.00']);  // due today: not overdue
        $this->invoice('paid', ['due_date' => '2026-01-01', 'total' => '99.00']);

        foreach ([[], ['period' => 'last_year'], ['period' => 'custom', 'from' => '2001-01-01', 'to' => '2001-01-31']] as $query) {
            $position = $this->block($this->summary($query), 'data-report-position');

            $this->assertStringContainsString('Outstanding: RM 50.00 (2 invoices)', $position);
            $this->assertStringContainsString('Overdue: RM 20.00 (1 invoice)', $position);
        }

        $this->summary(['period' => 'last_year'])
            ->assertSee(route('reports.invoices', ['view' => 'outstanding', 'period' => 'last_year']));
    }

    // ---------------------------------------------------------------- periods

    public function test_invalid_custom_ranges_fall_back_to_this_year_with_a_warning(): void
    {
        foreach ([
            ['from' => '2026-09-20', 'to' => '2026-09-10'],
            ['from' => '2026-09-01', 'to' => '2026-09-30'],
            ['from' => '1999-12-31', 'to' => '2000-01-10'],
            ['from' => '2026/09/01', 'to' => '2026-09-10'],
            ['from' => '2026-02-30', 'to' => '2026-03-10'],
            ['from' => ['x'], 'to' => '2026-09-10'],
        ] as $range) {
            $this->summary(['period' => 'custom'] + $range)
                ->assertSee('Showing 1 Jan – 29 Sep 2026.')
                ->assertSee("That date range wasn't valid, so this year is shown.", false);
        }
    }

    public function test_this_year_follows_the_malaysian_timezone(): void
    {
        // 20:00 UTC on 31 Dec 2026 is already 1 Jan 2027 in Kuala Lumpur.
        $this->travelTo(now('UTC')->setDate(2026, 12, 31)->setTime(20, 0));
        $this->expense(['expense_date' => '2027-01-01', 'amount' => '5.00']);

        $response = $this->summary();

        $response->assertSee('Showing 1 Jan 2027.');
        $this->assertSame('RM 5.00', $this->card($response, 'expenses'));
    }

    public function test_empty_account_shows_zeroes(): void
    {
        $response = $this->summary();

        foreach (['received', 'invoiced', 'expenses', 'net-cash'] as $card) {
            $this->assertSame('RM 0.00', $this->card($response, $card));
        }
        $response->assertSee('No invoices or expenses in this period.');
        $this->assertSame('Total RM 0.00 (0) RM 0.00 (0) RM 0.00 (0) RM 0.00', $this->block($response, 'data-month="total"'));
    }
}
