<?php

namespace Tests\Feature\Reports;

use App\Enums\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReportExpensesTest extends TestCase
{
    use BuildsReportData;
    use RefreshDatabase;

    private function expenses(array $query = [], ?User $as = null): TestResponse
    {
        return $this->report('reports.expenses', $query, $as);
    }

    public function test_all_thirteen_categories_in_enum_order_with_zero_rows_muted(): void
    {
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '100.00']);

        $response = $this->expenses();

        $response->assertSeeInOrder(array_map(fn ($c) => 'data-category-row="'.$c->value.'"', ExpenseCategory::cases()), false);
        foreach (ExpenseCategory::cases() as $category) {
            $response->assertSee($category->label());
        }
        $this->assertSame('Office supplies 0 RM 0.00 —', $this->block($response, 'data-category-row="office"'));
        $this->assertMatchesRegularExpression('/class="text-body-secondary" data-category-row="office"/', $response->getContent());
        $this->assertSame('Rent 1 RM 100.00 100%', $this->block($response, 'data-category-row="rent"'));
    }

    public function test_counts_sums_and_half_up_shares_are_exact(): void
    {
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '0.10']);
        $this->expense(['expense_date' => '2026-03-02', 'category' => 'rent', 'amount' => '0.90']);
        $this->expense(['expense_date' => '2026-03-03', 'category' => 'office', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-03-04', 'category' => 'meals', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-03-05', 'category' => 'travel', 'amount' => '0.50']);

        $response = $this->expenses(); // total 3.50: 1.00 → 28.57% → 29%; 0.50 → 14.29% → 14%

        $this->assertSame('Rent 2 RM 1.00 29%', $this->block($response, 'data-category-row="rent"'));
        $this->assertSame('Office supplies 1 RM 1.00 29%', $this->block($response, 'data-category-row="office"'));
        $this->assertSame('Travel & transport 1 RM 0.50 14%', $this->block($response, 'data-category-row="travel"'));
        $this->assertSame('Total 5 RM 3.50 100%', $this->block($response, 'data-category-row="total"'));
        $this->assertStringStartsWith('Expenses, 1 Jan – 29 Sep 2026 RM 3.50 5 expenses', $this->block($response, 'data-report-expense-total'));
        $response->assertSee('style="width: 29%"', false);
    }

    public function test_shares_round_half_up(): void
    {
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-03-02', 'category' => 'office', 'amount' => '7.00']); // 1/8 = 12.5% → 13%

        $this->assertStringEndsWith('13%', $this->block($this->expenses(), 'data-category-row="rent"'));
    }

    public function test_real_spending_that_rounds_to_zero_shows_less_than_one_percent(): void
    {
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '1000.00']);
        $this->expense(['expense_date' => '2026-03-02', 'category' => 'office', 'amount' => '4.99']); // 0.497% → 0

        $response = $this->expenses();

        $this->assertSame('Office supplies 1 RM 4.99 <1%', $this->block($response, 'data-category-row="office"'));
        $this->assertSame('Rent 1 RM 1,000.00 100%', $this->block($response, 'data-category-row="rent"'));
        $this->assertMatchesRegularExpression('/data-category-row="office".*?style="width: 1%"/s', $response->getContent());
        $this->assertSame('Meals & entertainment 0 RM 0.00 —', $this->block($response, 'data-category-row="meals"'));
    }

    public function test_period_boundaries_are_inclusive(): void
    {
        $this->expense(['expense_date' => '2026-01-31', 'category' => 'rent', 'amount' => '1.00']);
        $this->expense(['expense_date' => '2026-02-01', 'category' => 'rent', 'amount' => '10.00']);
        $this->expense(['expense_date' => '2026-02-28', 'category' => 'rent', 'amount' => '100.00']);
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '1000.00']);

        $this->assertSame('Rent 2 RM 110.00 100%', $this->block($this->expenses(['period' => 'custom', 'from' => '2026-02-01', 'to' => '2026-02-28']), 'data-category-row="rent"'));
    }

    public function test_links_go_to_the_filtered_expense_list(): void
    {
        $response = $this->expenses(['period' => 'last_year']);

        $response->assertSee(route('expenses.index', ['from' => '2025-01-01', 'to' => '2025-12-31']));
        $response->assertSee(route('expenses.index', ['category' => 'bank_fees', 'from' => '2025-01-01', 'to' => '2025-12-31']));
        $response->assertSee('No expenses recorded in this period.');
        $this->assertSame('Total 0 RM 0.00 —', $this->block($response, 'data-category-row="total"'));
    }

    public function test_another_users_expenses_never_count(): void
    {
        $other = User::factory()->create();
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '5.00']);
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'rent', 'amount' => '500.00'], $other);
        $this->expense(['expense_date' => '2026-03-01', 'category' => 'meals', 'amount' => '700.00'], $other);

        $response = $this->expenses();

        $this->assertSame('Rent 1 RM 5.00 100%', $this->block($response, 'data-category-row="rent"'));
        $this->assertSame('Meals & entertainment 0 RM 0.00 —', $this->block($response, 'data-category-row="meals"'));
        $this->assertSame('Total 1 RM 5.00 100%', $this->block($response, 'data-category-row="total"'));
    }
}
