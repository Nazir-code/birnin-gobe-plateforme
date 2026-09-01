<?php

/**
 * Notifications transactionnelles — §8.3.
 *
 * `sms.enabled` ne coupe pas une protection, il déclare une capacité. Tant
 * qu'aucun fournisseur n'est choisi — opérateur, identité d'expéditeur, coût
 * par message, et quelqu'un pour lire les réponses — les envois SMS sont
 * enregistrés comme non servis plutôt que passés sous silence. L'écran de
 * pilotage les compte, et le §9.2 « Communication » cesse de prétendre que rien
 * n'existe.
 *
 * `closing_reminder_days` est une constante nommée, pas un paramètre de
 * campagne : le §8.3 demande un rappel sans fixer de délai, et le §9.2 ne fait
 * pas figurer ce seuil parmi les réglages administrables. L'exposer donnerait à
 * croire qu'il a été arbitré — même raisonnement que les seuils d'alerte
 * d'ADR-014.
 */
return [
    'sms' => [
        'enabled' => env('SMS_ENABLED', false),
    ],

    /** Jours avant la clôture où le rappel part, du plus lointain au plus proche. */
    'closing_reminder_days' => [7, 1],
];
