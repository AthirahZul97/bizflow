<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function link(User $user, ?string $email = null): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->getKey(),
            'hash' => sha1($email ?? $user->getEmailForVerification()),
        ]);
    }

    public function test_the_user_model_is_ready_for_verification_and_the_email_is_queued(): void
    {
        $this->assertInstanceOf(MustVerifyEmail::class, new User);
        $this->assertInstanceOf(ShouldQueue::class, new QueuedVerifyEmail);
    }

    public function test_registration_sends_a_verification_email(): void
    {
        Notification::fake();

        $this->post(route('register'), [
            'name' => 'Aisha Rahman', 'business_name' => 'Rahman Trading', 'email' => 'aisha@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'aisha@example.com')->sole();
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_registration_does_not_wait_for_or_break_on_the_mail_server(): void
    {
        // Unlike sending inline, queueing cannot fail the request that asked for it.
        Queue::fake();

        $this->post(route('register'), [
            'name' => 'Aisha Rahman', 'business_name' => 'Rahman Trading', 'email' => 'aisha@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('dashboard'));

        Queue::assertPushed(SendQueuedNotifications::class);
        $this->assertAuthenticated();
    }

    public function test_the_notice_page_is_for_unverified_accounts_only(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'))
            ->assertOk()->assertSee('Verify your email address');

        $this->actingAs(User::factory()->create())->get(route('verification.notice'))->assertRedirect(route('dashboard'));
    }

    public function test_a_valid_signed_link_verifies_the_signed_in_user(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->link($user))->assertRedirect(route('dashboard'))->assertSessionHas('status');

        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_a_link_for_someone_else_does_not_verify_anyone(): void
    {
        $victim = User::factory()->unverified()->create();
        $attacker = User::factory()->unverified()->create();

        $this->actingAs($attacker)->get($this->link($victim))->assertForbidden();

        $this->assertNull($victim->fresh()->email_verified_at);
        $this->assertNull($attacker->fresh()->email_verified_at);
    }

    public function test_a_link_with_the_wrong_hash_or_without_a_signature_is_refused(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->link($user, 'other@example.com'))->assertForbidden();
        $this->actingAs($user)->get(route('verification.verify', ['id' => $user->getKey(), 'hash' => sha1($user->email)]))->assertForbidden();
        $this->actingAs($user)->get($this->link($user).'&tampered=1')->assertForbidden();

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $link = $this->link($user);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($link)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_link_can_be_requested_again_and_is_rate_limited(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from(route('verification.notice'))->post(route('verification.send'))
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status');
        Notification::assertSentTo($user, QueuedVerifyEmail::class);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post(route('verification.send'));
        }

        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
    }

    public function test_a_verified_user_asking_again_gets_nothing_sent(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('dashboard'));

        Notification::assertNothingSent();
    }

    public function test_guests_cannot_use_the_verification_routes(): void
    {
        $user = User::factory()->unverified()->create();

        $this->get(route('verification.notice'))->assertRedirect(route('login'));
        $this->post(route('verification.send'))->assertRedirect(route('login'));
        $this->get($this->link($user))->assertRedirect(route('login'));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_unverified_account_can_still_use_the_application_when_verification_is_not_required(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get(route('dashboard'))->assertOk();
    }
}
