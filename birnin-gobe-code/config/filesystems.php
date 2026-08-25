<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
     * Disque des pieces de candidature (etape 8).
     *
     * Nomme separement du disque par defaut, et non deduit de lui : ce que le
     * §15.2 protege — une piece d'identite, un RCCM — ne doit pas changer
     * d'emplacement parce qu'un autre reglage a bouge. `documents` est prive par
     * construction, hors de `public/`, et aucune URL n'y mene : le
     * telechargement passe par une route qui verifie la propriete.
     *
     * `DOCUMENTS_DISK` permet de le pointer vers `s3` en production sans
     * toucher au code. La valeur par defaut, `documents`, fonctionne sans MinIO
     * — un objet de plus a deployer n'est pas une condition pour recevoir des
     * candidatures.
     */
    'documents' => env('DOCUMENTS_DISK', 'documents'),

    'disks' => [
        /*
         * La durabilite ne vient pas d'ici mais du deploiement : en Docker,
         * `storage/app/private` est monte sur un volume nomme. Voir
         * docker-compose.yml.
         */
        'documents' => [
            'driver' => 'local',
            'root' => storage_path('app/private/documents'),
            'visibility' => 'private',
            'serve' => false,
            'throw' => false,
        ],
        'local' => ['driver' => 'local', 'root' => storage_path('app/private'), 'serve' => true, 'throw' => false],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],
    ],
];
