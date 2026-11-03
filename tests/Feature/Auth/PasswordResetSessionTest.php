<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Resetting a password signs out every other session of that account, whichever session driver
 * holds them. The mechanism is Laravel's AuthenticateSession: each session remembers a keyed hash of
 * the password it logged in with, and the next request from a session whose hash no longer matches is
 * logged out. Nothing is looked up or deleted per driver, so it works for all of them.
 */
class PasswordResetSessionTest extends TestCase
{
    use RefreshDatabase;

    private string $sessionDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessionDirectory = storage_path('framework/testing/sessions-'.bin2hex(random_bytes(4)));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sessionDirectory);

        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return ['array' => ['array'], 'file' => ['file'], 'database' => ['database']];
    }

    private function useDriver(string $driver): void
    {
        File::ensureDirectoryExists($this->sessionDirectory);
        config(['session.driver' => $driver, 'session.files' => $this->sessionDirectory]);
    }

    private function forgetClient(): void
    {
        // withCookie() is sticky, so a "new browser" must drop the old one's cookies as well.
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /**
     * A brand new browser session: log in and return the session id (and the response).
     *
     * @return array{string, TestResponse}
     */
    private function logIn(string $email, string $password, bool $remember = false): array
    {
        $this->forgetClient();

        $response = $this->post(route('login'), ['email' => $email, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticated();

        return [session()->getId(), $response];
    }

    /**
     * Whether a request carrying that session's cookie is still signed in.
     */
    private function isSignedIn(string $sessionId): bool
    {
        $this->forgetClient();

        $response = $this->withCookie(config('session.cookie'), $sessionId)->get(route('dashboard'));

        return $response->getStatusCode() === 200;
    }

    private function resetPassword(User $user, string $token, string $password = 'brand-new-pass-1'): TestResponse
    {
        $this->forgetClient();

        return $this->post(route('password.store'), [
            'token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    #[DataProvider('drivers')]
    public function test_a_reset_signs_out_every_other_session(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);

        [$laptop] = $this->logIn($user->email, 'old-password-1');
        [$phone] = $this->logIn($user->email, 'old-password-1');
        $this->assertNotSame($laptop, $phone, 'two independent sessions');
        $this->assertTrue($this->isSignedIn($laptop), 'control: the laptop is signed in');
        $this->assertTrue($this->isSignedIn($phone), 'control: so is the phone');

        $this->resetPassword($user, Password::createToken($user))->assertRedirect(route('login'));

        $this->assertFalse($this->isSignedIn($laptop), 'the laptop session is revoked');
        $this->assertFalse($this->isSignedIn($phone), 'the phone session is revoked');

        [$fresh] = $this->logIn($user->email, 'brand-new-pass-1');
        $this->assertTrue($this->isSignedIn($fresh), 'signing in with the new password works');
    }

    #[DataProvider('drivers')]
    public function test_a_revoked_session_is_redirected_to_login_and_cannot_write(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$stolen] = $this->logIn($user->email, 'old-password-1');
        $this->resetPassword($user, Password::createToken($user));

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $stolen)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $stolen)->post(route('customers.store'), ['name' => 'Planted'])->assertRedirect(route('login'));
        $this->assertSame(0, $this->businessOf($user)->customers()->count());
    }

    #[DataProvider('drivers')]
    public function test_a_failed_reset_revokes_nothing(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$laptop] = $this->logIn($user->email, 'old-password-1');
        [$phone] = $this->logIn($user->email, 'old-password-1');
        $token = Password::createToken($user);

        $this->resetPassword($user, 'wrong-token')->assertSessionHasErrors('email');
        $this->resetPassword($user, $token, 'short')->assertSessionHasErrors('password');
        $this->resetPassword(User::factory()->make(['email' => 'nobody@example.com']), $token)->assertSessionHasErrors('email');

        $this->assertTrue($this->isSignedIn($laptop));
        $this->assertTrue($this->isSignedIn($phone));
    }

    #[DataProvider('drivers')]
    public function test_other_accounts_keep_their_sessions(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);
        $other = User::factory()->create(['password' => 'other-password-1']);
        [$mine] = $this->logIn($user->email, 'old-password-1');
        [$theirs] = $this->logIn($other->email, 'other-password-1');

        $this->resetPassword($user, Password::createToken($user));

        $this->assertFalse($this->isSignedIn($mine));
        $this->assertTrue($this->isSignedIn($theirs), 'a reset only ends the sessions of the account it belongs to');
    }

    public function test_a_session_created_by_registering_and_never_used_again_is_revoked_too(): void
    {
        $this->forgetClient();
        $this->post(route('register'), [
            'name' => 'Aisha', 'business_name' => 'Aisha Trading', 'email' => 'aisha@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('dashboard'));
        $registered = session()->getId();
        $user = User::where('email', 'aisha@example.com')->sole();

        $this->resetPassword($user, Password::createToken($user));

        $this->assertFalse($this->isSignedIn($registered), 'no request was made with it before the reset, and it is still revoked');
    }

    public function test_logging_in_and_out_and_staying_signed_in_still_work(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user->email, 'old-password-1');

        // Several requests in the same session, as a normal visit.
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($this->isSignedIn($session));
        }

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)->post(route('logout'))->assertRedirect('/');
        $this->assertFalse($this->isSignedIn($session), 'logout ends the session');

        [$again] = $this->logIn($user->email, 'old-password-1');
        $this->assertTrue($this->isSignedIn($again));
    }

    public function test_a_remember_me_cookie_does_not_survive_a_reset(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [, $login] = $this->logIn($user->email, 'old-password-1', remember: true);

        $recaller = collect($login->headers->getCookies())->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($recaller, 'a remember cookie was issued');

        // Control: the cookie alone (no session) signs the user in.
        $this->forgetClient();
        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())->get(route('dashboard'))->assertOk();

        $this->resetPassword($user, Password::createToken($user));

        $this->forgetClient();
        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull(Auth::user());
    }
}
