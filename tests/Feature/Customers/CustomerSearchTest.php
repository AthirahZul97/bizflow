<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function searchableFields(): array
    {
        return [
            'name' => ['name', 'Zainab Ali', 'Zainab'],
            'company' => ['company_name', 'Orchid Florist', 'Orchid'],
            'email' => ['email', 'billing@orchid.test', 'billing@orchid'],
            'phone' => ['phone', '+60 3-8888 1234', '8888'],
        ];
    }

    #[DataProvider('searchableFields')]
    public function test_customers_can_be_searched_by_field(string $field, string $value, string $term): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Target Customer', $field => $value]);
        Customer::factory()->ownedBy($user)->create([
            'name' => 'Unrelated Person',
            'company_name' => null,
            'email' => null,
            'phone' => null,
        ]);

        $response = $this->actingAs($user)->get(route('customers.index', ['search' => $term]));

        $response->assertOk();
        $response->assertSee($field === 'name' ? $value : 'Target Customer');
        $response->assertDontSee('Unrelated Person');
    }

    public function test_search_never_returns_another_users_customers(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Acme Mine']);
        Customer::factory()->create(['name' => 'Acme Theirs', 'company_name' => 'Acme', 'email' => 'acme@theirs.test']);

        $response = $this->actingAs($user)->get(route('customers.index', ['search' => 'Acme']));

        $response->assertSee('Acme Mine');
        $response->assertDontSee('Acme Theirs');
    }

    public function test_search_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'ACME Corporation']);

        $this->actingAs($user)->get(route('customers.index', ['search' => 'acme']))
            ->assertSee('ACME Corporation');
    }

    public function test_like_wildcards_in_search_are_matched_literally(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Promo 50% Off']);
        Customer::factory()->ownedBy($user)->create(['name' => 'Promo 500 Off']);
        Customer::factory()->ownedBy($user)->create(['name' => 'Code A_B']);
        Customer::factory()->ownedBy($user)->create(['name' => 'Code AXB']);

        $this->actingAs($user)->get(route('customers.index', ['search' => '50%']))
            ->assertSee('Promo 50% Off')
            ->assertDontSee('Promo 500 Off');

        $this->actingAs($user)->get(route('customers.index', ['search' => 'A_B']))
            ->assertSee('Code A_B')
            ->assertDontSee('Code AXB');
    }

    public function test_non_string_search_input_is_ignored(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Visible Customer']);

        $this->actingAs($user)->get(route('customers.index', ['search' => ['x']]))
            ->assertOk()
            ->assertSee('Visible Customer');
    }

    public function test_customers_are_sorted_by_name(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Charlie Co']);
        Customer::factory()->ownedBy($user)->create(['name' => 'Alpha Co']);
        Customer::factory()->ownedBy($user)->create(['name' => 'Bravo Co']);

        $this->actingAs($user)->get(route('customers.index'))
            ->assertSeeInOrder(['Alpha Co', 'Bravo Co', 'Charlie Co']);
    }

    public function test_customers_are_paginated_fifteen_per_page(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $i) {
            Customer::factory()->ownedBy($user)->create(['name' => sprintf('Customer %02d', $i)]);
        }

        $this->actingAs($user)->get(route('customers.index'))
            ->assertSee('Customer 15')
            ->assertDontSee('Customer 16');

        $this->actingAs($user)->get(route('customers.index', ['page' => 2]))
            ->assertSee('Customer 16')
            ->assertDontSee('Customer 15');
    }

    public function test_pagination_links_keep_the_search_term(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $i) {
            Customer::factory()->ownedBy($user)->create(['name' => sprintf('Acme %02d', $i)]);
        }

        $this->actingAs($user)->get(route('customers.index', ['search' => 'Acme']))
            ->assertSee('search=Acme&page=2');
    }

    public function test_empty_state_is_shown_when_user_has_no_customers(): void
    {
        Customer::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('customers.index'))
            ->assertOk()
            ->assertSee("You haven't added any customers yet.", false)
            ->assertSee('Create your first customer');
    }

    public function test_empty_state_is_shown_when_search_has_no_matches(): void
    {
        $user = User::factory()->create();
        Customer::factory()->ownedBy($user)->create(['name' => 'Existing Customer']);

        $this->actingAs($user)->get(route('customers.index', ['search' => 'Nomatch']))
            ->assertOk()
            ->assertSee('No customers match')
            ->assertSee('Nomatch')
            ->assertDontSee('Existing Customer');
    }
}
