<?php

use App\Models\User;

return [
    'defaults' => ['guard' => env('AUTH_GUARD', 'web'), 'passwords' => 'users'],
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
    'passwords' => [
        'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],

        /*
         * Invitations de comptes internes (ADR-022).
         *
         * Table distincte et delai distinct, pour une raison de fond : une
         * reinitialisation est demandee par celui qui l'attend, une invitation
         * est envoyee par un tiers. Soixante minutes conviennent a la premiere
         * et rendent la seconde inutilisable — une invitation partie vendredi
         * soir serait morte lundi matin.
         *
         * Sept jours, donc. Et une table propre plutot qu'un parametre d'URL
         * choisissant la duree : un jeton n'existe que dans une table, et nul
         * ne peut allonger la validite du sien en modifiant un lien.
         *
         * `throttle` a zero : c'est l'administrateur qui declenche l'envoi, pas
         * le destinataire, et l'etrangler reviendrait a empecher de creer deux
         * evaluateurs de suite.
         */
        'invitations' => ['provider' => 'users', 'table' => 'internal_invitations', 'expire' => 60 * 24 * 7, 'throttle' => 0],
    ],
    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),
];
