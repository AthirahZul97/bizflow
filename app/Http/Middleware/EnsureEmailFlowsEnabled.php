<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Password reset and email verification work by sending email, so they are switched off with it.
 * When off the routes answer 404 and nothing is sent.
 */
class EnsureEmailFlowsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DemoMode::emailFlowsEnabled(), 404);

        return $next($request);
    }
}
