<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\LimiteurDeTentatives;
use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accès interne à l'administration (ADR-003, ADR-005).
 *
 * Flux distinct de la connexion candidat, sur la même garde de session native :
 * ce qui est séparé, ce sont le parcours et l'autorisation, pas l'infrastructure.
 * Il n'existe volontairement pas de pendant `create`/`store` d'inscription :
 * aucun formulaire ne crée de compte interne, ils sont provisionnés en ligne de
 * commande (`admin:create`).
 *
 * Cette page n'est annoncée nulle part dans l'interface publique ou candidate.
 */
final class AdminSessionController
{
    private readonly LimiteurDeTentatives $limiteur;

    public function __construct()
    {
        $this->limiteur = new LimiteurDeTentatives('interne-admin');
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            // Un administrateur déjà connecté est renvoyé vers son tableau de
            // bord — sans passer par `auth`, donc sans boucle de redirection.
            // Toute autre identité est simplement sortie de l'espace interne :
            // être connecté ailleurs ne donne aucun début de droit ici.
            return $user->hasRole(UserRole::ADMIN)
                ? redirect()->route('admin.dashboard')
                : redirect()->route('home');
        }

        return Inertia::render('Admin/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $identifiants = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->limiteur->verifier($request);

        // `attemptWhen` vérifie les identifiants, exécute la garde, et n'ouvre
        // la session que si elle passe. Le rôle est donc contrôlé *avant* qu'une
        // session existe : un candidat qui saisit ses bons identifiants ici n'est
        // à aucun instant authentifié sur l'espace interne. Un `Auth::attempt`
        // suivi d'un `logout()` aurait laissé exactement cette fenêtre ouverte.
        $connecte = Auth::attemptWhen(
            $identifiants,
            static fn (User $user): bool => $user->hasRole(UserRole::ADMIN),
            // Pas de « rester connecté » sur un espace interne.
            false,
        );

        if (! $connecte) {
            $this->limiteur->echec($request);

            // Même message, que l'adresse soit inconnue, que le mot de passe
            // soit faux, ou que le compte existe mais ne soit pas administrateur.
            // Distinguer ces cas reviendrait à confirmer qui est admin.
            throw ValidationException::withMessages([
                'email' => __('Identifiants incorrects.'),
            ]);
        }

        $this->limiteur->reussite($request);
        $request->session()->regenerate();

        return redirect()->to($this->destination($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Retour à l'accès interne, jamais vers un écran candidat.
        return redirect()->route('admin.login');
    }

    /**
     * Destination après connexion.
     *
     * `redirect()->intended()` rejouerait n'importe quelle URL mémorisée, y
     * compris `/candidate/...` laissée par une visite antérieure — l'admin
     * atterrirait alors sur un 403. On ne rejoue que l'espace interne.
     */
    private function destination(Request $request): string
    {
        $prevue = $request->session()->pull('url.intended');

        if (is_string($prevue) && str_starts_with((string) parse_url($prevue, PHP_URL_PATH), '/admin')) {
            return $prevue;
        }

        return route('admin.dashboard');
    }
}
