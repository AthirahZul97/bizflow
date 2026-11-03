<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Send a reset link if the address belongs to an account, and say the same thing either way.
     *
     * The broker's own result is deliberately ignored: "user not found" and "throttled for this
     * user" would each tell the visitor something about the account. The email itself is queued
     * (QueuedResetPassword), so the response time does not give it away either. If queueing fails the
     * failure is reported and the answer is still the same.
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        try {
            Password::sendResetLink($request->only('email'));
        } catch (Throwable $e) {
            // A queue or mail outage must not turn this into an error page that only an existing
            // account would get: report it and answer exactly as for any other address.
            report($e);
        }

        return back()->with('status', 'If an account exists for that email address, a password reset link is on its way.');
    }
}
