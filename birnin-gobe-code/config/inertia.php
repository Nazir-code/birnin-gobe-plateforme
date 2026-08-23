<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Emplacement des pages
    |--------------------------------------------------------------------------
    |
    | `assertInertia(fn ($page) => $page->component('Candidate/Dashboard'))`
    | vérifie que le fichier de la page existe réellement — c'est ce qui rend
    | l'assertion utile : elle attrape une page renommée côté React sans que la
    | route ait suivi.
    |
    | Encore faut-il chercher au bon endroit. Par défaut inertia-laravel regarde
    | dans `resources/js/pages`, en minuscules ; ce projet nomme le dossier
    | `Pages` (voir `resources/js/app.tsx`). Sous Windows la différence passe
    | inaperçue, mais Linux distingue la casse : en conteneur et en CI le
    | view-finder ne trouvait donc **aucune** page, et toute assertion
    | `->component()` échouait, y compris sur des pages bien présentes.
    |
    | Le bloc remplace entièrement celui du paquet — la fusion des
    | configurations est superficielle — il doit donc rester complet.
    |
    */

    'pages' => [

        // Laissé à `false` comme dans le paquet : le contrôle à l'exécution
        // n'a pas à faire échouer une requête en production. C'est le bloc
        // `testing` ci-dessous qui l'impose là où il a un sens.
        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/Pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Assertions de test
    |--------------------------------------------------------------------------
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

];
