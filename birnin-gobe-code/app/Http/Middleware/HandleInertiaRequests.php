<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Le rôle est partagé pour adapter l'affichage, jamais pour
            // autoriser : l'autorisation est faite par le middleware `role`.
            'auth' => [
                'user' => $user === null ? null : [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                ],
            ],
            'locale' => app()->getLocale(),
            // Message de confirmation d'une écriture, consommé une fois par la
            // page qui suit la redirection. Partagé ici plutôt que renvoyé en
            // prop par chaque contrôleur : « enregistré » se dit de la même
            // façon partout, et un contrat de sauvegarde explicite est une
            // exigence d'interface du projet (BLUEPRINT-UI-FOUNDATION).
            'flash' => [
                'status' => $request->session()->get('status'),
                // Le lien d'invitation d'un compte interne, quand aucun
                // transport de courriel ne l'a remis à son destinataire
                // (ADR-022). Partagé ici comme `status` : il voyage par la
                // session, sur la redirection qui suit la création, et n'existe
                // que le temps d'un affichage.
                'invitationLink' => $request->session()->get('invitationLink'),
            ],
        ];
    }
}
