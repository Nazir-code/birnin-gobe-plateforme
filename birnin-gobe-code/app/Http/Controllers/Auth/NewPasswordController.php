<?php

namespace App\Http\Controllers\Auth;

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
 * Choix d'un nouveau mot de passe, depuis le lien reçu par courriel.
 *
 * Le jeton n'est jamais comparé à la main : `Password::reset()` le vérifie
 * contre `password_reset_tokens`, où il est **haché** — un vol de la table ne
 * donne donc pas de lien utilisable — et refuse un jeton périmé au-delà des
 * soixante minutes de `config/auth.php`. Il est consommé à l'usage : le même
 * lien ne sert pas deux fois.
 *
 * Ce que fait cette classe autour de cela, et qui compte autant :
 *
 * - **Le mot de passe est réellement changé, pas seulement enregistré.** Le
 *   jeton de « rester connecté » est régénéré : les sessions ouvertes ailleurs
 *   avec l'ancien mot de passe cessent d'être valides. Quelqu'un qui
 *   réinitialise parce qu'il soupçonne un accès indésirable attend exactement
 *   cela.
 *
 * - **Un échec ne dit pas pourquoi.** Jeton faux, jeton expiré, adresse
 *   inconnue : un seul message. Distinguer « ce jeton a expiré » de « cette
 *   adresse n'existe pas » rendrait le formulaire bavard sur les comptes.
 *
 * - **L'exigence de robustesse est celle de l'inscription**, pas une autre :
 *   un mot de passe choisi ici doit valoir celui qu'il remplace.
 */
final class NewPasswordController
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            // Repris de l'adresse et réaffichés : la personne arrive par un
            // lien, elle n'a pas à ressaisir ce que le lien porte déjà.
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', ReglesMotDePasse::min(8)],
        ]);

        $verdict = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $utilisateur, string $motDePasse): void {
                $utilisateur->forceFill([
                    // Le cast `hashed` du modèle chiffre la valeur : on ne
                    // hache pas ici, sous peine de hacher deux fois.
                    'password' => $motDePasse,
                    // Invalide les cookies « rester connecté » émis avant le
                    // changement.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($utilisateur));
            }
        );

        if ($verdict !== Password::PasswordReset) {
            // Un seul message pour tous les échecs — jeton faux, jeton expiré,
            // adresse inconnue. La cause exacte renseignerait sur l'existence
            // du compte.
            throw ValidationException::withMessages([
                'email' => __('Ce lien de réinitialisation n’est plus valide. Demandez-en un nouveau.'),
            ]);
        }

        return redirect()->route('login')->with('status', __(
            'Votre mot de passe a été modifié. Vous pouvez maintenant vous connecter.'
        ));
    }
}
