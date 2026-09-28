<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_see_their_own_dashboard(): void
    {
        $user = User::factory()->create(['name' => 'Aisha Rahman']);
        User::factory()->create(['name' => 'Other Owner']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Welcome, <strong>Aisha Rahman</strong>', false);
        $response->assertDontSee('Other Owner');
    }

    public function test_navbar_shows_login_and_register_links_to_guests(): void
    {
        $response = $this->get('/');

        $response->assertSee(route('login'));
        $response->assertSee(route('register'));
        $response->assertDontSee(route('logout'));
    }

    public function test_navbar_shows_logout_form_to_authenticated_users(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/');

        $response->assertSee(route('logout'));
        $response->assertSee(route('dashboard'));
        $response->assertDontSee('href="'.route('register').'"', false);
    }
}
