<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * A signed-in session that has no password fingerprint (one that predates the protection, or lost it)
 * is logged out, never given a fingerprint from the current password. Fingerprints come only from
 * signing in, and never from the request.
 */
class LegacySessionTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'password_hash_web';

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
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->flushSession();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /**
     * @return array{string, TestResponse}
     */
    private function logIn(User $user, string $password = 'old-password-1', bool $remember = false): array
    {
        $this->forgetClient();

        $response = $this->post(route('login'), ['email' => $user->email, 'password' => $password] + ($remember ? ['remember' => '1'] : []));
        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticated();

        return [session()->getId(), $response];
    }

    /**
     * Turn a signed-in session into one from before the protection: still signed in, no fingerprint.
     */
    private function makeLegacy(string $sessionId): void
    {
        $this->forgetClient();

        // Load that session, drop the fingerprint, write it back.
        $this->app['session']->setId($sessionId);
        $this->app['session']->start();
        $this->assertTrue($this->app['session']->has(self::KEY), 'a real sign-in left a fingerprint');
        $this->app['session']->forget(self::KEY);
        $this->app['session']->save();

        $this->forgetClient();
    }

    private function request(string $sessionId, string $uri = '/dashboard'): TestResponse
    {
        $this->forgetClient();

        return $this->withCookie(config('session.cookie'), $sessionId)->get($uri);
    }

    // ---- 1. a legacy session --------------------------------------------------------------------

    #[DataProvider('drivers')]
    public function test_a_signed_in_session_without_a_fingerprint_is_logged_out(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user);
        $this->assertSame(200, $this->request($session)->getStatusCode(), 'control: the session works while it has its fingerprint');

        $this->makeLegacy($session);

        $this->request($session)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(302, $this->request($session)->getStatusCode(), 'and it stays logged out');
    }

    #[DataProvider('drivers')]
    public function test_a_legacy_session_cannot_write_either(string $driver): void
    {
        $this->useDriver($driver);
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user);
        $this->makeLegacy($session);

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)->post(route('customers.store'), ['name' => 'Planted'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, $this->businessOf($user)->customers()->count());
    }

    public function test_a_legacy_session_is_not_given_a_fingerprint_by_being_used(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user);
        $this->makeLegacy($session);

        // Many requests, none of which may quietly start trusting it.
        for ($i = 0; $i < 3; $i++) {
            $this->request($session)->assertRedirect(route('login'));
        }

        $this->forgetClient();
        $this->app['session']->setId($session);
        $this->app['session']->start();
        $this->assertFalse($this->app['session']->has(self::KEY));
        $this->assertNull($this->app['session']->get('login_web_'.sha1(SessionGuard::class)), 'the user was removed from it');
    }

    public function test_a_fingerprint_sent_in_the_request_is_never_accepted(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user);
        $good = $this->app['auth']->guard()->hashPasswordForCookie($user->fresh()->getAuthPassword());
        $this->makeLegacy($session);

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)
            ->withHeaders(['X-Password-Hash' => $good, 'X-Password-Fingerprint' => $good])
            ->get('/dashboard?'.http_build_query([self::KEY => $good]))
            ->assertRedirect(route('login'));

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)
            ->get('/dashboard', [self::KEY => $good])
            ->assertRedirect(route('login'));

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)
            ->post(route('customers.store'), [self::KEY => $good, 'name' => 'X'])
            ->assertRedirect(route('login'));
    }

    // ---- 2. signing in again ----------------------------------------------------------------------

    public function test_the_person_can_sign_in_again_after_a_legacy_session_is_rejected(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$old] = $this->logIn($user);
        $this->makeLegacy($old);
        $this->request($old)->assertRedirect(route('login'));

        // The login page loads for a guest (no redirect loop) and signing in works.
        $this->forgetClient();
        $this->get(route('login'))->assertOk();

        [$fresh] = $this->logIn($user);
        $this->assertNotSame($old, $fresh);
        $this->assertSame(200, $this->request($fresh)->getStatusCode());
        $this->assertSame(200, $this->request($fresh, '/customers')->getStatusCode());
    }

    public function test_following_the_redirect_ends_on_the_login_page(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session] = $this->logIn($user);
        $this->makeLegacy($session);

        $this->forgetClient();
        $this->withCookie(config('session.cookie'), $session)->followingRedirects()->get('/dashboard')
            ->assertOk()->assertSee('Log in to');
    }

    // ---- 3. new sign-ins get a fingerprint at once -----------------------------------------------------

    public function test_a_new_sign_in_has_its_fingerprint_before_any_other_request(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);

        $this->logIn($user);

        $this->assertTrue(session()->has(self::KEY));
        $this->assertSame(
            $this->app['auth']->guard()->hashPasswordForCookie($user->fresh()->getAuthPassword()),
            session(self::KEY),
        );
        $this->assertStringNotContainsString($user->fresh()->getAuthPassword(), (string) session(self::KEY), 'a keyed hash, not the stored password hash');
    }

    // ---- 4. registration ----------------------------------------------------------------------------------

    public function test_registering_signs_in_with_a_fingerprint_and_the_first_request_works(): void
    {
        $this->forgetClient();
        $this->post(route('register'), [
            'name' => 'Aisha', 'business_name' => 'Aisha Trading', 'email' => 'aisha@example.com',
            'password' => 'secret-pass-123', 'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('dashboard'));
        $session = session()->getId();

        $this->assertTrue(session()->has(self::KEY), 'present straight after registration');

        foreach (['/dashboard', '/customers', '/invoices'] as $uri) {
            $this->assertSame(200, $this->request($session, $uri)->getStatusCode(), $uri);
        }
    }

    // ---- 5. password reset ----------------------------------------------------------------------------------

    public function test_a_reset_still_ends_independent_sessions_that_have_fingerprints(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$laptop] = $this->logIn($user);
        [$phone] = $this->logIn($user);

        $this->forgetClient();
        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ])->assertRedirect(route('login'));

        $this->request($laptop)->assertRedirect(route('login'));
        $this->request($phone)->assertRedirect(route('login'));

        [$fresh] = $this->logIn($user, 'brand-new-pass-1');
        $this->assertSame(200, $this->request($fresh)->getStatusCode());
    }

    // ---- 6. remember me ----------------------------------------------------------------------------------------

    private function recaller(TestResponse $login): Cookie
    {
        $cookie = collect($login->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($cookie, 'a remember cookie was issued');

        return $cookie;
    }

    public function test_a_remember_me_sign_in_on_a_new_session_gets_a_fingerprint_and_keeps_working(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [, $login] = $this->logIn($user, remember: true);
        $recaller = $this->recaller($login);

        // The browser lost its session but still has the cookie.
        $this->forgetClient();
        $first = $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())->get('/dashboard');
        $first->assertOk();
        $newSession = session()->getId();
        $this->assertTrue(session()->has(self::KEY), 'signing in from the cookie records a fingerprint too');

        // And the new session works on its own, with no loop.
        $this->assertSame(200, $this->request($newSession)->getStatusCode());
        $this->assertSame(200, $this->request($newSession, '/customers')->getStatusCode());
    }

    public function test_a_legacy_session_that_also_has_a_remember_cookie_must_sign_in_again(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$session, $login] = $this->logIn($user, remember: true);
        $recaller = $this->recaller($login);
        $this->makeLegacy($session);

        $this->forgetClient();
        $response = $this->withCookie(config('session.cookie'), $session)
            ->withUnencryptedCookie($recaller->getName(), $recaller->getValue())
            ->get('/dashboard')
            ->assertRedirect(route('login'));

        // The browser is told to drop the remember cookie, so the person really does sign in again.
        $forget = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === $recaller->getName());
        $this->assertNotNull($forget);
        $this->assertLessThan(time(), $forget->getExpiresTime(), 'the remember cookie is expired in the browser');

        // A copy of that cookie replayed later is exactly what "remember me" has always allowed: it
        // is checked against the account's current password, and it signs in a NEW session, which gets
        // its own fingerprint. (A password reset kills it: see the test below.)
        $this->forgetClient();
        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())->get('/dashboard')->assertOk();
        $this->assertTrue(session()->has(self::KEY));
    }

    public function test_a_remember_cookie_does_not_survive_a_password_reset(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [, $login] = $this->logIn($user, remember: true);
        $recaller = $this->recaller($login);

        $this->forgetClient();
        $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ]);

        $this->forgetClient();
        $this->withUnencryptedCookie($recaller->getName(), $recaller->getValue())->get('/dashboard')->assertRedirect(route('login'));
    }

    // ---- 7. a failed reset ----------------------------------------------------------------------------------------

    public function test_a_failed_reset_keeps_valid_sessions_and_does_not_touch_legacy_ones_either(): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);
        [$valid] = $this->logIn($user);
        [$legacy] = $this->logIn($user);
        $this->makeLegacy($legacy);

        $this->forgetClient();
        $this->post(route('password.store'), [
            'token' => 'wrong-token', 'email' => $user->email,
            'password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(200, $this->request($valid)->getStatusCode(), 'a failed reset signs nobody out');
        $this->request($legacy)->assertRedirect(route('login'));
    }

    // ---- guests and ordinary pages ----------------------------------------------------------------------------------

    public function test_guests_and_the_public_pages_are_unaffected(): void
    {
        $this->forgetClient();

        $this->get('/')->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get('/health')->assertOk();
    }
}
