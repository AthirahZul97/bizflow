<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function expenseRoutes(): array
    {
        return [
            'index' => ['get', 'expenses.index', false],
            'create' => ['get', 'expenses.create', false],
            'store' => ['post', 'expenses.store', false],
            'show' => ['get', 'expenses.show', true],
            'edit' => ['get', 'expenses.edit', true],
            'update' => ['put', 'expenses.update', true],
            'delete confirmation' => ['get', 'expenses.delete', true],
            'destroy' => ['delete', 'expenses.destroy', true],
        ];
    }

    #[DataProvider('expenseRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, bool $needsExpense): void
    {
        $expense = Expense::factory()->create();

        $url = $needsExpense ? route($route, $expense) : route($route);

        $this->{$method}($url)->assertRedirect(route('login'));
        $this->assertModelExists($expense);
        $this->assertSame(1, Expense::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function expensePages(): array
    {
        return [
            'show' => ['expenses.show'],
            'edit' => ['expenses.edit'],
            'delete confirmation' => ['expenses.delete'],
        ];
    }

    #[DataProvider('expensePages')]
    public function test_users_cannot_view_pages_for_another_users_expense(string $route): void
    {
        $intruder = User::factory()->create();
        $expense = Expense::factory()->create(['description' => 'Secret Rent Payment', 'payee' => 'Secret Landlord']);

        $response = $this->actingAs($intruder)->get(route($route, $expense));

        $response->assertNotFound();
        $response->assertDontSee('Secret Rent Payment');
        $response->assertDontSee('Secret Landlord');
    }

    #[DataProvider('expensePages')]
    public function test_owners_can_view_pages_for_their_own_expense(string $route): void
    {
        $expense = Expense::factory()->create();

        $this->actingAs($expense->user)->get(route($route, $expense))->assertOk();
    }

    public function test_users_cannot_update_another_users_expense(): void
    {
        $intruder = User::factory()->create();
        $expense = Expense::factory()->create(['description' => 'Original', 'amount' => '100.00']);
        $before = $expense->fresh()->getAttributes();

        $this->actingAs($intruder)->put(route('expenses.update', $expense), [
            'expense_date' => '2026-09-01',
            'category' => 'rent',
            'description' => 'Hijacked',
            'amount' => '0.01',
        ])->assertNotFound();

        $this->assertSame($before, $expense->fresh()->getAttributes());
    }

    public function test_cross_user_update_with_invalid_payload_is_still_not_found(): void
    {
        $intruder = User::factory()->create();
        $expense = Expense::factory()->create();
        $before = $expense->fresh()->getAttributes();

        $this->actingAs($intruder)->put(route('expenses.update', $expense), [
            'expense_date' => 'not-a-date',
            'category' => 'bogus',
            'description' => '',
            'amount' => '-5',
        ])->assertNotFound()->assertSessionHasNoErrors();

        $this->assertSame($before, $expense->fresh()->getAttributes());
    }

    public function test_users_cannot_delete_another_users_expense(): void
    {
        $intruder = User::factory()->create();
        $expense = Expense::factory()->create();

        $this->actingAs($intruder)->delete(route('expenses.destroy', $expense))->assertNotFound();

        $this->assertModelExists($expense);
    }

    public function test_missing_expense_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/expenses/999999')->assertNotFound();
    }
}
