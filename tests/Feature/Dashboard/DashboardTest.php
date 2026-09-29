<?php

namespace Tests\Feature\Dashboard;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Today is Tuesday 29 Sep 2026 in Asia/Kuala_Lumpur.
        $this->travelTo('2026-09-29 10:00:00');
        $this->user = User::factory()->create(['name' => 'Aisha Rahman']);
    }

    /**
     * Create an invoice for $owner in the given state ("draft", "issued", "paid", "cancelled").
     */
    private function invoice(string $state, array $attributes = [], ?User $owner = null): Invoice
    {
        $factory = Invoice::factory();
        if ($state !== 'draft') {
            $factory = $factory->{$state}();
        }

        return $factory->create(['user_id' => ($owner ?? $this->user)->id] + $attributes);
    }

    private function expense(array $attributes = [], ?User $owner = null): Expense
    {
        return Expense::factory()->for($owner ?? $this->user)->create($attributes);
    }

    private function dashboard(array $query = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->get(route('dashboard', $query))->assertOk();
    }

    /**
     * The main figure on a KPI card, e.g. "RM 1,500.00".
     */
    private function kpi(TestResponse $response, string $card): string
    {
        preg_match('/data-kpi="'.$card.'".*?fs-4[^>]*>(.*?)<\/div>/s', $response->getContent(), $m);

        return trim(html_entity_decode($m[1] ?? 'missing'));
    }

    /**
     * The text of a block, tags stripped and whitespace collapsed.
     */
    private function block(TestResponse $response, string $attribute): string
    {
        $html = $response->getContent();
        $start = strpos($html, $attribute);
        if ($start === false) {
            return '';
        }
        $tagStart = strrpos(substr($html, 0, $start), '<');
        preg_match('/<(\w+)/', substr($html, $tagStart), $tag);
        $depth = 0;
        $offset = $tagStart;
        while (preg_match('/<(\/?)'.$tag[1].'\b[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $depth += $m[1][0] === '/' ? -1 : 1;
            $offset = $m[0][1] + strlen($m[0][0]);
            if ($depth === 0) {
                break;
            }
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(substr($html, $tagStart, $offset - $tagStart)))));
    }

    // ---------------------------------------------------------------- access

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_renders_with_the_welcome_line_and_this_month(): void
    {
        $this->dashboard()
            ->assertSee('Welcome, <strong>Aisha Rahman</strong>', false)
            ->assertSee('Showing 1–29 Sep 2026.')
            ->assertSee('Net cash (estimate)')
            ->assertSee('Received minus expenses for this period. A simple estimate, not accounting profit.')
            ->assertDontSee('profit</', false);
    }

    // ---------------------------------------------------------------- periods

    public function test_period_options_change_the_range(): void
    {
        $lastMonth = $this->dashboard(['period' => 'last_month'])->assertSee('Showing 1–31 Aug 2026.');
        $this->assertMatchesRegularExpression('/aria-current="true"\s*>Last month</', $lastMonth->getContent());
        $this->dashboard(['period' => 'this_year'])->assertSee('Showing 1 Jan – 29 Sep 2026.');
        $this->dashboard(['period' => 'custom', 'from' => '2026-07-01', 'to' => '2026-07-15'])
            ->assertSee('Showing 1–15 Jul 2026.')
            ->assertSee('value="2026-07-01"', false)
            ->assertDontSee("wasn't valid", false);
    }

    public function test_invalid_custom_ranges_fall_back_to_this_month_with_a_warning(): void
    {
        foreach ([
            ['from' => '2026-09-20', 'to' => '2026-09-10'],   // from after to
            ['from' => '2026-09-01', 'to' => '2026-09-30'],   // to in the future
            ['from' => '2026/09/01', 'to' => '2026-09-10'],   // malformed
            ['from' => '2026-02-30', 'to' => '2026-03-10'],   // impossible
            ['from' => ['x'], 'to' => '2026-09-10'],          // array
        ] as $range) {
            $this->dashboard(['period' => 'custom'] + $range)
                ->assertSee('Showing 1–29 Sep 2026.')
                ->assertSee("That date range wasn't valid, so this month is shown.", false);
        }
    }

    // ---------------------------------------------------------------- money in

    public function test_received_uses_paid_at_and_only_paid_invoices(): void
    {
        // Issued in August, paid in September: counts as received in September.
        $this->invoice('paid', ['issue_date' => '2026-08-10', 'paid_at' => '2026-09-05', 'total' => '100.00']);
        $this->invoice('paid', ['issue_date' => '2026-08-01', 'paid_at' => '2026-08-31', 'total' => '7.00']);
        $this->invoice('issued', ['issue_date' => '2026-09-10', 'total' => '1000.00']);
        $this->invoice('draft', ['issue_date' => '2026-09-10', 'total' => '2000.00']);
        $this->invoice('cancelled', ['issue_date' => '2026-09-10', 'total' => '3000.00']);

        $thisMonth = $this->dashboard();
        $this->assertSame('RM 100.00', $this->kpi($thisMonth, 'received'));
        $thisMonth->assertSee('1 invoice marked paid');

        $this->assertSame('RM 7.00', $this->kpi($this->dashboard(['period' => 'last_month']), 'received'));
    }

    public function test_invoiced_uses_issue_date_and_excludes_drafts_and_cancelled(): void
    {
        $this->invoice('issued', ['issue_date' => '2026-09-01', 'total' => '200.00']);
        $this->invoice('paid', ['issue_date' => '2026-09-29', 'paid_at' => '2026-09-29', 'total' => '50.00']);
        $this->invoice('issued', ['issue_date' => '2026-08-31', 'total' => '4000.00']);
        $this->invoice('draft', ['issue_date' => '2026-09-15', 'total' => '5000.00']);
        $this->invoice('cancelled', ['issue_date' => '2026-09-15', 'total' => '6000.00']);

        $response = $this->dashboard();
        $this->assertSame('RM 250.00', $this->kpi($response, 'invoiced'));
        $response->assertSee('2 invoices issued');

        $this->assertSame('RM 4,000.00', $this->kpi($this->dashboard(['period' => 'last_month']), 'invoiced'));
    }

    public function test_outstanding_is_all_issued_invoices_regardless_of_period(): void
    {
        $this->invoice('issued', ['issue_date' => '2025-01-10', 'due_date' => '2026-12-31', 'total' => '300.00']);
        $this->invoice('issued', ['issue_date' => '2026-09-10', 'due_date' => '2026-12-31', 'total' => '200.00']);
        $this->invoice('paid', ['total' => '999.00']);
        $this->invoice('draft', ['total' => '888.00']);
        $this->invoice('cancelled', ['total' => '777.00']);

        foreach ([[], ['period' => 'last_month'], ['period' => 'custom', 'from' => '2020-01-01', 'to' => '2020-01-31']] as $query) {
            $this->assertSame('RM 500.00', $this->kpi($this->dashboard($query), 'outstanding'));
        }
    }

    public function test_overdue_boundary_due_today_is_not_overdue(): void
    {
        $this->invoice('issued', ['due_date' => '2026-09-28', 'total' => '20.00']);   // yesterday: overdue
        $this->invoice('issued', ['due_date' => '2026-09-29', 'total' => '30.00']);   // today: not overdue
        $this->invoice('paid', ['due_date' => '2026-01-01', 'total' => '40.00']);     // paid: never overdue

        $response = $this->dashboard();

        $this->assertSame('RM 50.00', $this->kpi($response, 'outstanding'));
        $response->assertSee('RM 20.00 overdue (1)');
        $this->assertSame('Overdue 1 RM 20.00', $this->block($response, 'data-status-row="overdue"'));
        $this->assertSame('Issued, not yet due 1 RM 30.00', $this->block($response, 'data-status-row="issued"'));
    }

    public function test_due_soon_covers_today_through_six_days_ahead(): void
    {
        $this->invoice('issued', ['due_date' => '2026-09-28', 'total' => '1.00']);   // overdue, not "soon"
        $this->invoice('issued', ['due_date' => '2026-09-29', 'total' => '10.00']);  // today
        $this->invoice('issued', ['due_date' => '2026-10-05', 'total' => '20.00']);  // today + 6
        $this->invoice('issued', ['due_date' => '2026-10-06', 'total' => '40.00']);  // today + 7: not soon
        $this->invoice('paid', ['due_date' => '2026-09-30', 'total' => '80.00']);    // paid: not soon

        $this->assertSame(
            '2 invoices due in the next 7 days · RM 30.00',
            $this->block($this->dashboard(), 'data-attention-due-soon'),
        );
    }

    // ---------------------------------------------------------------- money out & net

    public function test_expenses_include_both_ends_of_the_period(): void
    {
        $this->expense(['expense_date' => '2026-08-31', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-09-01', 'amount' => '10.00']);
        $this->expense(['expense_date' => '2026-09-29', 'amount' => '100.00']);

        $this->assertSame('RM 110.00', $this->kpi($this->dashboard(), 'expenses'));
        $this->assertSame('RM 1.00', $this->kpi($this->dashboard(['period' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']), 'expenses'));
        $this->assertSame('RM 11.00', $this->kpi($this->dashboard(['period' => 'custom', 'from' => '2026-08-31', 'to' => '2026-09-01']), 'expenses'));
    }

    public function test_expenses_card_links_to_the_filtered_expense_list(): void
    {
        $this->expense(['expense_date' => '2026-09-10']);

        $this->dashboard()->assertSee(route('expenses.index', ['from' => '2026-09-01', 'to' => '2026-09-29']));
    }

    public function test_net_cash_is_the_exact_difference(): void
    {
        $this->invoice('paid', ['paid_at' => '2026-09-02', 'total' => '0.10']);
        $this->invoice('paid', ['paid_at' => '2026-09-03', 'total' => '0.20']);
        $this->expense(['expense_date' => '2026-09-04', 'amount' => '0.05']);

        $response = $this->dashboard();
        $this->assertSame('RM 0.30', $this->kpi($response, 'received'));
        $this->assertSame('RM 0.25', $this->kpi($response, 'net-cash'));
    }

    public function test_negative_net_cash_shows_a_real_minus_sign_in_red(): void
    {
        $this->expense(['expense_date' => '2026-09-04', 'amount' => '0.50']);

        $response = $this->dashboard();
        $this->assertSame('-RM 0.50', $this->kpi($response, 'net-cash'));
        $this->assertMatchesRegularExpression('/data-kpi="net-cash".*?class="fs-4 fw-semibold text-danger"/s', $response->getContent());

        $this->expense(['expense_date' => '2026-09-05', 'amount' => '1234.00']);
        $this->assertSame('-RM 1,234.50', $this->kpi($this->dashboard(), 'net-cash'));
    }

    // ---------------------------------------------------------------- summaries

    public function test_invoice_status_summary_counts_and_totals_all_time(): void
    {
        $this->invoice('draft', ['total' => '10.00']);
        $this->invoice('issued', ['issue_date' => '2024-01-01', 'due_date' => '2024-02-01', 'total' => '20.00']);
        $this->invoice('issued', ['due_date' => '2026-12-31', 'total' => '30.00']);
        $this->invoice('paid', ['paid_at' => '2025-05-05', 'total' => '40.00']);
        $this->invoice('cancelled', ['total' => '50.00']);

        $response = $this->dashboard();

        $this->assertSame('Draft 1 RM 10.00', $this->block($response, 'data-status-row="draft"'));
        $this->assertSame('Issued, not yet due 1 RM 30.00', $this->block($response, 'data-status-row="issued"'));
        $this->assertSame('Overdue 1 RM 20.00', $this->block($response, 'data-status-row="overdue"'));
        $this->assertSame('Paid 1 RM 40.00', $this->block($response, 'data-status-row="paid"'));
        $this->assertSame('Cancelled 1 RM 50.00', $this->block($response, 'data-status-row="cancelled"'));
        foreach (['draft', 'issued', 'overdue', 'paid', 'cancelled'] as $status) {
            $response->assertSee(route('invoices.index', ['status' => $status]), false);
        }
    }

    public function test_expense_categories_for_the_period_largest_first_with_percentages(): void
    {
        $this->expense(['expense_date' => '2026-09-02', 'category' => 'software', 'amount' => '250.00']);
        $this->expense(['expense_date' => '2026-09-03', 'category' => 'rent', 'amount' => '500.00']);
        $this->expense(['expense_date' => '2026-09-04', 'category' => 'rent', 'amount' => '250.00']);
        $this->expense(['expense_date' => '2026-08-15', 'category' => 'meals', 'amount' => '9999.00']); // last month

        $response = $this->dashboard();

        $response->assertSeeInOrder(['data-category-row="rent"', 'data-category-row="software"'], false);
        $this->assertSame('Rent RM 750.00 · 75%', $this->block($response, 'data-category-row="rent"'));
        $this->assertSame('Software & subscriptions RM 250.00 · 25%', $this->block($response, 'data-category-row="software"'));
        $response->assertDontSee('data-category-row="meals"', false);
        $response->assertSee('style="width: 75%"', false);
        $response->assertSee(route('expenses.index', ['category' => 'rent', 'from' => '2026-09-01', 'to' => '2026-09-29']));
    }

    public function test_category_percentages_round_half_up(): void
    {
        foreach (['rent', 'office', 'meals'] as $i => $category) {
            $this->expense(['expense_date' => '2026-09-0'.($i + 1), 'category' => $category, 'amount' => '1.00']);
        }
        $this->expense(['expense_date' => '2026-09-05', 'category' => 'travel', 'amount' => '0.50']);

        $response = $this->dashboard(); // total 3.50: 1.00 = 28.57% -> 29%, 0.50 = 14.29% -> 14%

        $this->assertStringEndsWith('· 29%', $this->block($response, 'data-category-row="rent"'));
        $this->assertStringEndsWith('· 14%', $this->block($response, 'data-category-row="travel"'));
    }

    public function test_trend_shows_six_months_newest_first_with_empty_months_as_zero(): void
    {
        $this->invoice('paid', ['paid_at' => '2026-08-20', 'total' => '100.00']);
        $this->expense(['expense_date' => '2026-08-21', 'amount' => '40.00']);
        $this->expense(['expense_date' => '2026-06-01', 'amount' => '75.00']);
        $this->invoice('paid', ['paid_at' => '2026-03-31', 'total' => '5000.00']); // 7th month back: excluded
        $this->invoice('paid', ['paid_at' => '2026-09-29', 'total' => '12.00']);

        $response = $this->dashboard();

        $response->assertSeeInOrder(array_map(fn ($m) => 'data-trend-month="'.$m.'"', ['2026-09', '2026-08', '2026-07', '2026-06', '2026-05', '2026-04']), false);
        $response->assertDontSee('data-trend-month="2026-03"', false);
        $this->assertSame('Sep 2026 (to date) RM 12.00 RM 0.00 RM 12.00', $this->block($response, 'data-trend-month="2026-09"'));
        $this->assertSame('Aug 2026 RM 100.00 RM 40.00 RM 60.00', $this->block($response, 'data-trend-month="2026-08"'));
        $this->assertSame('Jul 2026 RM 0.00 RM 0.00 RM 0.00', $this->block($response, 'data-trend-month="2026-07"'));
        $this->assertSame('Jun 2026 RM 0.00 RM 75.00 -RM 75.00', $this->block($response, 'data-trend-month="2026-06"'));
        $this->assertSame($this->kpi($response, 'received'), 'RM 12.00');
    }

    public function test_trend_crosses_a_year_boundary(): void
    {
        $this->travelTo('2027-02-10 10:00:00');
        $this->invoice('paid', ['paid_at' => '2026-12-15', 'total' => '300.00']);
        $this->expense(['expense_date' => '2026-09-01', 'amount' => '5.00']);

        $response = $this->dashboard();

        $response->assertSeeInOrder(array_map(fn ($m) => 'data-trend-month="'.$m.'"', ['2027-02', '2027-01', '2026-12', '2026-11', '2026-10', '2026-09']), false);
        $this->assertSame('Dec 2026 RM 300.00 RM 0.00 RM 300.00', $this->block($response, 'data-trend-month="2026-12"'));
        $this->assertSame('Sep 2026 RM 0.00 RM 5.00 -RM 5.00', $this->block($response, 'data-trend-month="2026-09"'));
    }

    // ---------------------------------------------------------------- lists & alerts

    public function test_recent_activity_shows_five_newest_of_each(): void
    {
        $customer = Customer::factory()->for($this->user)->create();
        foreach (range(1, 6) as $i) {
            $this->invoice('draft', ['customer_id' => $customer->id, 'customer_name' => "Recent Customer {$i}"]);
            $this->expense(['expense_date' => sprintf('2026-09-%02d', $i), 'description' => "Recent Expense {$i}"]);
        }

        $response = $this->dashboard();

        $this->assertSame(5, substr_count($response->getContent(), 'data-recent-invoice>'));
        $this->assertSame(5, substr_count($response->getContent(), 'data-recent-expense>'));
        $response->assertSeeInOrder(['Recent Customer 6', 'Recent Customer 5', 'Recent Customer 2']);
        $response->assertDontSee('Recent Customer 1<', false);
        $response->assertSeeInOrder(['Recent Expense 6', 'Recent Expense 5', 'Recent Expense 2']);
        $response->assertDontSee('Recent Expense 1<', false);
    }

    public function test_overdue_list_shows_five_oldest_with_days_overdue(): void
    {
        foreach (range(1, 6) as $i) {
            $this->invoice('issued', ['due_date' => sprintf('2026-09-%02d', $i), 'customer_name' => "Late Customer {$i}", 'total' => '10.00']);
        }

        $attention = $this->block($this->dashboard(), 'data-attention-overdue');

        $this->assertStringStartsWith('6 overdue invoices · RM 60.00', $attention);
        $this->assertStringContainsString('Late Customer 1 28 days overdue', $attention);
        $this->assertStringContainsString('Late Customer 5 24 days overdue', $attention);
        $this->assertStringNotContainsString('Late Customer 6', $attention);
        $this->assertStringEndsWith('View all overdue (6)', $attention);
        $this->assertLessThan(strpos($attention, 'Late Customer 2'), strpos($attention, 'Late Customer 1'));
    }

    public function test_attention_card_only_appears_when_needed(): void
    {
        $this->invoice('issued', ['due_date' => '2026-12-31']);
        $this->invoice('paid');
        $this->dashboard()->assertDontSee('data-dashboard-attention', false);

        $this->invoice('draft');
        $response = $this->dashboard();
        $response->assertSee('data-dashboard-attention', false);
        $this->assertSame('1 draft invoice not yet issued Drafts are not counted in any total.', $this->block($response, 'data-attention-drafts'));
    }

    public function test_user_supplied_text_is_escaped(): void
    {
        $this->invoice('issued', ['due_date' => '2026-09-01', 'customer_name' => '<b>Bold Customer</b>']);
        $this->expense(['expense_date' => '2026-09-02', 'description' => '<i>Italic Expense</i>']);

        $response = $this->dashboard();

        $response->assertDontSee('<b>Bold Customer</b>', false)
            ->assertDontSee('<i>Italic Expense</i>', false)
            ->assertSee('&lt;b&gt;Bold Customer&lt;/b&gt;', false)
            ->assertSee('&lt;i&gt;Italic Expense&lt;/i&gt;', false);
    }

    // ---------------------------------------------------------------- empty & isolation

    public function test_empty_account_shows_get_started_and_zeroes(): void
    {
        $response = $this->dashboard();

        $response->assertSee('data-dashboard-get-started', false)
            ->assertSee('No invoices yet.')
            ->assertSee('No expenses yet.')
            ->assertSee('No expenses recorded in this period.')
            ->assertDontSee('data-dashboard-attention', false);
        foreach (['received', 'expenses', 'net-cash', 'invoiced', 'outstanding'] as $card) {
            $this->assertSame('RM 0.00', $this->kpi($response, $card));
        }
    }

    public function test_get_started_disappears_once_the_user_has_a_customer(): void
    {
        Customer::factory()->for($this->user)->create();

        $this->dashboard()->assertDontSee('data-dashboard-get-started', false);
    }

    public function test_another_users_data_never_appears(): void
    {
        $other = User::factory()->create(['name' => 'Other Owner']);
        $this->invoice('paid', ['paid_at' => '2026-09-10', 'issue_date' => '2026-09-01', 'total' => '1000.00', 'customer_name' => 'Other Paid Co'], $other);
        $this->invoice('issued', ['issue_date' => '2026-09-01', 'due_date' => '2026-09-01', 'total' => '2000.00', 'customer_name' => 'Other Overdue Co'], $other);
        $this->invoice('issued', ['due_date' => '2026-10-01', 'total' => '3000.00', 'customer_name' => 'Other Soon Co'], $other);
        $this->invoice('draft', ['customer_name' => 'Other Draft Co'], $other);
        $this->invoice('cancelled', ['customer_name' => 'Other Cancelled Co'], $other);
        foreach (['rent', 'software', 'meals'] as $category) {
            $this->expense(['expense_date' => '2026-09-10', 'category' => $category, 'amount' => '500.00', 'description' => "Other {$category}"], $other);
        }
        $this->expense(['expense_date' => '2026-07-10', 'amount' => '700.00', 'description' => 'Other old'], $other);

        // Our user has one small figure of each kind.
        $this->invoice('paid', ['paid_at' => '2026-09-05', 'issue_date' => '2026-09-05', 'total' => '10.00', 'customer_name' => 'Mine']);
        $this->expense(['expense_date' => '2026-09-06', 'category' => 'office', 'amount' => '3.00', 'description' => 'My pens']);

        foreach ([[], ['user_id' => $other->id], ['period' => 'this_year', 'user_id' => $other->id]] as $query) {
            $response = $this->dashboard($query);

            $this->assertSame('RM 10.00', $this->kpi($response, 'received'));
            $this->assertSame('RM 3.00', $this->kpi($response, 'expenses'));
            $this->assertSame('RM 7.00', $this->kpi($response, 'net-cash'));
            $this->assertSame('RM 10.00', $this->kpi($response, 'invoiced'));
            $this->assertSame('RM 0.00', $this->kpi($response, 'outstanding'));
            $response->assertDontSee('data-dashboard-attention', false)
                ->assertDontSee('Other ')
                ->assertDontSee('data-category-row="rent"', false)
                ->assertSee('Welcome, <strong>Aisha Rahman</strong>', false);
            $this->assertSame('Draft 0 RM 0.00', $this->block($response, 'data-status-row="draft"'));
            $this->assertSame('Paid 1 RM 10.00', $this->block($response, 'data-status-row="paid"'));
        }
    }

    public function test_query_count_does_not_grow_with_the_amount_of_data(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $this->invoice('issued', ['due_date' => '2026-09-01']);
        $this->expense(['expense_date' => '2026-09-02']);
        $small = $count();

        foreach (range(1, 20) as $i) {
            $this->invoice($i % 2 ? 'issued' : 'paid', ['due_date' => '2026-09-01', 'paid_at' => '2026-09-10']);
            $this->expense(['expense_date' => '2026-09-02']);
        }
        $large = $count();

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(13, $large);
    }
}
