<?php

namespace Tests\Feature\Auth;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aisha Rahman',
            'business_name' => 'Rahman Trading',
            'email' => 'aisha@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered_with_csrf_token(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertSee('Create your account');
        $response->assertSee('name="_token"', false);
        $response->assertSee('name="business_name"', false);
    }

    public function test_new_users_can_register_and_are_logged_in(): void
    {
        $response = $this->post(route('register'), $this->validPayload());

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'name' => 'Aisha Rahman',
            'email' => 'aisha@example.com',
        ]);
    }

    public function test_session_id_is_regenerated_after_registration(): void
    {
        $this->get(route('register'));
        $guestSessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $guestSessionId);

        // Control: a normal request carrying the session cookie keeps the same session.
        $this->get(route('register'));
        $this->assertSame($guestSessionId, session()->getId());

        $this->post(route('register'), $this->validPayload());

        $this->assertAuthenticated();
        $this->assertNotSame($guestSessionId, session()->getId());
    }

    public function test_password_is_stored_hashed(): void
    {
        $this->post(route('register'), $this->validPayload());

        $user = User::where('email', 'aisha@example.com')->firstOrFail();

        $this->assertNotSame('secret-pass-123', $user->password);
        $this->assertTrue(Hash::check('secret-pass-123', $user->password));
    }

    public function test_email_is_normalized_to_lowercase(): void
    {
        $this->post(route('register'), $this->validPayload(['email' => '  Aisha@Example.COM ']));

        $this->assertDatabaseHas('users', ['email' => 'aisha@example.com']);
    }

    public function test_required_fields_are_validated(): void
    {
        $response = $this->from(route('register'))->post(route('register'), []);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors(['name', 'business_name', 'email', 'password']);
        $this->assertGuest();
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->post(route('register'), $this->validPayload(['email' => 'not-an-email']));

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_email_must_be_unique_regardless_of_case(): void
    {
        User::factory()->create(['email' => 'aisha@example.com']);

        $response = $this->post(route('register'), $this->validPayload(['email' => 'AISHA@example.com']));

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_password_must_be_confirmed(): void
    {
        $response = $this->post(route('register'), $this->validPayload(['password_confirmation' => 'different-pass']));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_password_must_be_at_least_eight_characters(): void
    {
        $response = $this->post(route('register'), $this->validPayload([
            'password' => 'short7!',
            'password_confirmation' => 'short7!',
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_password_may_not_exceed_seventy_two_characters(): void
    {
        $tooLong = str_repeat('a', 73);

        $response = $this->post(route('register'), $this->validPayload([
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ]));

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_password_is_not_flashed_back_to_the_form(): void
    {
        $response = $this->post(route('register'), $this->validPayload(['email' => 'not-an-email']));

        $response->assertSessionHasInput('name', 'Aisha Rahman');
        $response->assertSessionMissing('_old_input.password');
        $response->assertSessionMissing('_old_input.password_confirmation');
    }

    public function test_registration_is_throttled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post(route('register'), []);
        }

        $this->post(route('register'), [])->assertTooManyRequests();
    }

    public function test_registration_creates_the_business_with_the_user_as_its_owner(): void
    {
        $this->post(route('register'), $this->validPayload(['business_name' => '  Rahman Trading Sdn Bhd  ']));

        $user = User::sole();
        $business = Business::sole();
        $this->assertSame('Rahman Trading Sdn Bhd', $business->name);
        $this->assertTrue($business->hasMember($user, BusinessRole::Owner));
        $this->assertSame(1, $user->businesses()->count());
        $this->assertNull($business->registration_number);

        // The new account works straight away, in its own business.
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('business.profile.edit'))->assertOk()->assertSee('Rahman Trading Sdn Bhd');
    }

    public function test_business_name_is_validated(): void
    {
        $this->from(route('register'))->post(route('register'), $this->validPayload(['business_name' => '   ']))
            ->assertSessionHasErrors(['business_name' => 'The business name field is required.']);
        $this->from(route('register'))->post(route('register'), $this->validPayload(['business_name' => str_repeat('a', 256)]))
            ->assertSessionHasErrors('business_name');

        $this->assertSame(0, User::count());
        $this->assertSame(0, Business::count());
        $this->assertGuest();
    }

    public function test_a_failure_creating_the_business_keeps_nothing_and_logs_nobody_in(): void
    {
        Business::creating(fn () => throw new RuntimeException('Simulated failure'));

        $response = $this->post(route('register'), $this->validPayload());

        $response->assertServerError();
        $this->assertSame(0, User::count());
        $this->assertSame(0, Business::count());
        $this->assertSame(0, DB::table('business_user')->count());
        $this->assertGuest();
    }

    public function test_a_failure_creating_the_membership_keeps_nothing(): void
    {
        BusinessMembership::creating(fn () => throw new RuntimeException('Simulated failure'));

        $this->post(route('register'), $this->validPayload())->assertServerError();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Business::count());
        $this->assertGuest();
    }

    public function test_forged_business_fields_are_ignored(): void
    {
        $existing = Business::factory()->create();

        $this->post(route('register'), $this->validPayload(['business_id' => $existing->id, 'role' => 'admin']));

        $user = User::where('email', 'aisha@example.com')->sole();
        $this->assertFalse($existing->hasMember($user));
        $this->assertSame(BusinessRole::Owner, $user->businesses()->sole()->pivot->role);
    }

    public function test_authenticated_users_cannot_view_registration_screen(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('register'));

        $response->assertRedirect(route('dashboard'));
    }
}
