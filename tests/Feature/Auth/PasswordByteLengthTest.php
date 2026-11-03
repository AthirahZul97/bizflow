<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * bcrypt reads only the first 72 BYTES of a password. The limit is enforced in bytes, so a password
 * made of multibyte characters cannot be accepted while being only partly checked.
 */
class PasswordByteLengthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, bool}>
     */
    public static function passwords(): array
    {
        return [
            '72 ascii bytes' => [str_repeat('a', 72), true],
            '73 ascii bytes' => [str_repeat('a', 73), false],
            '8 ascii bytes (the minimum)' => ['abcd1234', true],
            '7 ascii bytes' => ['abcd123', false],
            '36 two-byte characters = 72 bytes' => [str_repeat('é', 36), true],
            '37 two-byte characters = 74 bytes' => [str_repeat('é', 37), false],
            '24 three-byte characters = 72 bytes' => [str_repeat('€', 24), true],
            '25 three-byte characters = 75 bytes' => [str_repeat('€', 25), false],
            '18 four-byte characters = 72 bytes' => [str_repeat('😀', 18), true],
            '19 four-byte characters = 76 bytes' => [str_repeat('😀', 19), false],
            'mixed, 71 bytes' => [str_repeat('a', 69).'é', true],
            'mixed, 73 bytes' => [str_repeat('a', 71).'é', false],
            '8 characters that are 32 bytes' => [str_repeat('😀', 8), true],
        ];
    }

    #[DataProvider('passwords')]
    public function test_registration_enforces_the_limit_in_bytes(string $password, bool $accepted): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Aisha', 'business_name' => 'Aisha Trading', 'email' => 'aisha@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ]);

        if (! $accepted) {
            $response->assertSessionHasErrors('password');
            $this->assertSame(0, User::count());

            return;
        }

        $response->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $user = User::where('email', 'aisha@example.com')->sole();

        // The whole password is what is checked: changing its last character must fail.
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Hash::check(mb_substr($password, 0, -1).'Z', $user->password), 'the last character counts');
    }

    #[DataProvider('passwords')]
    public function test_a_reset_enforces_the_same_limit(string $password, bool $accepted): void
    {
        $user = User::factory()->create(['password' => 'old-password-1']);

        $response = $this->post(route('password.store'), [
            'token' => Password::createToken($user), 'email' => $user->email,
            'password' => $password, 'password_confirmation' => $password,
        ]);

        if (! $accepted) {
            $response->assertSessionHasErrors('password');
            $this->assertTrue(Hash::check('old-password-1', $user->fresh()->password), 'the old password still stands');

            return;
        }

        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Hash::check(mb_substr($password, 0, -1).'Z', $user->fresh()->password));
    }

    public function test_the_error_says_bytes_not_characters(): void
    {
        $password = str_repeat('😀', 19);

        $this->post(route('register'), [
            'name' => 'Aisha', 'business_name' => 'Aisha Trading', 'email' => 'aisha@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertSessionHasErrors(['password' => 'The password is too long: at most 72 bytes are allowed, and characters such as emoji or accented letters count for more than one.']);
    }
}
