<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LimiteurDeTentatives;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connexion et déconnexion, sur la garde de session native de Laravel.
 */
final class AuthenticatedSessionController
{
    /**
     * Seuils et clé de limitation : voir `LimiteurDeTentatives`. La règle a été
     * extraite d'ici quand l'espace interne a eu besoin de la même protection,
     * pour qu'il n'en existe qu'une seule version.
     */
    private readonly LimiteurDeTentatives $limiteur;

    public function __construct()
    {
        $this->limiteur = new LimiteurDeTentatives('candidat');
    }

    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identifiants = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->limiteur->verifier($request);

        if (! Auth::attempt($identifiants, $request->boolean('remember'))) {
            $this->limiteur->echec($request);

            // Message volontairement identique pour un e-mail inconnu et un mot
            // de passe faux : distinguer les deux permettrait d'énumérer les
            // comptes existants.
            throw ValidationException::withMessages([
                'email' => __('Identifiants incorrects.'),
            ]);
        }

        $this->limiteur->reussite($request);

        // Contre la fixation de session : l'identifiant change à l'élévation
        // de privilège qu'est la connexion.
        $request->session()->regenerate();

        return redirect()->intended(route('candidate.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        // Invalide la session côté serveur puis régénère le jeton CSRF : le
        // cookie resté dans le navigateur ne vaut plus rien.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
