<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\LimiteurDInscriptions;
use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inscription publique — crée exclusivement des candidats.
 *
 * Le rôle n'est jamais lu depuis la requête. Il est imposé ici, et `role` est
 * hors de `$fillable` sur le modèle : deux barrières indépendantes contre une
 * requête forgée avec `role=admin`.
 *
 * Le formulaire est ouvert à tous : il est donc limité, comme les deux écrans
 * de connexion, mais sur une clé et un seuil qui lui sont propres — voir
 * `LimiteurDInscriptions` pour la raison.
 */
final class RegisteredUserController
{
    private readonly LimiteurDInscriptions $limiteur;

    public function __construct()
    {
        $this->limiteur = new LimiteurDInscriptions;
    }

    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        // Vérifié puis décompté avant la validation : un script qui envoie des
        // données invalides doit être freiné au même titre qu'un script qui en
        // envoie de valides.
        $this->limiteur->verifier($request);
        $this->limiteur->decompter($request);

        $donnees = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = new User;
        $user->fill($donnees);
        // Assigné explicitement, jamais depuis l'entrée utilisateur.
        $user->role = UserRole::PUBLIC_SIGNUP;
        $user->save();

        // Émis pour que la vérification d'e-mail puisse s'y brancher plus tard
        // sans retoucher ce contrôleur (Phase 1B). Aucun listener aujourd'hui :
        // aucun message n'est envoyé, rien n'est simulé.
        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('candidate.dashboard'));
    }
}
