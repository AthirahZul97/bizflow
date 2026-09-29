<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportCustomersTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private function customers(array $query = [], ?User $as = null): TestResponse
    {
        return $this->report('reports.customers', $query, $as);
    }

    public function test_rows_group_by_customer_with_period_and_today_figures(): void
    {
        $customer = $this->customer();
        $this->invoice('paid', ['customer_id' => $customer->id, 'customer_name' => 'Aina Trading', 'issue_date' => '2026-02-01', 'paid_at' => '2026-02-10', 'total' => '0.10']);
        $this->invoice('paid', ['customer_id' => $customer->id, 'customer_name' => 'Aina Trading', 'issue_date' => '2025-12-01', 'paid_at' => '2026-01-05', 'total' => '0.20']);
        $this->invoice('issued', ['customer_id' => $customer->id, 'customer_name' => 'Aina Trading', 'issue_date' => '2026-03-01', 'due_date' => '2026-09-28', 'total' => '5.00']);
        $this->invoice('issued', ['customer_id' => $customer->id, 'customer_name' => 'Aina Trading', 'issue_date' => '2026-04-01', 'due_date' => '2026-09-29', 'total' => '7.00']);

        $response = $this->customers();

        // Issued in 2026: 0.10 + 5.00 + 7.00; received in 2026: 0.10 + 0.20; outstanding 12.00; overdue 5.00 (due today is not overdue).
        $this->assertSame('Aina Trading 3 RM 12.10 RM 0.30 RM 12.00 RM 5.00', $this->block($response, 'data-customer-row="'.$customer->id.'"'));
        $this->assertSame(1, substr_count($response->getContent(), 'data-customer-row="'.$customer->id.'"'));
        $response->assertSee(route('customers.show', $customer->id));
    }

    public function test_name_comes_from_the_newest_invoice_snapshot_not_the_customer_record(): void
    {
        $customer = $this->customer(['name' => 'Live Record Name']);
        $this->invoice('issued', ['customer_id' => $customer->id, 'customer_name' => 'Old Name Sdn Bhd', 'customer_company_name' => 'Old Co', 'issue_date' => '2026-02-01', 'due_date' => '2026-12-31']);
        $this->invoice('issued', ['customer_id' => $customer->id, 'customer_name' => 'New Name Sdn Bhd', 'customer_company_name' => 'New Co', 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31']);

        $row = $this->block($this->customers(), 'data-customer-row="'.$customer->id.'"');
        $this->assertStringStartsWith('New Name Sdn Bhd New Co 2', $row);

        $customer->update(['name' => 'Renamed Again', 'company_name' => 'Renamed Co']);
        $response = $this->customers();

        $this->assertSame($row, $this->block($response, 'data-customer-row="'.$customer->id.'"'));
        $response->assertDontSee('Renamed Again')->assertDontSee('Live Record Name')->assertDontSee('Old Name Sdn Bhd');
    }

    public function test_old_outstanding_invoice_brings_in_a_customer_with_nothing_invoiced_this_period(): void
    {
        $customer = $this->customer();
        $this->invoice('issued', ['customer_id' => $customer->id, 'customer_name' => 'Long Overdue Co', 'issue_date' => '2024-01-01', 'due_date' => '2024-02-01', 'total' => '800.00']);

        $this->assertSame('Long Overdue Co 0 RM 0.00 RM 0.00 RM 800.00 RM 800.00', $this->block($this->customers(), 'data-customer-row="'.$customer->id.'"'));
    }

    public function test_draft_only_cancelled_only_and_out_of_period_paid_customers_are_excluded(): void
    {
        $this->invoice('draft', ['customer_name' => 'Draft Only Co', 'issue_date' => '2026-03-01', 'total' => '100.00']);
        $this->invoice('cancelled', ['customer_name' => 'Cancelled Only Co', 'issue_date' => '2026-03-01', 'total' => '200.00']);
        $this->invoice('paid', ['customer_name' => 'Paid Last Year Co', 'issue_date' => '2025-03-01', 'paid_at' => '2025-03-05', 'total' => '300.00']);

        $response = $this->customers();

        $response->assertDontSee('Draft Only Co')->assertDontSee('Cancelled Only Co')->assertDontSee('Paid Last Year Co');
        $response->assertSee('No invoiced, received or outstanding amounts for this period.');
        $this->customers(['period' => 'last_year'])->assertSee('Paid Last Year Co');
    }

    public function test_rows_sort_by_invoiced_then_received_then_customer(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $c = $this->customer();
        $d = $this->customer();
        $this->invoice('paid', ['customer_id' => $a->id, 'customer_name' => 'Row A', 'issue_date' => '2025-06-01', 'paid_at' => '2026-02-01', 'total' => '50.00']);
        $this->invoice('issued', ['customer_id' => $b->id, 'customer_name' => 'Row B', 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31', 'total' => '900.00']);
        $this->invoice('paid', ['customer_id' => $c->id, 'customer_name' => 'Row C', 'issue_date' => '2025-06-01', 'paid_at' => '2026-02-01', 'total' => '70.00']);
        $this->invoice('paid', ['customer_id' => $d->id, 'customer_name' => 'Row D', 'issue_date' => '2025-06-01', 'paid_at' => '2026-02-01', 'total' => '50.00']);

        // B has the most invoiced; C, A and D invoiced nothing this year, so received decides; A before D by id.
        $this->customers()->assertSeeInOrder(['Row B', 'Row C', 'Row A', 'Row D']);
    }

    public function test_pagination_is_25_per_page_with_totals_for_all_customers(): void
    {
        foreach (range(1, 26) as $i) {
            $customer = $this->customer();
            $this->invoice('paid', ['customer_id' => $customer->id, 'customer_name' => sprintf('Pager %02d', $i), 'issue_date' => '2025-06-01', 'paid_at' => '2026-02-01', 'total' => sprintf('%d.10', 100 - $i)]);
        }

        $first = $this->customers(['period' => 'this_year']);
        $this->assertSame(25, substr_count($first->getContent(), 'data-customer-row="') - 1); // minus the totals row
        $first->assertSee('Pager 01')->assertSee('Pager 25')->assertDontSee('Pager 26');
        $first->assertSee('period=this_year&page=2');

        // Received 99.10 + 98.10 + ... + 74.10 = 26 × 86.60 = 2,251.60
        $this->assertSame('Total (all 26 customers) 0 RM 0.00 RM 2,251.60 RM 0.00 RM 0.00', $this->block($first, 'data-customer-row="total"'));

        $second = $this->customers(['period' => 'this_year', 'page' => 2]);
        $this->assertSame(1, substr_count($second->getContent(), 'data-customer-row="') - 1);
        $second->assertSee('Pager 26')->assertDontSee('Pager 25');
        $this->assertSame($this->block($first, 'data-customer-row="total"'), $this->block($second, 'data-customer-row="total"'));
    }

    public function test_totals_are_exact(): void
    {
        foreach (['0.10', '0.20', '0.30'] as $amount) {
            $this->invoice('paid', ['issue_date' => '2026-02-01', 'paid_at' => '2026-02-02', 'total' => $amount]);
        }

        $this->assertStringContainsString('RM 0.60 RM 0.60', $this->block($this->customers(), 'data-customer-row="total"'));
    }

    public function test_another_users_customers_never_merge_or_appear(): void
    {
        $other = User::factory()->create();
        $this->invoice('issued', ['customer_name' => 'Same Name Co', 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31', 'total' => '10.00']);
        $this->invoice('issued', ['customer_name' => 'Same Name Co', 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31', 'total' => '999.00'], $other);
        $this->invoice('paid', ['customer_name' => 'Their Customer', 'paid_at' => '2026-03-02', 'total' => '555.00'], $other);

        $response = $this->customers();

        $response->assertDontSee('Their Customer')->assertDontSee('999.00')->assertDontSee('555.00');
        $this->assertStringStartsWith('Total (all 1 customer) 1 RM 10.00', $this->block($response, 'data-customer-row="total"'));
    }

    public function test_names_are_escaped(): void
    {
        $this->invoice('issued', ['customer_name' => '<b>Bold Co</b>', 'customer_company_name' => '<i>Italic</i>', 'issue_date' => '2026-03-01', 'due_date' => '2026-12-31']);

        $this->customers()
            ->assertDontSee('<b>Bold Co</b>', false)
            ->assertDontSee('<i>Italic</i>', false)
            ->assertSee('&lt;b&gt;Bold Co&lt;/b&gt;', false);
    }
}
