<?php

namespace App\Domain\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Limitation des créations de compte depuis une même origine.
 *
 * Distinct de `LimiteurDeTentatives`, et pour une raison de fond : les deux
 * formulaires n'ont pas le même abus à empêcher.
 *
 *   Connexion    on essaie **un compte** avec beaucoup de mots de passe. La clé
 *                doit donc porter l'e-mail visé, et seule une tentative
 *                **infructueuse** compte — un candidat qui se connecte bien dix
 *                fois n'attaque personne.
 *
 *   Inscription  on crée **beaucoup de comptes**, chacun avec un e-mail neuf.
 *                Compter par e-mail ne protégerait rien : il suffirait d'en
 *                changer. La clé porte donc l'adresse d'origine, et **chaque
 *                tentative** compte, réussie comme refusée — c'est la réussite
 *                qui est l'abus.
 *
 * Le seuil est volontairement plus large que celui de la connexion : plusieurs
 * personnes s'inscrivent légitimement depuis un même cybercafé ou un même
 * partage de connexion mobile, situation courante au Niger. Dix comptes par
 * quart d'heure laissent passer un atelier d'accompagnement et arrêtent un
 * script.
 *
 * Le refus prend la forme d'une erreur de validation sur le champ `email`, comme
 * à la connexion : un formulaire public doit expliquer, pas rendre un 429 nu.
 */
final class LimiteurDInscriptions
{
    /** Comptes créables depuis une même origine avant blocage. */
    private const MAX_INSCRIPTIONS = 10;

    /** Durée de la fenêtre, en secondes. */
    private const FENETRE = 900;

    /**
     * Refuse la tentative si le seuil est déjà atteint.
     *
     * @throws ValidationException
     */
    public function verifier(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->cle($request), self::MAX_INSCRIPTIONS)) {
            return;
        }

        $secondes = RateLimiter::availableIn($this->cle($request));

        throw ValidationException::withMessages([
            'email' => __(
                'Trop de comptes ont été créés depuis cette connexion. Réessayez dans :minutes minutes.',
                ['minutes' => max(1, (int) ceil($secondes / 60))],
            ),
        ]);
    }

    /**
     * Décompte une tentative.
     *
     * Appelée avant la validation des champs : un script qui bombarde le
     * formulaire avec des données invalides doit être freiné lui aussi, sinon
     * il suffirait d'échouer pour ne jamais être compté.
     */
    public function decompter(Request $request): void
    {
        RateLimiter::hit($this->cle($request), self::FENETRE);
    }

    private function cle(Request $request): string
    {
        return 'inscription|'.$request->ip();
    }
}
