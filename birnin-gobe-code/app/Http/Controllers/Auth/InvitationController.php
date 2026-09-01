<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as ReglesMotDePasse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Première définition du mot de passe d'un compte interne — ADR-022.
 *
 * **Le courtier `invitations`, jamais celui par défaut.** Il est fixé par le
 * contrôleur, pas par un paramètre de requête : sans cela, un lien de
 * réinitialisation ordinaire présenté ici vivrait sept jours au lieu de
 * soixante minutes. Le choix du courtier n'est pas une donnée d'entrée.
 *
 * **Le compte existe déjà, avec un mot de passe que personne ne connaît.**
 * `CreerUtilisateurInterne` lui donne une valeur aléatoire de 64 caractères :
 * le compte est inaccessible jusqu'à ce que ce formulaire soit rempli, et
 * l'administrateur qui l'a créé n'a lui-même aucun moyen d'entrer. C'est ce
 * qui fait qu'un compte notant des candidatures n'a qu'un seul détenteur.
 *
 * **L'échec ne dit pas pourquoi**, comme pour la réinitialisation : jeton faux,
 * jeton expiré, adresse inconnue — un seul message. Les distinguer rendrait le
 * formulaire bavard sur l'existence des comptes internes.
 *
 * **Le retour se fait vers l'espace de la personne**, par
 * `UserRole::routeDeConnexion()`. La règle vit sur le rôle et non ici, parce
 * que la réinitialisation de mot de passe rend la main de la même façon : deux
 * copies auraient divergé le jour où un espace s'ajoute.
 */
final class InvitationController
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/Invitation', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', ReglesMotDePasse::defaults()],
        ]);

        $destination = null;

        $verdict = Password::broker('invitations')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $utilisateur, string $motDePasse) use (&$destination): void {
                $utilisateur->forceFill([
                    // Le cast `hashed` du modèle chiffre la valeur : ne pas
                    // hacher ici, sous peine de hacher deux fois.
                    'password' => $motDePasse,
                    'remember_token' => Str::random(60),
                ])->save();

                $destination = $utilisateur->role;

                event(new PasswordReset($utilisateur));
            }
        );

        if ($verdict !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => __('Cette invitation n’est plus valide. Demandez un nouveau lien depuis « mot de passe oublié ».'),
            ]);
        }

        return redirect()->route(($destination ?? UserRole::CANDIDATE)->routeDeConnexion())->with('status', __(
            'Votre mot de passe est défini. Vous pouvez maintenant vous connecter.'
        ));
    }
}
