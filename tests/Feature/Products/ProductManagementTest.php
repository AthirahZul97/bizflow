<?php

namespace Tests\Feature\Products;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'service',
            'name' => 'Website Development',
            'sku' => 'WEB-001',
            'description' => 'Five-page company website.',
            'unit' => 'project',
            'selling_price' => '1500.00',
            'cost_price' => '400.00',
            'is_active' => '1',
        ], $overrides);
    }

    public function test_index_lists_only_the_users_own_items(): void
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->create(['name' => 'My Laptop Stand']);
        Product::factory()->create(['name' => 'Someone Elses Stand']);

        $response = $this->actingAs($user)->get(route('products.index'));

        $response->assertOk();
        $response->assertSee('My Laptop Stand');
        $response->assertDontSee('Someone Elses Stand');
    }

    public function test_create_form_can_be_rendered_with_csrf_token_and_active_by_default(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('products.create'));

        $response->assertOk();
        $response->assertSee('name="_token"', false);
        $this->assertMatchesRegularExpression('/value="product"[^>]*checked/s', $response->getContent());
        $this->assertMatchesRegularExpression('/id="is_active"[^>]*checked/s', $response->getContent());
    }

    public function test_users_can_create_a_service(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('products.store'), $this->validPayload());

        $product = Product::sole();
        $response->assertRedirect(route('products.show', $product));
        $response->assertSessionHas('status', 'Service created.');
        $this->assertTrue($product->user->is($user));
        $this->assertSame(ProductType::Service, $product->type);
        $this->assertSame('1500.00', $product->selling_price);
        $this->assertSame('400.00', $product->cost_price);
        $this->assertTrue($product->is_active);
    }

    public function test_users_can_create_a_product(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('products.store'), $this->validPayload([
            'type' => 'product',
            'name' => 'Laptop Stand',
            'sku' => 'LS-100',
            'unit' => 'piece',
            'selling_price' => '89.90',
        ]));

        $product = Product::sole();
        $response->assertSessionHas('status', 'Product created.');
        $this->assertSame(ProductType::Product, $product->type);

        $this->get(route('products.show', $product))
            ->assertSee('Laptop Stand')
            ->assertSee('RM 89.90');
    }

    public function test_optional_fields_may_be_blank_and_are_stored_as_null(): void
    {
        $this->actingAs(User::factory()->create())->post(route('products.store'), [
            'type' => 'service',
            'name' => 'Consultation',
            'sku' => '',
            'description' => '',
            'unit' => '',
            'selling_price' => '0',
            'cost_price' => '',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertNull($product->sku);
        $this->assertNull($product->description);
        $this->assertNull($product->unit);
        $this->assertNull($product->cost_price);
        $this->assertSame('0.00', $product->selling_price);
    }

    public function test_forged_user_id_is_ignored_on_create(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->post(route('products.store'), $this->validPayload(['user_id' => $victim->id]));

        $this->assertSame($user->id, Product::sole()->user_id);
        $this->assertSame(0, $victim->products()->count());
    }

    public function test_non_fillable_attributes_are_ignored_on_create(): void
    {
        $this->actingAs(User::factory()->create())->post(route('products.store'), $this->validPayload([
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
        ]));

        $product = Product::sole();
        $this->assertNotSame(999, $product->id);
        $this->assertNotSame('2000', $product->created_at->format('Y'));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing name' => ['name', ''],
            'name too long' => ['name', str_repeat('a', 256)],
            'name as array' => ['name', ['nested']],
            'missing type' => ['type', ''],
            'unknown type' => ['type', 'bundle'],
            'sku with space' => ['sku', 'WEB 001'],
            'sku with symbol' => ['sku', 'WEB#001'],
            'sku too long' => ['sku', str_repeat('A', 65)],
            'description too long' => ['description', str_repeat('a', 2001)],
            'unit too long' => ['unit', str_repeat('a', 31)],
            'missing selling price' => ['selling_price', ''],
            'negative selling price' => ['selling_price', '-1'],
            'selling price three decimals' => ['selling_price', '12.345'],
            'selling price text' => ['selling_price', 'abc'],
            'selling price scientific' => ['selling_price', '1e3'],
            'selling price with comma' => ['selling_price', '1,500.00'],
            'selling price over maximum' => ['selling_price', '10000000000.00'],
            'negative cost price' => ['cost_price', '-0.01'],
            'cost price three decimals' => ['cost_price', '1.234'],
            'cost price scientific' => ['cost_price', '1e3'],
            'cost price over maximum' => ['cost_price', '10000000000'],
            'missing status' => ['is_active', null],
            'non-boolean status' => ['is_active', 'yes'],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(string $field, mixed $value): void
    {
        $payload = $this->validPayload([$field => $value]);
        if ($value === null) {
            unset($payload[$field]);
        }

        $response = $this->actingAs(User::factory()->create())
            ->from(route('products.create'))
            ->post(route('products.store'), $payload);

        $response->assertRedirect(route('products.create'));
        $response->assertSessionHasErrors($field);
        $this->assertSame(0, Product::count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function validPrices(): array
    {
        return [
            'zero' => ['0', '0.00'],
            'whole number' => ['1500', '1500.00'],
            'one decimal' => ['19.9', '19.90'],
            'two decimals' => ['19.99', '19.99'],
            'maximum' => ['9999999999.99', '9999999999.99'],
        ];
    }

    #[DataProvider('validPrices')]
    public function test_prices_are_stored_as_exact_two_decimal_strings(string $input, string $stored): void
    {
        $this->actingAs(User::factory()->create())->post(route('products.store'), $this->validPayload([
            'selling_price' => $input,
            'cost_price' => $input,
        ]))->assertSessionHasNoErrors();

        $product = Product::sole();
        $this->assertSame($stored, $product->selling_price);
        $this->assertSame($stored, $product->cost_price);
    }

    public function test_prices_are_displayed_with_currency_and_thousands_separators(): void
    {
        $product = Product::factory()->create([
            'selling_price' => '9999999999.99',
            'cost_price' => '1500',
        ]);

        $this->actingAs($product->user)->get(route('products.show', $product))
            ->assertSee('RM 9,999,999,999.99')
            ->assertSee('RM 1,500.00');
    }

    public function test_cost_price_is_shown_on_detail_page_but_never_in_the_list(): void
    {
        $product = Product::factory()->create(['selling_price' => '100.00', 'cost_price' => '777.77']);

        $this->actingAs($product->user)->get(route('products.index'))
            ->assertSee('RM 100.00')
            ->assertDontSee('777.77');

        $this->actingAs($product->user)->get(route('products.show', $product))
            ->assertSee('RM 777.77')
            ->assertSee('Internal');
    }

    public function test_sku_is_trimmed_and_uppercased(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('products.store'), $this->validPayload(['sku' => '  web-001/a_b.c  ']))
            ->assertSessionHasNoErrors();

        $this->assertSame('WEB-001/A_B.C', Product::sole()->sku);
    }

    public function test_duplicate_sku_for_the_same_user_is_rejected_regardless_of_case(): void
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->create(['sku' => 'WEB-001']);

        foreach (['WEB-001', 'web-001', ' Web-001 '] as $sku) {
            $this->actingAs($user)->post(route('products.store'), $this->validPayload(['sku' => $sku]))
                ->assertSessionHasErrors(['sku' => 'You already have an item with this SKU.']);
        }

        $this->assertSame(1, Product::count());
    }

    public function test_different_users_may_use_the_same_sku(): void
    {
        Product::factory()->create(['sku' => 'WEB-001']);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('products.store'), $this->validPayload(['sku' => 'WEB-001']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $user->products()->where('sku', 'WEB-001')->count());
    }

    public function test_multiple_items_may_have_no_sku(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('products.store'), $this->validPayload(['sku' => '']))->assertSessionHasNoErrors();
        $this->actingAs($user)->post(route('products.store'), $this->validPayload(['sku' => null]))->assertSessionHasNoErrors();

        $this->assertSame(2, $user->products()->whereNull('sku')->count());
    }

    public function test_database_rejects_duplicate_sku_for_the_same_user(): void
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->create(['sku' => 'WEB-001']);

        $this->expectException(UniqueConstraintViolationException::class);

        Product::factory()->for($user)->create(['sku' => 'WEB-001']);
    }

    public function test_users_can_update_their_item_keeping_its_own_sku(): void
    {
        $product = Product::factory()->create(['sku' => 'WEB-001', 'name' => 'Old Name']);

        $response = $this->actingAs($product->user)->put(
            route('products.update', $product),
            $this->validPayload(['name' => 'New Name', 'sku' => 'web-001', 'selling_price' => '2000']),
        );

        $response->assertRedirect(route('products.show', $product));
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('status', 'Service updated.');
        $product->refresh();
        $this->assertSame('New Name', $product->name);
        $this->assertSame('WEB-001', $product->sku);
        $this->assertSame('2000.00', $product->selling_price);
    }

    public function test_update_rejects_another_of_the_users_skus(): void
    {
        $user = User::factory()->create();
        Product::factory()->for($user)->create(['sku' => 'TAKEN-1']);
        $product = Product::factory()->for($user)->create(['sku' => 'MINE-1']);

        $this->actingAs($user)->put(route('products.update', $product), $this->validPayload(['sku' => 'taken-1']))
            ->assertSessionHasErrors('sku');

        $this->assertSame('MINE-1', $product->fresh()->sku);
    }

    public function test_update_cannot_change_the_owner(): void
    {
        $product = Product::factory()->create();
        $owner = $product->user;

        $this->actingAs($owner)->put(
            route('products.update', $product),
            $this->validPayload(['user_id' => User::factory()->create()->id]),
        );

        $this->assertSame($owner->id, $product->fresh()->user_id);
    }

    public function test_items_can_be_deactivated_and_reactivated(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($product->user)->put(route('products.update', $product), $this->validPayload(['is_active' => '0']));
        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($product->user)->get(route('products.index'))
            ->assertSee('Inactive');

        $this->actingAs($product->user)->put(route('products.update', $product), $this->validPayload(['is_active' => '1']));
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_new_items_are_active_by_default_in_the_database(): void
    {
        $product = User::factory()->create()->products()->create([
            'type' => ProductType::Service,
            'name' => 'Monthly Maintenance',
            'selling_price' => '300',
        ]);

        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_user_supplied_content_is_escaped(): void
    {
        $product = Product::factory()->create([
            'name' => '<b>Bold Item</b>',
            'description' => "<script>alert('xss')</script>\nSecond line",
            'unit' => '<i>hr</i>',
        ]);

        $response = $this->actingAs($product->user)->get(route('products.show', $product));

        $response->assertDontSee("<script>alert('xss')</script>", false);
        $response->assertDontSee('<b>Bold Item</b>', false);
        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('<br />', false);

        $this->actingAs($product->user)->get(route('products.index'))
            ->assertDontSee('<b>Bold Item</b>', false)
            ->assertDontSee('<i>hr</i>', false);
    }

    public function test_delete_confirmation_page_does_not_delete(): void
    {
        $product = Product::factory()->create(['name' => 'Still Here']);

        $response = $this->actingAs($product->user)->get(route('products.delete', $product));

        $response->assertOk();
        $response->assertSee('Still Here');
        $response->assertSee('mark it inactive');
        $response->assertSee('name="_method" value="DELETE"', false);
        $response->assertSee('name="_token"', false);
        $this->assertModelExists($product);
    }

    public function test_users_can_delete_their_item(): void
    {
        $product = Product::factory()->service()->create();

        $response = $this->actingAs($product->user)->delete(route('products.destroy', $product));

        $response->assertRedirect(route('products.index'));
        $response->assertSessionHas('status', 'Service deleted.');
        $this->assertModelMissing($product);
    }

    public function test_deleting_a_user_deletes_their_items(): void
    {
        $product = Product::factory()->create();
        $untouched = Product::factory()->create();

        $product->user->delete();

        $this->assertModelMissing($product);
        $this->assertModelExists($untouched);
    }
}
