<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function customerRoutes(): array
    {
        return [
            'index' => ['get', 'customers.index', false],
            'create' => ['get', 'customers.create', false],
            'store' => ['post', 'customers.store', false],
            'show' => ['get', 'customers.show', true],
            'edit' => ['get', 'customers.edit', true],
            'update' => ['put', 'customers.update', true],
            'delete confirmation' => ['get', 'customers.delete', true],
            'destroy' => ['delete', 'customers.destroy', true],
        ];
    }

    #[DataProvider('customerRoutes')]
    public function test_guests_are_redirected_to_login(string $method, string $route, bool $needsCustomer): void
    {
        $customer = Customer::factory()->create();

        $url = $needsCustomer ? route($route, $customer) : route($route);

        $this->{$method}($url)->assertRedirect(route('login'));
        $this->assertModelExists($customer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function customerPages(): array
    {
        return [
            'show' => ['customers.show'],
            'edit' => ['customers.edit'],
            'delete confirmation' => ['customers.delete'],
        ];
    }

    #[DataProvider('customerPages')]
    public function test_users_cannot_view_pages_for_another_users_customer(string $route): void
    {
        $intruder = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Secret Holdings']);

        $response = $this->actingAs($intruder)->get(route($route, $customer));

        $response->assertNotFound();
        $response->assertDontSee('Secret Holdings');
    }

    #[DataProvider('customerPages')]
    public function test_owners_can_view_pages_for_their_own_customer(string $route): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($customer->user)->get(route($route, $customer))->assertOk();
    }

    public function test_users_cannot_update_another_users_customer(): void
    {
        $intruder = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Original Name']);

        $response = $this->actingAs($intruder)->put(route('customers.update', $customer), [
            'name' => 'Hijacked',
        ]);

        $response->assertNotFound();
        $this->assertSame('Original Name', $customer->fresh()->name);
    }

    public function test_authorization_runs_before_validation_on_update(): void
    {
        $intruder = User::factory()->create();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($intruder)->put(route('customers.update', $customer), [
            'name' => '',
            'email' => 'not-an-email',
        ]);

        $response->assertNotFound();
        $response->assertSessionHasNoErrors();
    }

    public function test_users_cannot_delete_another_users_customer(): void
    {
        $intruder = User::factory()->create();
        $customer = Customer::factory()->create();

        $this->actingAs($intruder)->delete(route('customers.destroy', $customer))->assertNotFound();

        $this->assertModelExists($customer);
    }

    public function test_missing_customer_returns_not_found(): void
    {
        $this->actingAs(User::factory()->create())->get('/customers/999999')->assertNotFound();
    }
}
