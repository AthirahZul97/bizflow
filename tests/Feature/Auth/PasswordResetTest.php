<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'If an account exists for that email address, a password reset link is on its way.';

    public function test_the_forgot_password_form_renders(): void
    {
        $this->get(route('password.request'))->assertOk()->assertSee('Forgot your password?')->assertSee('name="_token"', false);
    }

    public function test_a_known_address_gets_a_reset_link_and_the_generic_answer(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'aisha@example.com']);

        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'Aisha@Example.com '])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', self::GENERIC);

        Notification::assertSentTo($user, QueuedResetPassword::class);
    }

    public function test_an_unknown_address_gets_exactly_the_same_answer_and_nothing_is_sent(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'aisha@example.com']);

        $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'aisha@example.com']);
        $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody@example.com']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $unknown->assertSessionHas('status', self::GENERIC)->assertSessionHasNoErrors();
        Notification::assertSentTimes(QueuedResetPassword::class, 1);
    }

    public function test_asking_again_straight_away_does_not_reveal_that_the_account_exists(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'aisha@example.com']);

        $first = $this->post(route('password.email'), ['email' => 'aisha@example.com']);
        $second = $this->post(route('password.email'), ['email' => 'aisha@example.com']);

        $second->assertSessionHas('status', self::GENERIC)->assertSessionHasNoErrors();
        $this->assertSame($first->getStatusCode(), $second->getStatusCode());
        Notification::assertSentToTimes($user, QueuedResetPassword::class, 1);
    }

    public function test_a_malformed_address_is_a_validation_error_not_an_account_check(): void
    {
        Notification::fake();

        $this->post(route('password.email'), ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->post(route('password.email'), [])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_the_request_form_is_rate_limited(): void
    {
        Notification::fake();

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('password.email'), ['email' => "p{$i}@example.com"])->assertSessionHas('status');
        }

        $this->post(route('password.email'), ['email' => 'p7@example.com'])->assertStatus(429);
    }

    public function test_the_reset_email_is_queued_so_the_request_does_not_wait_for_the_mail_server(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new QueuedResetPassword('token'));

        Queue::fake();
        $user = User::factory()->create(['email' => 'aisha@example.com']);

        $this->post(route('password.email'), ['email' => 'aisha@example.com']);
        $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        Queue::assertPushed(SendQueuedNotifications::class, 1);
        $this->assertNotNull($user);
    }

    public function test_the_link_in_the_email_points_at_the_reset_form(): void
    {
        $user = User::factory()->create();
        $mail = (new QueuedResetPassword('the-token'))->toMail($user);

        $this->assertStringContainsString('/reset-password/the-token', $mail->actionUrl);
        $this->assertStringContainsString('email='.urlencode($user->email), $mail->actionUrl);
    }

    // ---- resetting ------------------------------------------------------------------------------

    private function reset(User $user, string $token, array $overrides = []): TestResponse
    {
        return $this->post(route('password.store'), array_merge([
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
        ], $overrides));
    }

    public function test_the_reset_form_renders_with_the_token(): void
    {
        $this->get(route('password.reset', ['token' => 'abc123', 'email' => 'aisha@example.com']))
            ->assertOk()->assertSee('value="abc123"', false)->assertSee('aisha@example.com');
    }

    public function test_a_valid_token_sets_the_new_password_and_ends_remember_me_sessions(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['password' => 'old-password-1', 'remember_token' => 'old-remember-token']);
        $token = Password::createToken($user);

        $this->reset($user, $token)->assertRedirect(route('login'))->assertSessionHas('status');

        $user->refresh();
        $this->assertTrue(Hash::check('brand-new-pass-1', $user->password));
        $this->assertFalse(Hash::check('old-password-1', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        Event::assertDispatched(PasswordReset::class);
        $this->assertGuest();
    }

    public function test_the_new_password_works_for_logging_in(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $this->reset($user, Password::createToken($user));

        $this->post(route('login'), ['email' => $user->email, 'password' => 'brand-new-pass-1'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_token_works_once_only(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->reset($user, $token)->assertRedirect(route('login'));
        $this->reset($user, $token, ['password' => 'another-pass-123', 'password_confirmation' => 'another-pass-123'])
            ->assertSessionHasErrors(['email' => 'This password reset link is invalid or has expired. Request a new one.']);

        $this->assertTrue(Hash::check('brand-new-pass-1', $user->fresh()->password));
    }

    public function test_a_refused_link_sends_the_browser_back_to_the_form_and_a_good_one_to_login(): void
    {
        // The MySQL concurrency test (tests/Mysql/PasswordResetMysqlTest) classifies each submission by
        // exactly this: /login when the password was reset, the reset form when the link was refused.
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $form = url('/reset-password/'.$token);
        $payload = ['token' => $token, 'email' => $user->email, 'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'];

        $this->withHeader('Referer', $form)->post(route('password.store'), $payload)->assertRedirect(route('login'));
        $this->withHeader('Referer', $form)->post(route('password.store'), $payload)->assertRedirect($form);
    }

    public function test_an_expired_token_is_refused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->reset($user, $token)->assertSessionHasErrors('email');
        $this->assertFalse(Hash::check('brand-new-pass-1', $user->fresh()->password));
    }

    public function test_every_way_a_link_can_be_wrong_gets_the_same_message(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $valid = Password::createToken($user);
        $message = 'This password reset link is invalid or has expired. Request a new one.';

        $this->reset($user, 'wrong-token')->assertSessionHasErrors(['email' => $message]);
        $this->reset($user, $valid, ['email' => 'nobody@example.com'])->assertSessionHasErrors(['email' => $message]);
        $this->reset($user, $valid, ['email' => $other->email])->assertSessionHasErrors(['email' => $message]);

        $this->assertFalse(Hash::check('brand-new-pass-1', $user->fresh()->password));
        $this->assertFalse(Hash::check('brand-new-pass-1', $other->fresh()->password));
    }

    public function test_the_new_password_must_meet_the_registration_rules(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        $token = Password::createToken($user);

        $this->reset($user, $token, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->reset($user, $token, ['password_confirmation' => 'different-pass-1'])->assertSessionHasErrors('password');
        $long = str_repeat('a', 73);
        $this->reset($user, $token, ['password' => $long, 'password_confirmation' => $long])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password));
        $this->reset($user, $token)->assertRedirect(route('login'));
    }

    public function test_the_reset_submission_is_rate_limited(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->reset($user, 'guess-'.$i)->assertSessionHasErrors('email');
        }

        $this->reset($user, 'guess-7')->assertStatus(429);
    }

    public function test_a_signed_in_user_is_sent_away_from_the_recovery_pages(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('password.request'))->assertRedirect();
        $this->get(route('password.reset', 'token'))->assertRedirect();
    }
}
