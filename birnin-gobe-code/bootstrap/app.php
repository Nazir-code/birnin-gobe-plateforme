<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);

        // Contrôle d'accès par espace (ADR-003) : `->middleware('role:candidate')`.
        $middleware->alias(['role' => EnsureUserHasRole::class]);

        // Un visiteur anonyme est renvoyé vers la connexion candidat — le seul
        // écran de connexion que le portail public expose.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central exception mapping / error codes will be added with domain use cases.
    })->create();
