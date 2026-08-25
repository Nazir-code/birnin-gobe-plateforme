<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LimiteurDeTentatives;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Demande d'un lien de réinitialisation — « Mot de passe oublié ».
 *
 * **La réponse est la même quoi qu'il arrive.** Adresse inconnue, adresse
 * connue, demande déjà faite il y a trente secondes : dans les trois cas
 * l'écran affiche le même message. C'est la seule façon d'empêcher qu'un
 * formulaire public serve à établir la liste des personnes inscrites — et cette
 * plateforme héberge des candidatures individuelles, dont le seul fait
 * d'exister est une information.
 *
 * Le piège est dans le détail : `Password::sendResetLink()` distingue
 * `INVALID_USER` (adresse inconnue) de `RESET_THROTTLED` (demande trop
 * rapprochée). Les afficher séparément suffirait à énumérer les comptes — la
 * seconde réponse ne pouvant venir que d'une adresse existante. Tous les
 * verdicts sont donc ramenés à un seul message.
 *
 * Deux limitations se superposent, et elles ne protègent pas la même chose :
 *
 *   celle de `LimiteurDeTentatives`, sur e-mail + adresse IP, empêche
 *     d'arroser le formulaire — cinq demandes, puis une minute d'attente ;
 *   celle du broker Laravel, dans `config/auth.php`, empêche d'inonder une
 *     boîte donnée de courriels — un lien par adresse et par minute.
 *
 * La première protège la plateforme, la seconde protège la personne.
 */
final class PasswordResetLinkController
{
    private readonly LimiteurDeTentatives $limiteur;

    public function __construct()
    {
        $this->limiteur = new LimiteurDeTentatives('mot-de-passe-oublie');
    }

    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $this->limiteur->verifier($request);

        // Chaque demande compte, aboutie ou non — et il n'y a volontairement
        // pas d'appel à `reussite()`. Remettre le compteur à zéro quand
        // l'adresse existe rendrait le comportement du formulaire différent
        // selon le compte, ce que tout le reste de cette classe s'applique à
        // éviter.
        $this->limiteur->echec($request);

        Password::sendResetLink($request->only('email'));

        return back()->with('status', __(
            'Si un compte existe pour cette adresse, un lien de réinitialisation vient d’être envoyé. Pensez à vérifier vos indésirables.'
        ));
    }
}
