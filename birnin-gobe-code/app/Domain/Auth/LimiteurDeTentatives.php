<?php

namespace App\Domain\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Limitation des tentatives de connexion, commune à tous les espaces.
 *
 * Extrait d'`AuthenticatedSessionController` quand l'espace interne a eu besoin
 * de la même protection : dupliquer une règle de sécurité, c'est accepter que
 * les deux copies divergent. Il n'y a qu'un seul jeu de seuils.
 *
 * Clé de limitation : l'identifiant visé ET l'adresse d'origine.
 *
 * Limiter sur la seule adresse IP punirait tous les candidats derrière un même
 * opérateur ou un même cybercafé dès qu'une personne se trompe — un cas courant
 * au Niger. Limiter sur le seul e-mail laisserait un attaquant balayer les
 * comptes. La combinaison des deux est la pratique Laravel.
 *
 * La clé est en plus préfixée par l'espace : sans cela, marteler le formulaire
 * candidat avec l'adresse d'un administrateur suffirait à lui interdire l'accès
 * au back-office. Un espace ne doit pas pouvoir en bloquer un autre.
 */
final class LimiteurDeTentatives
{
    /** Tentatives autorisées avant blocage temporaire. */
    private const MAX_TENTATIVES = 5;

    /** Durée du blocage, en secondes. */
    private const BLOCAGE = 60;

    public function __construct(private readonly string $espace) {}

    /**
     * Refuse la tentative si le seuil est déjà atteint.
     *
     * @throws ValidationException
     */
    public function verifier(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->cle($request), self::MAX_TENTATIVES)) {
            return;
        }

        event(new Lockout($request));

        $secondes = RateLimiter::availableIn($this->cle($request));

        throw ValidationException::withMessages([
            'email' => __('Trop de tentatives. Réessayez dans :secondes secondes.', ['secondes' => $secondes]),
        ]);
    }

    /** Décompte une tentative infructueuse. */
    public function echec(Request $request): void
    {
        RateLimiter::hit($this->cle($request), self::BLOCAGE);
    }

    /** Remet le compteur à zéro après une connexion réussie. */
    public function reussite(Request $request): void
    {
        RateLimiter::clear($this->cle($request));
    }

    private function cle(Request $request): string
    {
        return $this->espace.'|'.Str::transliterate(
            Str::lower((string) $request->input('email')).'|'.$request->ip(),
        );
    }
}
