<?php

namespace App\Http\Controllers\Auth;

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
 */
final class RegisteredUserController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
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
