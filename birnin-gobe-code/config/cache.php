<?php

return [
    'default' => env('CACHE_STORE', 'redis'),
    'stores' => [
        'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data')],
    ],
    'prefix' => env('CACHE_PREFIX', 'birnin_gobe_cache'),
];
