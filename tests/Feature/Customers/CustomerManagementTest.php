<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string|null>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nur Aina',
            'company_name' => 'Aina Trading Sdn Bhd',
            'email' => 'aina@example.com',
            'phone' => '+60 12-345 6789',
            'address_line_1' => '12 Jalan Mawar',
            'address_line_2' => 'Taman Melati',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan',
            'postcode' => '53100',
            'country' => 'Malaysia',
            'notes' => 'Prefers email contact.',
        ], $overrides);
    }

    public function test_index_lists_only_the_users_own_customers(): void
    {
        $user = User::factory()->create();
        Customer::factory()->for($user)->create(['name' => 'My Customer']);
        Customer::factory()->create(['name' => 'Someone Elses Customer']);

        $response = $this->actingAs($user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('My Customer');
        $response->assertDontSee('Someone Elses Customer');
    }

    public function test_create_form_can_be_rendered_with_csrf_token(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('customers.create'));

        $response->assertOk();
        $response->assertSee('name="_token"', false);
    }

    public function test_users_can_create_a_customer(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('customers.store'), $this->validPayload());

        $customer = Customer::sole();
        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status', 'Customer created.');
        $this->assertTrue($customer->user->is($user));
        $this->assertDatabaseHas('customers', ['user_id' => $user->id] + $this->validPayload());
    }

    public function test_forged_user_id_is_ignored_on_create(): void
    {
        $user = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($user)->post(route('customers.store'), $this->validPayload(['user_id' => $victim->id]));

        $this->assertSame($user->id, Customer::sole()->user_id);
        $this->assertSame(0, $victim->customers()->count());
    }

    public function test_non_fillable_attributes_are_ignored_on_create(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('customers.store'), $this->validPayload([
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
        ]));

        $customer = Customer::sole();
        $this->assertNotSame(999, $customer->id);
        $this->assertNotSame('2000', $customer->created_at->format('Y'));
    }

    public function test_optional_fields_may_be_blank_and_are_stored_as_null(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('customers.store'), [
            'name' => 'Walk-in Customer',
            'company_name' => '',
            'email' => '',
            'phone' => '',
            'notes' => '',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'name' => 'Walk-in Customer',
            'company_name' => null,
            'email' => null,
            'phone' => null,
            'notes' => null,
        ]);
    }

    public function test_email_is_stored_in_lowercase(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('customers.store'), $this->validPayload(['email' => 'Aina@Example.COM']));

        $this->assertSame('aina@example.com', Customer::sole()->email);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidInput(): array
    {
        return [
            'missing name' => ['name', ''],
            'name too long' => ['name', str_repeat('a', 256)],
            'company too long' => ['company_name', str_repeat('a', 256)],
            'invalid email' => ['email', 'not-an-email'],
            'phone with letters' => ['phone', '012-ABC-4567'],
            'phone too long' => ['phone', str_repeat('1', 31)],
            'city too long' => ['city', str_repeat('a', 101)],
            'postcode too long' => ['postcode', str_repeat('1', 21)],
            'country too long' => ['country', str_repeat('a', 101)],
            'notes too long' => ['notes', str_repeat('a', 5001)],
            'array instead of string' => ['name', ['nested']],
        ];
    }

    #[DataProvider('invalidInput')]
    public function test_invalid_input_is_rejected(string $field, mixed $value): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->from(route('customers.create'))
            ->post(route('customers.store'), $this->validPayload([$field => $value]));

        $response->assertRedirect(route('customers.create'));
        $response->assertSessionHasErrors($field);
        $this->assertSame(0, Customer::count());
    }

    public function test_users_can_update_their_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($customer->user)->put(
            route('customers.update', $customer),
            $this->validPayload(['name' => 'New Name', 'city' => 'Penang']),
        );

        $response->assertRedirect(route('customers.show', $customer));
        $response->assertSessionHas('status', 'Customer updated.');
        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('Penang', $customer->city);
    }

    public function test_update_cannot_change_the_owner(): void
    {
        $customer = Customer::factory()->create();
        $owner = $customer->user;
        $other = User::factory()->create();

        $this->actingAs($owner)->put(
            route('customers.update', $customer),
            $this->validPayload(['user_id' => $other->id]),
        );

        $this->assertSame($owner->id, $customer->fresh()->user_id);
    }

    public function test_update_validates_input(): void
    {
        $customer = Customer::factory()->create(['name' => 'Keep Me']);

        $response = $this->actingAs($customer->user)
            ->from(route('customers.edit', $customer))
            ->put(route('customers.update', $customer), ['name' => '']);

        $response->assertRedirect(route('customers.edit', $customer));
        $response->assertSessionHasErrors('name');
        $this->assertSame('Keep Me', $customer->fresh()->name);
    }

    public function test_detail_page_shows_the_customer(): void
    {
        $user = User::factory()->create();
        $customer = $user->customers()->create($this->validPayload());

        $response = $this->actingAs($user)->get(route('customers.show', $customer));

        $response->assertOk();
        $response->assertSee('Nur Aina');
        $response->assertSee('Aina Trading Sdn Bhd');
        $response->assertSee('12 Jalan Mawar');
        $response->assertSee('53100 Kuala Lumpur');
    }

    public function test_user_supplied_content_is_escaped(): void
    {
        $customer = Customer::factory()->create([
            'name' => '<b>Bold Co</b>',
            'notes' => "<script>alert('xss')</script>\nSecond line",
        ]);

        $response = $this->actingAs($customer->user)->get(route('customers.show', $customer));

        $response->assertDontSee("<script>alert('xss')</script>", false);
        $response->assertDontSee('<b>Bold Co</b>', false);
        $response->assertSee('&lt;script&gt;', false);
        $response->assertSee('<br />', false);

        $this->actingAs($customer->user)->get(route('customers.index'))
            ->assertDontSee('<b>Bold Co</b>', false);
    }

    public function test_delete_confirmation_page_does_not_delete(): void
    {
        $customer = Customer::factory()->create(['name' => 'Still Here']);

        $response = $this->actingAs($customer->user)->get(route('customers.delete', $customer));

        $response->assertOk();
        $response->assertSee('Still Here');
        $response->assertSee('name="_method" value="DELETE"', false);
        $this->assertModelExists($customer);
    }

    public function test_users_can_delete_their_customer(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->actingAs($customer->user)->delete(route('customers.destroy', $customer));

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('status', 'Customer deleted.');
        $this->assertModelMissing($customer);
    }

    public function test_deleting_a_user_deletes_their_customers(): void
    {
        $customer = Customer::factory()->create();
        $untouched = Customer::factory()->create();

        $customer->user->delete();

        $this->assertModelMissing($customer);
        $this->assertModelExists($untouched);
    }
}
