<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Access, navigation and cross-report guarantees (isolation, forged parameters,
 * constant query counts, empty accounts) for all four report pages.
 */
class ReportsAccessTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function reportRoutes(): array
    {
        return [
            'summary' => ['reports.summary'],
            'customers' => ['reports.customers'],
            'invoices' => ['reports.invoices'],
            'expenses' => ['reports.expenses'],
        ];
    }

    #[DataProvider('reportRoutes')]
    public function test_guests_are_redirected_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[DataProvider('reportRoutes')]
    public function test_reports_render_with_navigation_print_and_footnote(string $route): void
    {
        $response = $this->report($route)
            ->assertSee('Showing 1 Jan – 29 Sep 2026.')
            ->assertSee('data-print', false)
            ->assertSee('Net cash (estimate)</strong>: Received minus expenses for this period. A simple estimate, not accounting profit.', false);

        $this->assertStringContainsString('Summary Customers Invoices Expenses', $this->block($response, 'data-report-tabs'));
    }

    public function test_navbar_link_is_active_on_every_report_page(): void
    {
        foreach (array_column(self::reportRoutes(), 0) as $route) {
            $html = $this->report($route)->getContent();
            $this->assertMatchesRegularExpression('/class="nav-link\s+active\s*"\s+href="'.preg_quote(route('reports.summary'), '/').'">Reports</', $html, $route);
        }
    }

    public function test_tabs_carry_the_period_and_the_invoice_tab_carries_view_and_status(): void
    {
        $period = ['period' => 'custom', 'from' => '2026-03-01', 'to' => '2026-04-15'];

        $summary = $this->report('reports.summary', $period);
        foreach (['reports.summary', 'reports.customers', 'reports.invoices', 'reports.expenses'] as $route) {
            $summary->assertSee(route($route, $period));
        }

        $this->report('reports.invoices', $period + ['view' => 'invoiced', 'status' => 'overdue'])
            ->assertSee(route('reports.invoices', $period + ['view' => 'invoiced', 'status' => 'overdue']))
            ->assertSee(route('reports.customers', $period));

        $this->report('reports.summary', ['period' => 'last_year'])
            ->assertSee(route('reports.expenses', ['period' => 'last_year']));
    }

    public function test_reports_offer_last_year_and_default_to_this_year(): void
    {
        $response = $this->report('reports.summary');

        $response->assertSee(route('reports.summary', ['period' => 'last_year']));
        $this->assertMatchesRegularExpression('/aria-current="true"\s*>This year</', $response->getContent());
        $this->report('reports.summary', ['period' => 'last_year'])->assertSee('Showing 1 Jan – 31 Dec 2025.');
    }

    public function test_dashboard_links_to_full_reports(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('See full reports')
            ->assertSee(route('reports.summary'));
    }

    #[DataProvider('reportRoutes')]
    public function test_empty_account_renders_without_errors(string $route): void
    {
        $this->report($route)->assertSee('data-report-empty', false);
    }

    /**
     * Rich data for another user in every state, category and period.
     */
    private function seedOtherUser(): User
    {
        $other = User::factory()->create(['name' => 'Other Owner']);
        $this->invoice('paid', ['issue_date' => '2026-03-01', 'paid_at' => '2026-03-05', 'total' => '1000.00', 'customer_name' => 'Other Paid Co'], $other);
        $this->invoice('issued', ['issue_date' => '2026-02-01', 'due_date' => '2026-02-15', 'total' => '2000.00', 'customer_name' => 'Other Overdue Co'], $other);
        $this->invoice('issued', ['issue_date' => '2026-09-01', 'due_date' => '2026-12-31', 'total' => '3000.00', 'customer_name' => 'Other Current Co'], $other);
        $this->invoice('draft', ['total' => '4000.00', 'customer_name' => 'Other Draft Co'], $other);
        $this->invoice('cancelled', ['total' => '5000.00', 'customer_name' => 'Other Cancelled Co'], $other);
        foreach (['rent', 'software', 'meals', 'other'] as $i => $category) {
            $this->expense(['expense_date' => '2026-0'.($i + 3).'-10', 'category' => $category, 'amount' => '600.00', 'description' => "Other {$category}"], $other);
        }

        return $other;
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function reportPages(): array
    {
        return [
            'summary' => ['reports.summary', []],
            'customers' => ['reports.customers', []],
            'invoices issued' => ['reports.invoices', []],
            'invoices paid' => ['reports.invoices', ['view' => 'received']],
            'invoices outstanding' => ['reports.invoices', ['view' => 'outstanding']],
            'expenses' => ['reports.expenses', []],
        ];
    }

    #[DataProvider('reportPages')]
    public function test_another_users_data_and_forged_parameters_never_change_a_report(string $route, array $query): void
    {
        $this->invoice('paid', ['issue_date' => '2026-03-02', 'paid_at' => '2026-03-03', 'total' => '10.00', 'customer_name' => 'Mine Paid']);
        $this->invoice('issued', ['issue_date' => '2026-02-02', 'due_date' => '2026-02-20', 'total' => '20.00', 'customer_name' => 'Mine Overdue']);
        $this->expense(['expense_date' => '2026-03-04', 'category' => 'office', 'amount' => '3.00', 'description' => 'My pens']);

        $render = fn (array $extra = []) => preg_replace('/name="csrf-token" content="[^"]*"|name="_token" value="[^"]*"/', '', $this->report($route, $query + $extra)->getContent());

        $before = $render();
        $other = $this->seedOtherUser();
        $foreignInvoice = $other->invoices()->first();

        $this->assertSame($before, $render(), 'Another user\'s data changed the report');
        foreach ([['user_id' => $other->id], ['customer_id' => $foreignInvoice->customer_id], ['invoice_id' => $foreignInvoice->id]] as $forged) {
            $html = $render($forged);
            $this->assertStringNotContainsString('Other ', strip_tags($html));
            $this->assertStringNotContainsString('1,000.00', $html);
            $this->assertStringNotContainsString('3,000.00', $html);
        }
    }

    #[DataProvider('reportPages')]
    public function test_query_count_does_not_grow_with_the_amount_of_data(string $route, array $query): void
    {
        $count = function () use ($route, $query): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report($route, $query);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // At least one row of every kind, so an empty paginator never skips its page query.
        $this->invoice('issued', ['issue_date' => '2026-03-01', 'due_date' => '2026-04-01']);
        $this->invoice('paid', ['issue_date' => '2026-03-01', 'paid_at' => '2026-03-10']);
        $this->expense(['expense_date' => '2026-03-02']);
        $small = $count();

        foreach (range(1, 30) as $i) {
            $this->invoice($i % 2 ? 'issued' : 'paid', ['issue_date' => '2026-03-01', 'due_date' => '2026-04-01', 'paid_at' => '2026-03-10']);
            $this->expense(['expense_date' => '2026-03-02']);
        }
        $large = $count();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(10, $large);
    }
}
