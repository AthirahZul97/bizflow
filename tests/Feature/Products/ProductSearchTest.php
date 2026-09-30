<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function searchableFields(): array
    {
        return [
            'name' => ['name', 'Business Card Printing', 'Card Print'],
            'sku' => ['sku', 'BCP-2026', 'bcp-20'],
            'description' => ['description', 'Glossy 350gsm finish', '350gsm'],
        ];
    }

    #[DataProvider('searchableFields')]
    public function test_items_can_be_searched_by_field(string $field, string $value, string $term): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Target Item', $field => $value]);
        Product::factory()->ownedBy($user)->create(['name' => 'Unrelated Item', 'sku' => null, 'description' => null]);

        $response = $this->actingAs($user)->get(route('products.index', ['search' => $term]));

        $response->assertOk();
        $response->assertSee($field === 'name' ? $value : 'Target Item');
        $response->assertDontSee('Unrelated Item');
    }

    public function test_search_never_returns_another_users_items(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Consultation Mine']);
        Product::factory()->create([
            'name' => 'Consultation Theirs',
            'sku' => 'CONSULTATION',
            'description' => 'Consultation',
        ]);

        $response = $this->actingAs($user)->get(route('products.index', ['search' => 'Consultation']));

        $response->assertSee('Consultation Mine');
        $response->assertDontSee('Consultation Theirs');
    }

    public function test_search_is_case_insensitive(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'WEBSITE Development']);

        $this->actingAs($user)->get(route('products.index', ['search' => 'website']))
            ->assertSee('WEBSITE Development');
    }

    public function test_like_wildcards_and_escape_character_are_matched_literally(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Promo 50% Off']);
        Product::factory()->ownedBy($user)->create(['name' => 'Promo 500 Off']);
        Product::factory()->ownedBy($user)->create(['name' => 'Kit A_B']);
        Product::factory()->ownedBy($user)->create(['name' => 'Kit AXB']);
        Product::factory()->ownedBy($user)->create(['name' => 'Wow! Deal']);
        Product::factory()->ownedBy($user)->create(['name' => 'Wow Deal']);

        $this->actingAs($user)->get(route('products.index', ['search' => '50%']))
            ->assertSee('Promo 50% Off')
            ->assertDontSee('Promo 500 Off');

        $this->actingAs($user)->get(route('products.index', ['search' => 'A_B']))
            ->assertSee('Kit A_B')
            ->assertDontSee('Kit AXB');

        $this->actingAs($user)->get(route('products.index', ['search' => 'Wow!']))
            ->assertSee('Wow! Deal')
            ->assertDontSee('Wow Deal');
    }

    public function test_items_can_be_filtered_by_type(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Laptop Stand']);
        Product::factory()->ownedBy($user)->service()->create(['name' => 'Consultation']);

        $this->actingAs($user)->get(route('products.index', ['type' => 'service']))
            ->assertSee('Consultation')
            ->assertDontSee('Laptop Stand');

        $this->actingAs($user)->get(route('products.index', ['type' => 'product']))
            ->assertSee('Laptop Stand')
            ->assertDontSee('Consultation');
    }

    public function test_items_can_be_filtered_by_status(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Current Offer']);
        Product::factory()->ownedBy($user)->inactive()->create(['name' => 'Retired Offer']);

        $this->actingAs($user)->get(route('products.index'))
            ->assertSee('Current Offer')
            ->assertSee('Retired Offer');

        $this->actingAs($user)->get(route('products.index', ['status' => 'active']))
            ->assertSee('Current Offer')
            ->assertDontSee('Retired Offer');

        $this->actingAs($user)->get(route('products.index', ['status' => 'inactive']))
            ->assertSee('Retired Offer')
            ->assertDontSee('Current Offer');
    }

    public function test_search_and_filters_combine(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->service()->create(['name' => 'Web Hosting']);
        Product::factory()->ownedBy($user)->service()->inactive()->create(['name' => 'Web Legacy Support']);
        Product::factory()->ownedBy($user)->create(['name' => 'Web Camera']);
        Product::factory()->ownedBy($user)->service()->create(['name' => 'Consultation']);

        $this->actingAs($user)->get(route('products.index', ['search' => 'Web', 'type' => 'service', 'status' => 'active']))
            ->assertSee('Web Hosting')
            ->assertDontSee('Web Legacy Support')
            ->assertDontSee('Web Camera')
            ->assertDontSee('Consultation');
    }

    public function test_invalid_filter_values_are_ignored(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Visible Item']);

        $this->actingAs($user)->get(route('products.index', ['type' => 'bundle', 'status' => 'deleted', 'search' => ['x']]))
            ->assertOk()
            ->assertSee('Visible Item');
    }

    public function test_items_are_sorted_by_name(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Charlie Item']);
        Product::factory()->ownedBy($user)->create(['name' => 'Alpha Item']);
        Product::factory()->ownedBy($user)->create(['name' => 'Bravo Item']);

        $this->actingAs($user)->get(route('products.index'))
            ->assertSeeInOrder(['Alpha Item', 'Bravo Item', 'Charlie Item']);
    }

    public function test_items_are_paginated_fifteen_per_page(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $i) {
            Product::factory()->ownedBy($user)->create(['name' => sprintf('Item %02d', $i)]);
        }

        $this->actingAs($user)->get(route('products.index'))
            ->assertSee('Item 15')
            ->assertDontSee('Item 16');

        $this->actingAs($user)->get(route('products.index', ['page' => 2]))
            ->assertSee('Item 16')
            ->assertDontSee('Item 15');
    }

    public function test_pagination_links_keep_search_and_filters(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 16) as $i) {
            Product::factory()->ownedBy($user)->service()->create(['name' => sprintf('Web %02d', $i)]);
        }

        $this->actingAs($user)->get(route('products.index', ['search' => 'Web', 'type' => 'service', 'status' => 'active']))
            ->assertSee('search=Web&type=service&status=active&page=2');
    }

    public function test_empty_state_is_shown_when_user_has_no_items(): void
    {
        Product::factory()->create();

        $this->actingAs(User::factory()->create())->get(route('products.index'))
            ->assertOk()
            ->assertSee("You haven't added any products or services yet.", false)
            ->assertSee('Create your first product or service');
    }

    public function test_empty_state_is_shown_when_filters_have_no_matches(): void
    {
        $user = User::factory()->create();
        Product::factory()->ownedBy($user)->create(['name' => 'Existing Item']);

        $this->actingAs($user)->get(route('products.index', ['type' => 'service']))
            ->assertOk()
            ->assertSee('No items match your search or filters.')
            ->assertDontSee('Existing Item');
    }
}
