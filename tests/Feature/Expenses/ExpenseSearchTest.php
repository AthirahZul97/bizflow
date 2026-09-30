<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseSearchTest extends TestCase
{
    use RefreshDatabase;

    private function index(User $user, array $query = []): TestResponse
    {
        return $this->actingAs($user)->get(route('expenses.index', $query));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function searchableFields(): array
    {
        return [
            'description' => ['description', 'Printer ink cartridges', 'ink cart'],
            'payee' => ['payee', 'Tenaga Nasional', 'tenaga'],
        ];
    }

    #[DataProvider('searchableFields')]
    public function test_expenses_can_be_searched_case_insensitively(string $field, string $value, string $term): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Target Expense', $field => $value]);
        Expense::factory()->ownedBy($user)->create(['description' => 'Unrelated Expense', 'payee' => null]);

        $this->index($user, ['search' => $term])
            ->assertOk()
            ->assertSee($field === 'description' ? $value : 'Target Expense')
            ->assertDontSee('Unrelated Expense');
    }

    public function test_search_does_not_match_notes_or_category(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Coffee beans', 'notes' => 'zebra', 'category' => 'rent', 'payee' => null]);

        $this->index($user, ['search' => 'zebra'])->assertDontSee('Coffee beans');
        $this->index($user, ['search' => 'rent'])->assertDontSee('Coffee beans');
    }

    public function test_search_never_returns_another_users_expenses(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Rent Mine', 'payee' => null]);
        Expense::factory()->create(['description' => 'Rent Theirs', 'payee' => 'Rent Landlord']);

        $this->index($user, ['search' => 'Rent'])
            ->assertSee('Rent Mine')
            ->assertDontSee('Rent Theirs');
    }

    public function test_like_wildcards_and_escape_character_are_matched_literally(): void
    {
        $user = User::factory()->create();
        foreach (['Promo 50% Off', 'Promo 500 Off', 'Kit A_B', 'Kit AXB', 'Wow! Deal', 'Wow Deal'] as $description) {
            Expense::factory()->ownedBy($user)->create(['description' => $description, 'payee' => null]);
        }

        $this->index($user, ['search' => '50%'])->assertSee('Promo 50% Off')->assertDontSee('Promo 500 Off');
        $this->index($user, ['search' => 'A_B'])->assertSee('Kit A_B')->assertDontSee('Kit AXB');
        $this->index($user, ['search' => 'Wow!'])->assertSee('Wow! Deal')->assertDontSee('Wow Deal');
    }

    public function test_expenses_can_be_filtered_by_category(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Office Rent', 'category' => 'rent']);
        Expense::factory()->ownedBy($user)->create(['description' => 'Canva Pro', 'category' => 'software']);

        $this->index($user, ['category' => 'software'])
            ->assertSee('Canva Pro')
            ->assertDontSee('Office Rent');
    }

    public function test_date_filters_are_inclusive(): void
    {
        $user = User::factory()->create();
        foreach (['2026-08-31' => 'Before Range', '2026-09-01' => 'First Day', '2026-09-15' => 'Middle Day', '2026-09-30' => 'Last Day', '2026-10-01' => 'After Range'] as $date => $description) {
            Expense::factory()->ownedBy($user)->create(['expense_date' => $date, 'description' => $description]);
        }

        $this->index($user, ['from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertSee('First Day')->assertSee('Middle Day')->assertSee('Last Day')
            ->assertDontSee('Before Range')->assertDontSee('After Range');

        $this->index($user, ['from' => '2026-09-30'])
            ->assertSee('Last Day')->assertSee('After Range')->assertDontSee('Middle Day');

        $this->index($user, ['to' => '2026-09-01'])
            ->assertSee('First Day')->assertSee('Before Range')->assertDontSee('Middle Day');
    }

    public function test_from_after_to_returns_no_results_rather_than_swapping(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['expense_date' => '2026-09-15', 'description' => 'Mid September']);

        $this->index($user, ['from' => '2026-09-30', 'to' => '2026-09-01'])
            ->assertDontSee('Mid September')
            ->assertSee('No expenses match your search or filters.');
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Visible Expense']);

        $this->index($user, [
            'from' => '2026-02-30',
            'to' => 'next week',
            'category' => 'payroll',
            'search' => ['x'],
        ])->assertOk()->assertSee('Visible Expense');

        $this->index($user, ['from' => ['2026-01-01'], 'to' => '2026/09/01'])
            ->assertOk()->assertSee('Visible Expense');
    }

    public function test_search_category_and_dates_combine(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'Grab ride', 'category' => 'travel', 'expense_date' => '2026-09-10']);
        Expense::factory()->ownedBy($user)->create(['description' => 'Grab ride old', 'category' => 'travel', 'expense_date' => '2026-07-10']);
        Expense::factory()->ownedBy($user)->create(['description' => 'Grab food', 'category' => 'meals', 'expense_date' => '2026-09-10']);
        Expense::factory()->ownedBy($user)->create(['description' => 'Taxi', 'category' => 'travel', 'expense_date' => '2026-09-10', 'payee' => null]);

        $this->index($user, ['search' => 'Grab', 'category' => 'travel', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertSee('Grab ride')
            ->assertDontSee('Grab ride old')
            ->assertDontSee('Grab food')
            ->assertDontSee('Taxi');
    }

    public function test_summary_counts_and_totals_only_the_users_filtered_expenses(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['category' => 'software', 'amount' => '0.10']);
        Expense::factory()->ownedBy($user)->create(['category' => 'software', 'amount' => '0.20']);
        Expense::factory()->ownedBy($user)->create(['category' => 'software', 'amount' => '1499.70']);
        Expense::factory()->ownedBy($user)->create(['category' => 'rent', 'amount' => '5000.00']);
        Expense::factory()->create(['category' => 'software', 'amount' => '777.00']);

        $this->index($user)
            ->assertSee('4 expenses')
            ->assertSee('Total <strong>RM 6,500.00</strong>', false);

        $this->index($user, ['category' => 'software'])
            ->assertSee('3 expenses')
            ->assertSee('Total <strong>RM 1,500.00</strong>', false)
            ->assertDontSee('777');
    }

    public function test_summary_covers_all_pages_not_just_the_current_one(): void
    {
        $user = User::factory()->create();
        Expense::factory()->count(20)->ownedBy($user)->create(['amount' => '10.00']);

        $this->index($user)
            ->assertSee('20 expenses')
            ->assertSee('Total <strong>RM 200.00</strong>', false);

        $this->index($user, ['page' => 2])
            ->assertSee('20 expenses')
            ->assertSee('Total <strong>RM 200.00</strong>', false);
    }

    public function test_summary_uses_singular_for_one_expense(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['amount' => '9.99']);

        $this->index($user)->assertSee('1 expense ')->assertSee('RM 9.99');
    }

    public function test_expenses_are_sorted_by_newest_date_then_newest_id(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['expense_date' => '2026-09-10', 'description' => 'Same Day First']);
        Expense::factory()->ownedBy($user)->create(['expense_date' => '2026-09-01', 'description' => 'Oldest']);
        Expense::factory()->ownedBy($user)->create(['expense_date' => '2026-09-20', 'description' => 'Newest']);
        Expense::factory()->ownedBy($user)->create(['expense_date' => '2026-09-10', 'description' => 'Same Day Second']);

        $this->index($user)->assertSeeInOrder(['Newest', 'Same Day Second', 'Same Day First', 'Oldest']);
    }

    public function test_expenses_are_paginated_fifteen_per_page(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $day) {
            Expense::factory()->ownedBy($user)->create([
                'expense_date' => sprintf('2026-08-%02d', $day),
                'description' => sprintf('Expense day %02d', $day),
            ]);
        }

        $this->index($user)->assertSee('Expense day 16')->assertSee('Expense day 02')->assertDontSee('Expense day 01');
        $this->index($user, ['page' => 2])->assertSee('Expense day 01')->assertDontSee('Expense day 02');
    }

    public function test_pagination_links_keep_all_filters(): void
    {
        $user = User::factory()->create();
        Expense::factory()->count(16)->ownedBy($user)->create([
            'description' => 'Grab ride', 'category' => 'travel', 'expense_date' => '2026-09-10',
        ]);

        $this->index($user, ['search' => 'Grab', 'category' => 'travel', 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->assertSee('search=Grab&category=travel&from=2026-09-01&to=2026-09-30&page=2');
    }

    public function test_filter_form_keeps_the_submitted_values(): void
    {
        $user = User::factory()->create();

        $response = $this->index($user, ['search' => 'Grab', 'category' => 'travel', 'from' => '2026-09-01', 'to' => '2026-09-30']);

        $response->assertSee('value="Grab"', false)
            ->assertSee('value="2026-09-01"', false)
            ->assertSee('value="2026-09-30"', false);
        $this->assertMatchesRegularExpression('/value="travel"[^>]*selected/', $response->getContent());
    }

    public function test_empty_states(): void
    {
        $user = User::factory()->create();

        $this->index($user)
            ->assertSee("You haven't recorded any expenses yet.", false)
            ->assertSee('Record your first expense');

        Expense::factory()->ownedBy($user)->create(['category' => 'rent']);

        $this->index($user, ['category' => 'meals'])
            ->assertSee('No expenses match your search or filters.');
    }
}
