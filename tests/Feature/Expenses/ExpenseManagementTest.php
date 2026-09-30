<?php

namespace Tests\Feature\Expenses;

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // "Today" is 28 Sep 2026 in the application's Asia/Kuala_Lumpur timezone.
        $this->travelTo('2026-09-28 10:00:00');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'expense_date' => '2026-09-15',
            'category' => 'rent',
            'description' => 'October office rent',
            'amount' => '2500.00',
            'payee' => 'Mawar Properties',
            'notes' => 'Paid by bank transfer, ref 88123.',
        ], $overrides);
    }

    public function test_index_lists_only_the_users_own_expenses(): void
    {
        $user = User::factory()->create();
        Expense::factory()->ownedBy($user)->create(['description' => 'My Electricity Bill']);
        Expense::factory()->create(['description' => 'Their Electricity Bill']);

        $this->actingAs($user)->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('My Electricity Bill')
            ->assertDontSee('Their Electricity Bill');
    }

    public function test_create_form_defaults_to_today_and_lists_all_categories(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('expenses.create'));

        $response->assertOk();
        $response->assertSee('name="_token"', false);
        $response->assertSee('value="2026-09-28"', false);
        foreach (ExpenseCategory::cases() as $case) {
            $response->assertSee($case->label());
        }
    }

    public function test_users_can_record_an_expense(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('expenses.store'), $this->validPayload());

        $expense = Expense::sole();
        $response->assertRedirect(route('expenses.show', $expense));
        $response->assertSessionHas('status', 'Expense recorded.');
        $this->assertTrue($expense->business->is($this->businessOf($user)));
        $this->assertTrue($expense->creator->is($user));
        $this->assertSame('2026-09-15', $expense->expense_date->toDateString());
        $this->assertSame(ExpenseCategory::Rent, $expense->category);
        $this->assertSame('2500.00', $expense->amount);
        $this->assertSame('Mawar Properties', $expense->payee);

        $this->get(route('expenses.show', $expense))
            ->assertSee('October office rent')
            ->assertSee('RM 2,500.00')
            ->assertSee('15 Sep 2026')
            ->assertSee('Rent')
            ->assertSee('Mawar Properties')
            ->assertSee('Paid by bank transfer, ref 88123.');
    }

    public function test_optional_fields_may_be_blank_and_are_stored_as_null(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('expenses.store'), $this->validPayload(['payee' => '', 'notes' => '']))
            ->assertSessionHasNoErrors();

        $expense = Expense::sole();
        $this->assertNull($expense->payee);
        $this->assertNull($expense->notes);
    }

    public function test_users_can_update_their_expense(): void
    {
        $expense = Expense::factory()->create(['description' => 'Old']);

        $response = $this->actingAs($this->ownerOf($expense))->put(
            route('expenses.update', $expense),
            $this->validPayload(['description' => 'New', 'amount' => '99.9', 'category' => 'software']),
        );

        $response->assertRedirect(route('expenses.show', $expense));
        $response->assertSessionHas('status', 'Expense updated.');
        $expense->refresh();
        $this->assertSame('New', $expense->description);
        $this->assertSame('99.90', $expense->amount);
        $this->assertSame(ExpenseCategory::Software, $expense->category);
    }

    public function test_edit_form_shows_saved_values(): void
    {
        $expense = Expense::factory()->create(['description' => 'Printer ink', 'amount' => '45.50', 'expense_date' => '2026-08-03']);

        $this->actingAs($this->ownerOf($expense))->get(route('expenses.edit', $expense))
            ->assertOk()
            ->assertSee('value="Printer ink"', false)
            ->assertSee('value="45.50"', false)
            ->assertSee('value="2026-08-03"', false);
    }

    public function test_invalid_update_leaves_the_expense_unchanged(): void
    {
        $expense = Expense::factory()->create(['description' => 'Keep Me']);

        $this->actingAs($this->ownerOf($expense))
            ->from(route('expenses.edit', $expense))
            ->put(route('expenses.update', $expense), $this->validPayload(['description' => '', 'amount' => '-1']))
            ->assertRedirect(route('expenses.edit', $expense))
            ->assertSessionHasErrors(['description', 'amount']);

        $this->assertSame('Keep Me', $expense->fresh()->description);
    }

    public function test_forged_business_id_and_created_by_are_ignored(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();
        $forged = ['business_id' => $this->businessOf($victim)->id, 'created_by' => $victim->id, 'user_id' => $victim->id];

        $this->actingAs($user)->post(route('expenses.store'), $this->validPayload($forged));
        $expense = Expense::sole();
        $this->assertSame($this->businessOf($user)->id, $expense->business_id);
        $this->assertSame($user->id, $expense->created_by);

        $this->actingAs($user)->put(route('expenses.update', $expense), $this->validPayload($forged));
        $this->assertSame($this->businessOf($user)->id, $expense->fresh()->business_id);
        $this->assertSame($user->id, $expense->fresh()->created_by);
        $this->assertSame(0, $this->businessOf($victim)->expenses()->count());
    }

    public function test_id_and_timestamps_cannot_be_mass_assigned(): void
    {
        $this->actingAs(User::factory()->create())->post(route('expenses.store'), $this->validPayload([
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
        ]));

        $expense = Expense::sole();
        $this->assertNotSame(999, $expense->id);
        $this->assertNotSame('2000', $expense->created_at->format('Y'));
        $this->assertNotSame('2000', $expense->updated_at->format('Y'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing date' => ['expense_date', ''],
            'date wrong format' => ['expense_date', '15/09/2026'],
            'impossible date' => ['expense_date', '2026-02-30'],
            'date as text' => ['expense_date', 'yesterday'],
            'future date' => ['expense_date', '2026-09-29'],
            'date before 2000' => ['expense_date', '1999-12-31'],
            'missing category' => ['category', ''],
            'unknown category' => ['category', 'payroll'],
            'category as label' => ['category', 'Rent'],
            'missing description' => ['description', ''],
            'description too long' => ['description', str_repeat('a', 256)],
            'description as array' => ['description', ['nested']],
            'payee too long' => ['payee', str_repeat('a', 256)],
            'payee as array' => ['payee', ['nested']],
            'notes too long' => ['notes', str_repeat('a', 5001)],
            'missing amount' => ['amount', ''],
            'zero amount' => ['amount', '0'],
            'zero amount with decimals' => ['amount', '0.00'],
            'negative amount' => ['amount', '-1'],
            'three decimal places' => ['amount', '12.345'],
            'scientific notation' => ['amount', '1e3'],
            'comma formatted' => ['amount', '1,500.00'],
            'text amount' => ['amount', 'abc'],
            'one cent over maximum' => ['amount', '10000000000000.00'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(string $field, mixed $value): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->from(route('expenses.create'))
            ->post(route('expenses.store'), $this->validPayload([$field => $value]));

        $response->assertRedirect(route('expenses.create'));
        $response->assertSessionHasErrors($field);
        $this->assertSame(0, Expense::count());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function boundaryInput(): array
    {
        return [
            'today' => ['expense_date', '2026-09-28'],
            'first allowed date' => ['expense_date', '2000-01-01'],
            'smallest amount' => ['amount', '0.01'],
            'whole number amount' => ['amount', '1500'],
            'one decimal amount' => ['amount', '19.9'],
            'maximum amount' => ['amount', '9999999999999.99'],
            'longest description' => ['description', str_repeat('a', 255)],
            'longest payee' => ['payee', str_repeat('a', 255)],
            'longest notes' => ['notes', str_repeat('a', 5000)],
        ];
    }

    #[DataProvider('boundaryInput')]
    public function test_boundary_values_are_accepted(string $field, mixed $value): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('expenses.store'), $this->validPayload([$field => $value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());
    }

    public function test_the_validation_maximum_matches_the_column(): void
    {
        $this->assertSame('9999999999999.99', Expense::MAX_AMOUNT);
    }

    public function test_today_follows_the_malaysian_timezone(): void
    {
        $this->assertSame('Asia/Kuala_Lumpur', config('app.timezone'));

        // 20:00 UTC on 27 Sep is already 04:00 on 28 Sep in Kuala Lumpur.
        $this->travelTo(now('UTC')->setDate(2026, 9, 27)->setTime(20, 0));
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('expenses.store'), $this->validPayload(['expense_date' => '2026-09-28']))
            ->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('expenses.store'), $this->validPayload(['expense_date' => '2026-09-29']))
            ->assertSessionHasErrors(['expense_date' => 'The expense date cannot be in the future.']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function amounts(): array
    {
        return [
            'whole number' => ['1500', '1500.00', 'RM 1,500.00'],
            'one decimal' => ['19.9', '19.90', 'RM 19.90'],
            'smallest' => ['0.01', '0.01', 'RM 0.01'],
            'large' => ['1234567890.12', '1234567890.12', 'RM 1,234,567,890.12'],
        ];
    }

    #[DataProvider('amounts')]
    public function test_amounts_are_stored_as_exact_strings_and_displayed_with_currency(string $input, string $stored, string $displayed): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('expenses.store'), $this->validPayload(['amount' => $input]));

        $expense = Expense::sole();
        $this->assertSame($stored, $expense->amount);
        $this->actingAs($user)->get(route('expenses.show', $expense))->assertSee($displayed);
    }

    public function test_user_supplied_content_is_escaped(): void
    {
        $expense = Expense::factory()->create([
            'description' => '<b>Bold Rent</b>',
            'payee' => '<i>Italic Landlord</i>',
            'notes' => "<script>alert('x')</script>\nSecond line",
        ]);
        $user = $this->ownerOf($expense);

        $this->actingAs($user)->get(route('expenses.show', $expense))
            ->assertDontSee('<b>Bold Rent</b>', false)
            ->assertDontSee('<i>Italic Landlord</i>', false)
            ->assertDontSee("<script>alert('x')</script>", false)
            ->assertSee('&lt;script&gt;', false)
            ->assertSee('<br />', false);

        $this->actingAs($user)->get(route('expenses.index'))
            ->assertDontSee('<b>Bold Rent</b>', false)
            ->assertDontSee('<i>Italic Landlord</i>', false);

        $this->actingAs($user)->get(route('expenses.edit', $expense))
            ->assertDontSee('<b>Bold Rent</b>', false);

        $this->actingAs($user)->get(route('expenses.delete', $expense))
            ->assertDontSee('<b>Bold Rent</b>', false);
    }

    public function test_delete_confirmation_page_does_not_delete(): void
    {
        $expense = Expense::factory()->create([
            'description' => 'Still Here', 'amount' => '120.00', 'expense_date' => '2026-09-01',
        ]);

        $response = $this->actingAs($this->ownerOf($expense))->get(route('expenses.delete', $expense));

        $response->assertOk();
        $response->assertSee('Still Here');
        $response->assertSee('RM 120.00');
        $response->assertSee('01 Sep 2026');
        $response->assertSee('cannot be undone');
        $response->assertSee('name="_method" value="DELETE"', false);
        $response->assertSee('name="_token"', false);
        $this->assertModelExists($expense);
    }

    public function test_users_can_delete_their_expense(): void
    {
        $expense = Expense::factory()->create();

        $response = $this->actingAs($this->ownerOf($expense))->delete(route('expenses.destroy', $expense));

        $response->assertRedirect(route('expenses.index'));
        $response->assertSessionHas('status', 'Expense deleted.');
        $this->assertModelMissing($expense);
    }

    public function test_deleting_the_business_owner_is_blocked_and_keeps_the_expenses(): void
    {
        $expense = Expense::factory()->create();

        $this->assertThrows(fn () => $this->ownerOf($expense)->delete(), QueryException::class);

        $this->assertModelExists($expense);
    }

    public function test_deleting_the_creator_keeps_the_expense_and_clears_created_by(): void
    {
        // A creator who is no longer a member (the membership would otherwise block the delete).
        $creator = User::factory()->withoutBusiness()->create();
        $expense = Expense::factory()->create(['created_by' => $creator->id]);

        $creator->delete();

        $this->assertModelExists($expense);
        $this->assertNull($expense->fresh()->created_by);
    }
}
