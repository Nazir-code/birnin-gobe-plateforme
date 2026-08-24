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
        // La confiance s'arrête aux réseaux privés — pas `*`.
        //
        // `*` revient à croire quiconque parvient à parler à PHP-FPM. Tant que
        // seul Caddy y parvient, cela ne change rien ; le jour où un port est
        // publié par erreur, ou qu'un conteneur de dépannage est lancé sur le
        // réseau, n'importe qui peut alors forger `X-Forwarded-For` et se
        // présenter sous l'adresse de son choix. Ce n'est pas théorique ici : la
        // limitation des tentatives de connexion et celle des inscriptions sont
        // toutes deux clées sur `$request->ip()`. Les contourner suffirait à
        // verrouiller le compte d'un administrateur, ou à créer des comptes en
        // masse sans jamais être décompté.
        //
        // Les plages ci-dessous couvrent les réseaux Docker (172.16/12 par
        // défaut), un répartiteur de charge sur réseau privé et la boucle
        // locale. Caddy s'y trouve : HTTPS et l'adresse cliente restent donc
        // correctement détectés. `TRUSTED_PROXIES` demeure réglable si
        // l'infrastructure change — y compris pour revenir à `*`, mais alors en
        // connaissance de cause.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,127.0.0.1,::1,fc00::/7'),
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
