<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        // Un visiteur anonyme est renvoyé vers l'écran de connexion de l'espace
        // qu'il visait : le portail public n'expose que la connexion candidat,
        // et envoyer vers celle-ci quelqu'un qui tape /admin/... l'enverrait dans
        // un formulaire qui ne peut pas le connecter.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central exception mapping / error codes will be added with domain use cases.
    })->create();
