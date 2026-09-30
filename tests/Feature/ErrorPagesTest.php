<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_404_for_another_users_record_uses_the_app_layout_without_record_details(): void
    {
        $customer = Customer::factory()->create(['name' => 'Secret Customer Sdn Bhd']);

        $response = $this->actingAs(User::factory()->create())->get(route('customers.show', $customer));

        $response->assertNotFound()
            ->assertSee('data-error-page="404"', false)
            ->assertSee('Page not found')
            ->assertSee('navbar', false)
            ->assertSee('Go to dashboard')
            ->assertDontSee('Secret Customer Sdn Bhd')
            ->assertDontSee('App\Models')
            ->assertDontSee('No query results');
    }

    public function test_404_for_an_unknown_url_is_guest_safe(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('data-error-page="404"', false)
            ->assertSee('Go to the home page')
            ->assertSee('Log in');
    }

    public function test_403_shows_the_policy_reason_in_the_app_layout(): void
    {
        $invoice = Invoice::factory()->issued()->create();

        $this->actingAs($this->ownerOf($invoice))->get(route('invoices.edit', $invoice))
            ->assertForbidden()
            ->assertSee('data-error-page="403"', false)
            ->assertSee('Only draft invoices can be edited.')
            ->assertSee('navbar', false)
            ->assertSee('Go to dashboard');
    }

    public function test_419_explains_that_the_page_expired(): void
    {
        Route::middleware('web')->get('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));

        $this->get('/_test/expired')
            ->assertStatus(419)
            ->assertSee('data-error-page="419"', false)
            ->assertSee('This page has expired')
            ->assertSee('refresh the page and try again');
    }

    public function test_500_is_generic_and_leaks_no_internal_details(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException(
            'SQLSTATE[42S02] select * from secret_table at '.base_path('app/Secret.php')
        ));

        $response = $this->get('/_test/boom');

        $response->assertStatus(500)
            ->assertSee('data-error-page="500"', false)
            ->assertSee('Something went wrong')
            ->assertSee('Go to the home page')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('secret_table')
            ->assertDontSee('RuntimeException')
            ->assertDontSee(base_path(), false)
            ->assertDontSee('Stack trace');
    }

    public function test_500_page_renders_without_a_session_or_signed_in_user(): void
    {
        $html = view('errors.500')->render();

        $this->assertStringContainsString('data-error-page="500"', $html);
        $this->assertStringNotContainsString('csrf-token', $html);
        $this->assertStringNotContainsString('Log out', $html);
    }
}
