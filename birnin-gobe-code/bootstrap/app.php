<?php

use App\Http\Middleware\EnsureApplicationIsEligible;
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

        // L'application ne reçoit jamais de requête directement : Caddy la lui
        // transmet. Sans cette déclaration, Laravel voit l'adresse du conteneur
        // Caddy comme adresse cliente et une connexion « en clair » même quand
        // le visiteur est en HTTPS — d'où des liens en `http://`, un
        // `$request->ip()` identique pour tout le monde, et une limitation des
        // tentatives de connexion qui compterait le proxy au lieu du visiteur.
        //
        // Le réseau Docker de la pile n'est pas routable depuis l'extérieur :
        // seul Caddy peut atteindre PHP-FPM, et faire confiance à ses en-têtes
        // ne crée donc pas de porte dérobée. `TRUSTED_PROXIES` reste réglable
        // au cas où un répartiteur de charge s'intercalerait un jour.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Contrôle d'accès par espace (ADR-003) : `->middleware('role:candidate')`.
        // Barrière d'éligibilité (ADR-007) : `->middleware('eligible')` sur les
        // sections postérieures à l'étape 1.
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'eligible' => EnsureApplicationIsEligible::class,
        ]);

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
