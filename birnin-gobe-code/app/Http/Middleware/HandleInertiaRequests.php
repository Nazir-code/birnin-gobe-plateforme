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
        ];
    }
}
