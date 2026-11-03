<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Laravel's AuthenticateSession, made to fail closed.
 *
 * AuthenticateSession ends a session when the account's password no longer matches the keyed hash
 * (the "fingerprint") the session remembers. But a signed-in session that has no fingerprint at all is,
 * in Laravel's version, trusted and given one from the CURRENT password, which would let a session
 * that predates this protection (or one whose fingerprint was lost) survive a password reset forever.
 *
 * Here such a session is logged out instead. A session gets its fingerprint when it signs in (the
 * Login listener in AppServiceProvider records it, for a password login, registration and a
 * "remember me" sign-in alike), never from anything the request carries, so a signed-in session
 * without one can only be an old session. The person signs in again and gets a new one; the login page
 * is for guests, so there is no redirect loop.
 */
class RequirePasswordFingerprint extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if ($request->hasSession() && $request->user() && $request->user()->getAuthPassword()
            && ! $request->session()->has('password_hash_'.$this->auth->getDefaultDriver())) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
