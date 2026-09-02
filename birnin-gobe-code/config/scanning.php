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

    /**
     * Dérogation : les rôles internes peuvent-ils ouvrir une pièce non analysée ?
     *
     * **Fermée par défaut, et elle doit le rester partout où un analyseur peut
     * tourner.** Elle existe pour un cas précis : un hébergement mutualisé où
     * `clamd` est impossible — il exige un démon permanent et plusieurs centaines
     * de mégaoctets pour sa base de signatures. Sans dérogation, aucun
     * vérificateur ne peut ouvrir la moindre pièce, et le contrôle
     * d'admissibilité du §10 s'arrête.
     *
     * Ce qu'elle n'ouvre **jamais** : une pièce en quarantaine. Une menace
     * détectée reste fermée à tous, y compris au déposant.
     *
     * Le prix est assumé et rendu visible : chaque ouverture dérogatoire est
     * écrite au journal d'audit, et l'écran de pilotage annonce en permanence
     * que la dérogation est active. Un écart qu'on voit vaut mieux qu'une
     * protection qu'on croit avoir.
     */
    'allow_unscanned_internal' => env('ATTACHMENTS_ALLOW_UNSCANNED', false),

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
