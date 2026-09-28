<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        $response->assertSessionHasErrors(['name', 'email', 'password']);
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

    public function test_authenticated_users_cannot_view_registration_screen(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('register'));

        $response->assertRedirect(route('dashboard'));
    }
}
