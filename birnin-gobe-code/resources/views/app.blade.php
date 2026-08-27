<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#075b39">
    {{-- Favicon : l'emblème du logo seul, recadré carré. Le verrou complet
         — emblème plus « BIRNI'NGOBE » et son slogan — est un rectangle large
         qui ne serait plus lisible à 16 px : c'est le sigle qui tient à cette
         taille, pas le lockup. --}}
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/branding/favicon-32.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/branding/favicon-192.png">
    <link rel="apple-touch-icon" href="/assets/branding/apple-touch-icon.png">
    <title inertia>{{ config('app.name', 'BIRNIN GOBE') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
