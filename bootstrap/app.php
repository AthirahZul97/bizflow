<?php

use App\Http\Middleware\EnsureCurrentBusiness;
use App\Http\Middleware\EnsureSubscriptionWritable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'business' => EnsureCurrentBusiness::class,
            'subscription.writable' => EnsureSubscriptionWritable::class,
        ]);

        // Decide read-only before route-model binding, so a forged record ID and a missing one
        // are refused identically.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureSubscriptionWritable::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
