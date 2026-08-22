<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connexion et déconnexion, sur la garde de session native de Laravel.
 */
final class AuthenticatedSessionController
{
    /** Tentatives autorisées avant blocage temporaire. */
    private const MAX_TENTATIVES = 5;

    /** Durée du blocage, en secondes. */
    private const BLOCAGE = 60;

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

        $this->verifierLimite($request);

        if (! Auth::attempt($identifiants, $request->boolean('remember'))) {
            RateLimiter::hit($this->cleLimite($request), self::BLOCAGE);

            // Message volontairement identique pour un e-mail inconnu et un mot
            // de passe faux : distinguer les deux permettrait d'énumérer les
            // comptes existants.
            throw ValidationException::withMessages([
                'email' => __('Identifiants incorrects.'),
            ]);
        }

        RateLimiter::clear($this->cleLimite($request));

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

    /**
     * Clé de limitation : l'identifiant visé ET l'adresse d'origine.
     *
     * Limiter sur la seule adresse IP punirait tous les candidats derrière un
     * même opérateur ou un même cybercafé dès qu'une personne se trompe — un
     * cas courant au Niger. Limiter sur le seul e-mail laisserait un attaquant
     * balayer les comptes. La combinaison des deux est la pratique Laravel.
     */
    private function cleLimite(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }

    private function verifierLimite(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->cleLimite($request), self::MAX_TENTATIVES)) {
            return;
        }

        event(new Lockout($request));

        $secondes = RateLimiter::availableIn($this->cleLimite($request));

        throw ValidationException::withMessages([
            'email' => __('Trop de tentatives. Réessayez dans :secondes secondes.', ['secondes' => $secondes]),
        ]);
    }
}
