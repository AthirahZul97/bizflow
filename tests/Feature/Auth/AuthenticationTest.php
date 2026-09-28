<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered_with_csrf_token(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Log in to');
        $response->assertSee('name="_token"', false);
    }

    public function test_users_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_session_id_is_regenerated_after_login(): void
    {
        $user = User::factory()->create();

        $this->get(route('login'));
        $guestSessionId = session()->getId();
        $this->withCookie(config('session.cookie'), $guestSessionId);

        // Control: a normal request carrying the session cookie keeps the same session.
        $this->get(route('login'));
        $this->assertSame($guestSessionId, session()->getId());

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($guestSessionId, session()->getId());
    }

    public function test_login_email_is_case_insensitive(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->post(route('login'), [
            'email' => 'Owner@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_and_unknown_email_return_the_same_generic_error(): void
    {
        $user = User::factory()->create();

        $wrongPassword = $this->from(route('login'))->post(route('login'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $unknownEmail = $this->from(route('login'))->post(route('login'), [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $wrongPassword->assertRedirect(route('login'));
        $wrongPassword->assertSessionHasErrors(['email' => __('auth.failed')]);
        $unknownEmail->assertSessionHasErrors(['email' => __('auth.failed')]);
        $this->assertGuest();
    }

    public function test_login_fields_are_required(): void
    {
        $response = $this->post(route('login'), []);

        $response->assertSessionHasErrors(['email', 'password']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // Even the correct password is rejected while locked out.
        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_remember_me_sets_the_recaller_cookie(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_are_redirected_to_the_intended_page_after_login(): void
    {
        Route::middleware(['web', 'auth'])->get('/_test/protected', fn () => 'protected');

        $user = User::factory()->create();

        $this->get('/_test/protected')->assertRedirect(route('login'));

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/_test/protected');
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect('/');
        $response->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_logout_cannot_be_triggered_with_get(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertMethodNotAllowed();
        $this->assertAuthenticatedAs($user);
    }

    public function test_authenticated_users_cannot_view_login_screen(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('login'));

        $response->assertRedirect(route('dashboard'));
    }
}
