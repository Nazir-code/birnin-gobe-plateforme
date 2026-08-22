<?php

namespace App\Http\Middleware;

use App\Domain\Auth\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contrôle d'accès par espace (ADR-003).
 *
 * Appliqué au groupe de routes, pas dispersé en conditions dans les
 * contrôleurs : un espace entier est protégé par une seule déclaration, et
 * ajouter une route à ce groupe la protège automatiquement.
 *
 * S'utilise `->middleware('role:candidate')`, ou avec plusieurs rôles
 * `->middleware('role:admin,evaluator')`.
 *
 * Répond 403 plutôt que de rediriger : un candidat qui saisit /admin n'a pas
 * à être guidé vers un autre écran de connexion — ce serait précisément
 * annoncer l'existence du back-office.
 */
final class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // `auth` doit précéder ce middleware. On ne le remplace pas : un visiteur
        // anonyme mérite une redirection vers la connexion, pas un 403.
        abort_if($user === null, 403);

        $autorises = array_map(
            static fn (string $role): UserRole => UserRole::from($role),
            $roles,
        );

        abort_unless($user->hasRole(...$autorises), 403);

        return $next($request);
    }
}
