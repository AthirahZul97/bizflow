<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Acme Studio Sdn Bhd',
            'registration_number' => '202001234567 (1234567-A)',
            'sst_number' => 'W10-1808-32000012',
            'email' => 'Billing@Acme.test',
            'phone' => '+60 3-1234 5678',
            'address_line_1' => '12 Jalan Bukit',
            'address_line_2' => 'Taman Melawati',
            'city' => 'Kuala Lumpur',
            'state' => 'Wilayah Persekutuan',
            'postcode' => '53100',
            'country' => 'Malaysia',
        ], $overrides);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('business.profile.edit'))->assertRedirect(route('login'));
        $this->put(route('business.profile.update'), $this->validPayload())->assertRedirect(route('login'));
    }

    public function test_the_owner_sees_their_business_profile(): void
    {
        $user = User::factory()->create(['name' => 'Aisha Rahman']);
        $this->businessOf($user)->update(['sst_number' => 'W10-0000-00000001']);

        $this->actingAs($user)->get(route('business.profile.edit'))
            ->assertOk()
            ->assertSee('Business profile')
            ->assertSee('value="Aisha Rahman"', false)
            ->assertSee('value="W10-0000-00000001"', false);
    }

    public function test_the_navigation_links_to_the_profile(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertSee(route('business.profile.edit'));
    }

    public function test_the_owner_can_update_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('business.profile.update'), $this->validPayload())
            ->assertRedirect(route('business.profile.edit'))
            ->assertSessionHas('status', 'Business profile updated.');

        $business = $this->businessOf($user);
        $this->assertSame('Acme Studio Sdn Bhd', $business->name);
        $this->assertSame('billing@acme.test', $business->email);
        $this->assertSame(['12 Jalan Bukit', 'Taman Melawati', '53100 Kuala Lumpur, Wilayah Persekutuan', 'Malaysia'], $business->addressLines());
    }

    public function test_optional_fields_can_be_cleared(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update(['sst_number' => 'OLD', 'city' => 'Old City']);

        // The form always submits every field; empty ones arrive as null.
        $empty = array_fill_keys(array_keys($this->validPayload()), '');
        $this->actingAs($user)->put(route('business.profile.update'), ['name' => 'Only A Name'] + $empty)->assertSessionHasNoErrors();

        $business = $this->businessOf($user);
        $this->assertSame('Only A Name', $business->name);
        $this->assertNull($business->sst_number);
        $this->assertNull($business->city);
    }

    public function test_input_is_validated(): void
    {
        $user = User::factory()->create();

        foreach ([
            'name' => ['name' => ''],
            'email' => ['email' => 'not-an-email'],
            'phone' => ['phone' => 'call me'],
            'registration_number' => ['registration_number' => str_repeat('1', 51)],
            'sst_number' => ['sst_number' => str_repeat('1', 51)],
            'postcode' => ['postcode' => str_repeat('1', 21)],
        ] as $field => $override) {
            $this->actingAs($user)->from(route('business.profile.edit'))
                ->put(route('business.profile.update'), $this->validPayload($override))
                ->assertRedirect(route('business.profile.edit'))
                ->assertSessionHasErrors($field);
        }

        $this->assertSame($user->name, $this->businessOf($user)->name);
    }

    public function test_only_the_current_business_is_ever_updated(): void
    {
        $user = User::factory()->create();
        $other = Business::factory()->create(['name' => 'Other Business']);

        $this->actingAs($user)->put(route('business.profile.update'), $this->validPayload([
            'id' => $other->id,
            'business_id' => $other->id,
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Acme Studio Sdn Bhd', $this->businessOf($user)->name);
        $this->assertSame('Other Business', $other->fresh()->name);
        $this->assertSame($other->id, $other->fresh()->id);
    }

    public function test_profile_values_are_escaped(): void
    {
        $user = User::factory()->create();
        $this->businessOf($user)->update(['name' => '<script>alert(1)</script>']);

        $this->actingAs($user)->get(route('business.profile.edit'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
