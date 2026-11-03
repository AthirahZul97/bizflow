<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    /**
     * Set a new password with a valid, unused token. A token is single-use and expires (see the
     * "passwords" block in config/auth.php). Whatever is wrong with the link, the message is the same.
     *
     * @throws ValidationException
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        // One transaction that first locks the account's row, so two submissions of the same link
        // run one after the other: the second finds the token already used. (The lock is a no-op on
        // SQLite; on MySQL it is what serializes them.)
        $status = DB::transaction(function () use ($request): string {
            User::query()->where('email', $request->string('email')->toString())->lockForUpdate()->first();

            return Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password): void {
                    // The model's "hashed" cast hashes it. The new remember token ends "remember me"
                    // sessions; AuthenticateSession ends every other signed-in session, because the
                    // password hash each one remembers no longer matches.
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordReset($user));
                },
            );
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Log in with the new password.');
    }
}
