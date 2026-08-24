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
 * **Le seuil est délibérément haut, et c'est le point le plus important de
 * cette classe.** Les opérateurs mobiles nigériens partagent massivement leurs
 * adresses publiques (CGNAT) : des milliers de candidats peuvent se présenter
 * sous la même IP. Un cybercafé, un campus, un atelier d'accompagnement
 * produisent le même effet. Un seuil serré n'arrêterait pas seulement les
 * scripts — il fermerait la porte à des candidats légitimes le jour du
 * lancement, et ce dommage-là est bien pire que celui qu'on cherche à éviter.
 *
 * Soixante comptes par tranche de cinq minutes, soit douze par minute, tracent
 * la frontière au bon endroit : un formulaire d'inscription se remplit en une
 * demi-minute au moins, donc douze par minute supposent déjà une douzaine de
 * personnes simultanées derrière la même adresse — rare, et rattrapé en cinq
 * minutes. Un script, lui, en tenterait des centaines par seconde et bute
 * immédiatement.
 *
 * Ce que cette limite ne prétend pas faire : arrêter une création de comptes
 * distribuée sur de nombreuses adresses. Seules la vérification d'adresse
 * e-mail et une épreuve anti-robot y parviendraient ; ni l'une ni l'autre
 * n'existe encore. La limite couvre le cas du script unique, pas davantage.
 *
 * Le refus prend la forme d'une erreur de validation sur le champ `email`, comme
 * à la connexion : un formulaire public doit expliquer, pas rendre un 429 nu.
 */
final class LimiteurDInscriptions
{
    /** Comptes créables depuis une même origine avant blocage. */
    private const MAX_INSCRIPTIONS = 60;

    /** Durée de la fenêtre, en secondes. */
    private const FENETRE = 300;

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
