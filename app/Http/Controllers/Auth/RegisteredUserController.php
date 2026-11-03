<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\BusinessRegistration;
use App\Support\DemoMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration form.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Register a new user with their business and log them in.
     *
     * The user, business and owner membership are created in one transaction;
     * the user is only logged in once all three exist.
     */
    public function store(RegisterRequest $request, BusinessRegistration $registration): RedirectResponse
    {
        $user = $registration->register($request->safe()->only(['name', 'email', 'password', 'business_name']));

        Auth::login($user);

        $request->session()->regenerate();

        // Queued, so a mail problem can never undo or delay a registration that has succeeded.
        if (DemoMode::emailFlowsEnabled()) {
            try {
                $user->sendEmailVerificationNotification();
            } catch (Throwable $e) {
                report($e);
            }
        }

        return redirect()->route('dashboard')
            ->with('status', 'Welcome to BizFlow! Your account has been created.');
    }
}
