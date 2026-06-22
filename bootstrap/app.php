<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        $middleware->alias([
            'instructor' => \App\Http\Middleware\EnsureIsInstructor::class,
        ]);
        // Beta gate: content is free but requires an account. Guests hitting a
        // gated route are sent to signup (not login); Laravel stores the
        // intended URL so they return to the page after registering.
        $middleware->redirectGuestsTo(fn () => route('register'));
        // Payment provider webhooks are authenticated by signature, not CSRF.
        $middleware->validateCsrfTokens(except: [
            'webhooks/payments',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
