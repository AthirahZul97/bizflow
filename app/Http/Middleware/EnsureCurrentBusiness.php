<?php

namespace App\Http\Middleware;

use App\Support\CurrentBusiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current business before any business page runs, so an account
 * without a business fails the same way everywhere (403) instead of partway
 * through a controller.
 */
class EnsureCurrentBusiness
{
    public function __construct(private readonly CurrentBusiness $currentBusiness) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->currentBusiness->get();

        return $next($request);
    }
}
