<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's "verified" check, switched by configuration. Off (the default), it lets everyone
 * through, so local development and the demo behave as before. On (a private beta with a real
 * mailer), an account whose email address is not verified cannot do what it guards: today, send
 * invoice emails.
 */
class EnsureEmailVerifiedWhenRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('bizflow.require_verified_email')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            // Without the email flows there is no way to verify, so say no rather than loop.
            abort_unless(DemoMode::emailFlowsEnabled(), 403, 'Your email address is not verified.');

            return $request->expectsJson()
                ? abort(403, 'Your email address is not verified.')
                : Redirect::guest(route('verification.notice'));
        }

        return $next($request);
    }
}
