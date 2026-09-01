<?php

namespace App\Http\Controllers\Auth;

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
 * Connexion à un espace interne (ADR-003, ADR-006, étendue par ADR-021).
 *
 * Flux distinct de la connexion candidat, sur la même garde de session native :
 * ce qui est séparé, ce sont le parcours et l'autorisation, pas
 * l'infrastructure. Il n'existe volontairement aucun pendant d'inscription —
 * les comptes internes sont provisionnés en ligne de commande
 * (`admin:create`, `evaluator:create`).
 *
 * **Une seule implémentation pour deux espaces.** ADR-006 pose que dupliquer
 * une règle de sécurité, c'est accepter que les deux copies divergent. Le point
 * qui compte ici — vérifier le rôle *avant* qu'une session existe — est
 * exactement le genre de détail qu'une seconde copie finit par perdre. Les
 * sous-classes ne décrivent que ce qui distingue leur espace.
 *
 * Ces pages ne sont annoncées nulle part dans l'interface publique ou candidate.
 */
abstract class ConnexionInterne
{
    private readonly LimiteurDeTentatives $limiteur;

    public function __construct()
    {
        $this->limiteur = new LimiteurDeTentatives($this->cleDeLimitation());
    }

    /** Le seul rôle admis dans cet espace. */
    abstract protected function role(): UserRole;

    /**
     * Le préfixe de la clé de limitation, propre à l'espace.
     *
     * **Jamais partagé entre espaces.** Sans cette séparation, marteler le
     * formulaire d'un espace avec l'adresse de quelqu'un suffirait à lui
     * interdire l'accès à l'autre : un déni de service à un seul paramètre.
     */
    abstract protected function cleDeLimitation(): string;

    /** Le composant Inertia de l'écran de connexion. */
    abstract protected function page(): string;

    /** La route d'atterrissage après connexion. */
    abstract protected function routeDApres(): string;

    /** La route de l'écran de connexion lui-même, pour le retour après déconnexion. */
    abstract protected function routeDeConnexion(): string;

    /** Le préfixe d'URL de l'espace, pour ne rejouer que ce qui lui appartient. */
    abstract protected function prefixeDEspace(): string;

    /** La route qui ferme la session en cours et ramène ici. */
    abstract protected function routeDeDeconnexion(): string;

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        // Qui a déjà le bon rôle repart vers son espace — sans passer par
        // `auth`, donc sans boucle de redirection.
        if ($user !== null && $user->hasRole($this->role())) {
            return redirect()->route($this->routeDApres());
        }

        // **Une autre identité voit l'écran, et non l'accueil.** ADR-006 la
        // renvoyait vers `home`, sans un mot : quelqu'un qui tapait l'URL de son
        // espace atterrissait sur la page publique, sans rien pour comprendre ni
        // rien à faire. Le renvoi était juste — être connecté ailleurs ne donne
        // aucun droit ici — mais muet, et cette discrétion se retournait contre
        // l'utilisateur légitime dont le navigateur gardait une session.
        //
        // Afficher le formulaire ne concède rien : le rôle reste vérifié au
        // `store()`, avant l'ouverture de session. Ce qui change est qu'on
        // nomme l'obstacle et qu'on donne le geste qui le lève.
        return Inertia::render($this->page(), [
            'sessionEnCours' => $user === null ? null : [
                'name' => $user->name,
                'roleLabel' => $user->role->label(),
                'logoutUrl' => route($this->routeDeDeconnexion()),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $identifiants = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->limiteur->verifier($request);

        $role = $this->role();

        // `attemptWhen` vérifie les identifiants, exécute la garde, et n'ouvre
        // la session que si elle passe. Le rôle est donc contrôlé *avant* qu'une
        // session existe : un candidat qui saisit ses bons identifiants ici n'est
        // à aucun instant authentifié sur l'espace interne. Un `Auth::attempt`
        // suivi d'un `logout()` aurait laissé exactement cette fenêtre ouverte.
        $connecte = Auth::attemptWhen(
            $identifiants,
            static fn (User $user): bool => $user->hasRole($role),
            // Pas de « rester connecté » sur un espace interne.
            false,
        );

        if (! $connecte) {
            $this->limiteur->echec($request);

            // Même message, que l'adresse soit inconnue, que le mot de passe
            // soit faux, ou que le compte existe sans porter le rôle attendu.
            // Distinguer ces cas reviendrait à confirmer qui travaille ici.
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
        return redirect()->route($this->routeDeConnexion());
    }

    /**
     * Destination après connexion.
     *
     * `redirect()->intended()` rejouerait n'importe quelle URL mémorisée, y
     * compris `/candidate/...` laissée par une visite antérieure — l'arrivant
     * atterrirait alors sur un 403. On ne rejoue que son propre espace.
     */
    private function destination(Request $request): string
    {
        $prevue = $request->session()->pull('url.intended');

        if (is_string($prevue) && str_starts_with((string) parse_url($prevue, PHP_URL_PATH), $this->prefixeDEspace())) {
            return $prevue;
        }

        return route($this->routeDApres());
    }
}
