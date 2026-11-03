<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * When the queue is down the account emails cannot be queued. That must neither undo a registration
 * that succeeded nor turn "forgot password" into an error that only a real account would produce.
 */
class AccountEmailQueueFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A database queue whose table does not exist: pushing a job throws, as when the database is down.
        config([
            'queue.default' => 'broken',
            'queue.connections.broken' => ['driver' => 'database', 'table' => 'no_such_jobs_table', 'queue' => 'default', 'retry_after' => 90],
        ]);

        Exceptions::fake();
    }

    public function test_registration_succeeds_when_the_verification_email_cannot_be_queued(): void
    {
        $this->post(route('register'), [
            'name' => 'Aisha Rahman', 'business_name' => 'Rahman Trading', 'email' => 'aisha@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $this->assertAuthenticated();
        $user = User::where('email', 'aisha@example.com')->sole();
        $this->assertSame(1, $user->businesses()->count(), 'the business and membership exist too');
        Exceptions::assertReported(fn (QueryException $e) => true);
    }

    public function test_forgot_password_answers_the_same_for_known_and_unknown_addresses_when_the_queue_is_down(): void
    {
        User::factory()->create(['email' => 'aisha@example.com']);

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'aisha@example.com']);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $known->assertSessionHas('status')->assertSessionHasNoErrors();
        $unknown->assertSessionHas('status')->assertSessionHasNoErrors();
        $this->assertSame(session('status'), 'If an account exists for that email address, a password reset link is on its way.');

        // The outage is reported for the operator; the visitor learns nothing from it.
        Exceptions::assertReported(fn (QueryException $e) => true);
    }

    public function test_resending_the_verification_link_says_so_when_it_cannot_be_queued(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('error')
            ->assertSessionMissing('status');

        Exceptions::assertReported(fn (QueryException $e) => true);
    }
}
