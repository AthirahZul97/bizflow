<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function productRoutes(): array
    {
        return [
            'index' => ['get', 'products.index', false],
            'create' => ['get', 'products.create', false],
            'store' => ['post', 'products.store', false],
            'show' => ['get', 'products.show', true],
            'edit' => ['get', 'products.edit', true],
            'update' => ['put', 'products.update', true],
            'delete confirmation' => ['get', 'products.delete', true],
            'destroy' => ['delete', 'products.destroy', true],
        ];
    }

    #[DataProvider('productRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, bool $needsProduct): void
    {
        $product = Product::factory()->create();

        $url = $needsProduct ? route($route, $product) : route($route);

        $this->{$method}($url)->assertRedirect(route('login'));
        $this->assertModelExists($product);
        $this->assertSame(1, Product::count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function productPages(): array
    {
        return [
            'show' => ['products.show'],
            'edit' => ['products.edit'],
            'delete confirmation' => ['products.delete'],
        ];
    }

    #[DataProvider('productPages')]
    public function test_users_cannot_view_pages_for_another_users_item(string $route): void
    {
        $intruder = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Secret Consulting Package']);

        $response = $this->actingAs($intruder)->get(route($route, $product));

        $response->assertNotFound();
        $response->assertDontSee('Secret Consulting Package');
    }

    #[DataProvider('productPages')]
    public function test_owners_can_view_pages_for_their_own_item(string $route): void
    {
        $product = Product::factory()->create();

        $this->actingAs($product->user)->get(route($route, $product))->assertOk();
    }

    public function test_users_cannot_update_another_users_item(): void
    {
        $intruder = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Original Name', 'selling_price' => '100.00']);

        $response = $this->actingAs($intruder)->put(route('products.update', $product), [
            'type' => 'product',
            'name' => 'Hijacked',
            'selling_price' => '0.01',
            'is_active' => '1',
        ]);

        $response->assertNotFound();
        $product->refresh();
        $this->assertSame('Original Name', $product->name);
        $this->assertSame('100.00', $product->selling_price);
    }

    public function test_authorization_runs_before_validation_on_update(): void
    {
        $intruder = User::factory()->create();
        $product = Product::factory()->create();

        $response = $this->actingAs($intruder)->put(route('products.update', $product), [
            'type' => 'bundle',
            'name' => '',
            'selling_price' => '-5',
        ]);

        $response->assertNotFound();
        $response->assertSessionHasNoErrors();
    }

    public function test_users_cannot_delete_another_users_item(): void
    {
        $intruder = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($intruder)->delete(route('products.destroy', $product))->assertNotFound();

        $this->assertModelExists($product);
    }

    public function test_missing_item_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/products/999999')->assertNotFound();
    }
}
