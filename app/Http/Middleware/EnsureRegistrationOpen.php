<?php

namespace App\Http\Middleware;

use App\Support\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service registration only exists when the deployment allows it. When closed the routes
 * answer 404, as if they were not there, for the form and for a posted registration alike.
 */
class EnsureRegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(DemoMode::registrationOpen(), 404);

        return $next($request);
    }
}
