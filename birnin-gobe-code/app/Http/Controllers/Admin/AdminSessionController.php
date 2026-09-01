<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\UserRole;
use App\Http\Controllers\Auth\ConnexionInterne;

/**
 * Accès interne à l'administration (ADR-003, ADR-006).
 *
 * Tout le comportement vit dans `ConnexionInterne`, partagé avec l'espace
 * évaluateur : la vérification du rôle avant l'ouverture de session, le message
 * d'échec indistinct, l'absence de « rester connecté », et le refus de rejouer
 * une URL mémorisée hors de l'espace. Cette classe ne décrit que ce qui
 * appartient à l'administration.
 */
final class AdminSessionController extends ConnexionInterne
{
    protected function role(): UserRole
    {
        return UserRole::ADMIN;
    }

    protected function cleDeLimitation(): string
    {
        return 'interne-admin';
    }

    protected function page(): string
    {
        return 'Admin/Login';
    }

    protected function routeDApres(): string
    {
        return 'admin.dashboard';
    }

    protected function routeDeConnexion(): string
    {
        return 'admin.login';
    }

    protected function routeDeDeconnexion(): string
    {
        return 'admin.logout';
    }

    protected function prefixeDEspace(): string
    {
        return '/admin';
    }
}
