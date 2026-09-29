<?php

namespace Tests\Feature\Reports;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportInvoicesTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private function invoices(array $query = [], ?User $as = null): TestResponse
    {
        return $this->report('reports.invoices', $query, $as);
    }

    /**
     * The invoice IDs listed on the page, in order.
     *
     * @return list<int>
     */
    private function listed(TestResponse $response): array
    {
        preg_match_all('/data-invoice-row="(\d+)"/', $response->getContent(), $m);

        return array_map('intval', $m[1]);
    }

    /**
     * A spread of invoices around the period (this year) and today (29 Sep 2026).
     *
     * @return array<string, Invoice>
     */
    private function spread(): array
    {
        return [
            'issuedCurrent' => $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => '2026-12-31', 'total' => '1.00']),
            'issuedOverdue' => $this->invoice('issued', ['issue_date' => '2026-02-01', 'due_date' => '2026-09-28', 'total' => '2.00']),
            'issuedDueToday' => $this->invoice('issued', ['issue_date' => '2026-04-01', 'due_date' => '2026-09-29', 'total' => '4.00']),
            'issuedLastYear' => $this->invoice('issued', ['issue_date' => '2025-06-01', 'due_date' => '2025-07-01', 'total' => '8.00']),
            'paidThisYear' => $this->invoice('paid', ['issue_date' => '2026-05-01', 'paid_at' => '2026-05-20', 'total' => '16.00']),
            'paidAcross' => $this->invoice('paid', ['issue_date' => '2025-12-01', 'paid_at' => '2026-01-10', 'total' => '32.00']),
            'draft' => $this->invoice('draft', ['issue_date' => '2026-03-01', 'total' => '64.00']),
            'cancelled' => $this->invoice('cancelled', ['issue_date' => '2026-03-01', 'total' => '128.00']),
        ];
    }

    public function test_issued_view_lists_issued_and_paid_by_issue_date_newest_first(): void
    {
        $i = $this->spread();

        $response = $this->invoices();

        $this->assertSame(
            [$i['paidThisYear']->id, $i['issuedDueToday']->id, $i['issuedCurrent']->id, $i['issuedOverdue']->id],
            $this->listed($response),
        );
        $this->assertSame('4 invoices · Total RM 23.00', $this->block($response, 'data-report-invoice-total'));
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function statusFilters(): array
    {
        return [
            'unpaid' => ['unpaid', ['issuedDueToday', 'issuedCurrent', 'issuedOverdue'], 'RM 7.00'],
            'overdue' => ['overdue', ['issuedOverdue'], 'RM 2.00'],
            'paid' => ['paid', ['paidThisYear'], 'RM 16.00'],
        ];
    }

    #[DataProvider('statusFilters')]
    public function test_issued_view_status_filters(string $status, array $expected, string $total): void
    {
        $i = $this->spread();

        $response = $this->invoices(['view' => 'invoiced', 'status' => $status]);

        $this->assertSame(array_map(fn ($key) => $i[$key]->id, $expected), $this->listed($response));
        $this->assertStringEndsWith($total, $this->block($response, 'data-report-invoice-total'));
    }

    public function test_paid_view_lists_paid_invoices_by_paid_date_newest_first(): void
    {
        $i = $this->spread();

        $response = $this->invoices(['view' => 'received']);

        $this->assertSame([$i['paidThisYear']->id, $i['paidAcross']->id], $this->listed($response));
        $this->assertSame('2 invoices · Total RM 48.00', $this->block($response, 'data-report-invoice-total'));
        $this->assertSame([$i['paidAcross']->id], $this->listed($this->invoices(['view' => 'received', 'period' => 'custom', 'from' => '2026-01-10', 'to' => '2026-01-10'])));
    }

    public function test_outstanding_view_lists_all_issued_invoices_oldest_due_first(): void
    {
        $i = $this->spread();

        $response = $this->invoices(['view' => 'outstanding', 'period' => 'last_month']);

        $this->assertSame(
            [$i['issuedLastYear']->id, $i['issuedOverdue']->id, $i['issuedDueToday']->id, $i['issuedCurrent']->id],
            $this->listed($response),
        );
        $this->assertSame('4 invoices · Total RM 15.00', $this->block($response, 'data-report-invoice-total'));
    }

    public function test_invalid_view_and_status_fall_back(): void
    {
        $i = $this->spread();
        $default = $this->listed($this->invoices());

        $this->assertSame($default, $this->listed($this->invoices(['view' => 'everything'])));
        $this->assertSame($default, $this->listed($this->invoices(['status' => 'deleted'])));
        $this->assertSame($default, $this->listed($this->invoices(['view' => ['x'], 'status' => ['y']])));

        // Status only applies to the issued view.
        $this->assertSame([$i['paidThisYear']->id, $i['paidAcross']->id], $this->listed($this->invoices(['view' => 'received', 'status' => 'unpaid'])));
    }

    public function test_ageing_buckets_have_exact_boundaries_and_add_up_to_outstanding(): void
    {
        $due = [
            'current' => ['2026-10-10' => '1.00', '2026-09-29' => '2.00'],        // future, and due today (0 days)
            'days_1_30' => ['2026-09-28' => '4.00', '2026-08-30' => '8.00'],     // 1 and 30 days
            'days_31_60' => ['2026-08-29' => '16.00', '2026-07-31' => '32.00'],  // 31 and 60 days
            'days_61_90' => ['2026-07-30' => '64.00', '2026-07-01' => '128.00'], // 61 and 90 days
            'days_over_90' => ['2026-06-30' => '256.00'],                         // 91 days
        ];
        foreach ($due as $dates) {
            foreach ($dates as $date => $total) {
                $this->invoice('issued', ['issue_date' => '2026-01-01', 'due_date' => $date, 'total' => $total]);
            }
        }
        $this->invoice('paid', ['due_date' => '2026-01-01', 'total' => '9999.00']);

        $response = $this->invoices(['view' => 'outstanding']);

        $this->assertSame('Not yet due RM 3.00 2 invoices', $this->block($response, 'data-ageing="current"'));
        $this->assertSame('1–30 days overdue RM 12.00 2 invoices', $this->block($response, 'data-ageing="days_1_30"'));
        $this->assertSame('31–60 days overdue RM 48.00 2 invoices', $this->block($response, 'data-ageing="days_31_60"'));
        $this->assertSame('61–90 days overdue RM 192.00 2 invoices', $this->block($response, 'data-ageing="days_61_90"'));
        $this->assertSame('Over 90 days overdue RM 256.00 1 invoice', $this->block($response, 'data-ageing="days_over_90"'));
        $this->assertSame('9 invoices · Total RM 511.00', $this->block($response, 'data-report-invoice-total'));
        $this->assertSame(3 + 12 + 48 + 192 + 256, 511);
    }

    public function test_pagination_is_25_per_page_with_totals_for_the_whole_set_and_filters_kept(): void
    {
        foreach (range(1, 26) as $n) {
            $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => sprintf('2026-10-%02d', $n), 'total' => '1.10']);
        }

        $first = $this->invoices(['view' => 'outstanding', 'period' => 'last_year']);
        $this->assertCount(25, $this->listed($first));
        $this->assertSame('26 invoices · Total RM 28.60', $this->block($first, 'data-report-invoice-total'));
        $first->assertSee('view=outstanding&period=last_year&page=2');

        $second = $this->invoices(['view' => 'outstanding', 'period' => 'last_year', 'page' => 2]);
        $this->assertCount(1, $this->listed($second));
        $this->assertSame('26 invoices · Total RM 28.60', $this->block($second, 'data-report-invoice-total'));
    }

    public function test_rows_show_dates_status_link_and_a_dash_for_unpaid(): void
    {
        $issued = $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => '2026-03-31', 'total' => '10.00', 'customer_name' => 'Late Co']);
        $paid = $this->invoice('paid', ['issue_date' => '2026-03-02', 'paid_at' => '2026-03-15', 'total' => '20.00', 'customer_name' => 'Paid Co']);

        $response = $this->invoices();

        $this->assertSame("{$issued->invoice_number} Late Co 01 Mar 2026 31 Mar 2026 — Overdue RM 10.00", $this->block($response, 'data-invoice-row="'.$issued->id.'"'));
        $this->assertSame("{$paid->invoice_number} Paid Co 02 Mar 2026 ".$paid->due_date->format('d M Y').' 15 Mar 2026 Paid RM 20.00', $this->block($response, 'data-invoice-row="'.$paid->id.'"'));
        $response->assertSee(route('invoices.show', $issued));
    }

    public function test_period_links_keep_the_view_and_status(): void
    {
        $response = $this->invoices(['view' => 'invoiced', 'status' => 'paid']);

        $response->assertSee(route('reports.invoices', ['period' => 'last_year', 'view' => 'invoiced', 'status' => 'paid']));
        $response->assertSee('name="view" value="invoiced"', false)->assertSee('name="status" value="paid"', false);
    }

    public function test_another_users_invoices_never_appear(): void
    {
        $other = User::factory()->create();
        $this->spread();
        $mine = [
            'invoiced' => $this->listed($this->invoices()),
            'received' => $this->listed($this->invoices(['view' => 'received'])),
            'outstanding' => $this->listed($this->invoices(['view' => 'outstanding'])),
        ];

        $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => '2026-01-01', 'total' => '777.00', 'customer_name' => 'Theirs Co'], $other);
        $this->invoice('paid', ['issue_date' => '2026-03-01', 'paid_at' => '2026-03-02', 'total' => '888.00', 'customer_name' => 'Theirs Co'], $other);

        foreach ($mine as $view => $ids) {
            $response = $this->invoices(['view' => $view]);
            $this->assertSame($ids, $this->listed($response));
            $response->assertDontSee('Theirs Co')->assertDontSee('777.00')->assertDontSee('888.00');
        }
    }
}
