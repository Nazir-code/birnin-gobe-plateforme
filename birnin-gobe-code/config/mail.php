<?php

/*
 * Transport des courriels.
 *
 * Le dépôt n'avait pas ce fichier : aucun courriel n'était envoyé, donc rien ne
 * le lisait. Il arrive avec la réinitialisation de mot de passe, qui est le
 * premier message que la plateforme adresse à quelqu'un.
 *
 * Trois transports déclarés, et un seul par environnement :
 *
 *   `log`    développement — le message part dans les journaux, en clair. On
 *            relit le lien de réinitialisation sans configurer de serveur, et
 *            aucun courriel ne peut partir par accident vers une vraie adresse
 *            pendant qu'on met au point le parcours ;
 *   `array`  tests — les messages sont gardés en mémoire, jamais envoyés ;
 *            c'est ce que `phpunit.xml` impose déjà, et c'est ce qui rend le
 *            parcours vérifiable sans serveur SMTP ;
 *   `smtp`   production — le serveur fourni par Niger Télécom.
 *
 * Le défaut du fichier est `log`, et c'est un choix : un poste de travail qui
 * n'a rien configuré écrit dans ses journaux au lieu d'essayer d'atteindre de
 * vraies personnes avec des réglages approximatifs. Ce défaut n'a rien d'un
 * état d'erreur — c'est le réglage attendu en développement.
 *
 * En production, `MAIL_MAILER=smtp` est en revanche obligatoire, et c'est
 * `.env.production.example` qui le pose. Laissé à `log`, le lien de
 * réinitialisation partirait dans les journaux du serveur sans qu'aucune erreur
 * ne soit levée : la panne la plus difficile à voir est celle qui n'échoue pas.
 */

return [

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            // `scheme` gouverne le chiffrement : `smtps` pour du TLS implicite
            // (port 465), `smtp` pour du STARTTLS (port 587). Laissé au serveur
            // plutôt que deviné, les deux se rencontrant encore.
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            // Nom annoncé au serveur SMTP. Certains relais refusent un `HELO`
            // qui ne correspond pas au domaine expéditeur.
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        // Bascule automatique : le premier transport disponible l'emporte.
        // Sert le jour où un second relais existera ; inutile aujourd'hui,
        // déclaré pour que la configuration n'ait pas à être réécrite alors.
        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
            'retry_after' => 60,
        ],
    ],

    /*
     * Expéditeur par défaut.
     *
     * L'adresse doit appartenir à un domaine que le serveur d'envoi est
     * autorisé à utiliser : une adresse arbitraire fait finir les messages en
     * indésirables, quand elle n'est pas purement refusée.
     */
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'ne-pas-repondre@birningobe.local'),
        'name' => env('MAIL_FROM_NAME', env('APP_NAME', 'BIRNIN GOBE')),
    ],
];
