<?php

/**
 * Analyse antivirus des pièces déposées — §15.1.
 *
 * `enabled` ne décide pas si les fichiers sont protégés : ils le sont dans tous
 * les cas, parce que seul l'état `CLEAN` ouvre le téléchargement. Il décide
 * seulement **si un analyseur est joignable**. Désactivé, chaque pièce reçoit un
 * verdict `UNAVAILABLE` — état honnête, visible sur l'écran d'alertes, et qui
 * garde les téléchargements fermés.
 *
 * C'est volontairement l'inverse du réglage habituel : couper l'antivirus
 * n'ouvre rien, ça ferme tout. Un interrupteur dont la position « off » relâche
 * une protection finit toujours par être trouvé en position « off ».
 *
 * En local, `clamav` vit derrière le profil Docker `fichiers` et n'est donc pas
 * démarré par défaut :
 *
 *     docker compose --profile fichiers up -d clamav
 */
return [
    'enabled' => env('CLAMAV_ENABLED', false),

    'host' => env('CLAMAV_HOST', 'clamav'),
    'port' => (int) env('CLAMAV_PORT', 3310),

    /** Secondes. Généreux : la première analyse suit le chargement des signatures. */
    'timeout' => (int) env('CLAMAV_TIMEOUT', 30),

    /**
     * Doit rester sous le `StreamMaxLength` de clamd, 25 Mio par défaut.
     * Au-delà, clamd ferme la connexion en cours de transfert, ce qui se lit
     * comme une panne réseau plutôt que comme un fichier trop lourd.
     */
    'max_bytes' => (int) env('CLAMAV_MAX_BYTES', 20 * 1024 * 1024),
];
