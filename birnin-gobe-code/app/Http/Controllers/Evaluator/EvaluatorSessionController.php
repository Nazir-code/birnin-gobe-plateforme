<?php

namespace App\Http\Controllers\Evaluator;

use App\Domain\Auth\UserRole;
use App\Http\Controllers\Auth\ConnexionInterne;

/**
 * Accès interne à l'espace évaluateur (ADR-021).
 *
 * **Ce qui manquait.** Les cinq routes de l'espace évaluateur étaient protégées
 * par `auth` + `role:evaluator` depuis ADR-015, mais aucun écran de connexion
 * n'admettait ce rôle : `/admin/login` filtre sur `UserRole::ADMIN`, et `/login`
 * est l'écran candidat. Un évaluateur, même provisionné, n'avait aucune porte —
 * la situation qu'ADR-006 décrivait pour l'administration avant de la corriger,
 * restée telle quelle pour l'évaluation.
 *
 * **Une clé de limitation distincte de celle de l'administration.** ADR-006
 * préfixe la clé par espace pour qu'un espace ne puisse pas en bloquer un
 * autre ; deux espaces internes qui partageraient la leur rouvriraient
 * exactement ce déni de service.
 *
 * Le comportement est celui de `ConnexionInterne` ; cette classe ne décrit que
 * ce qui appartient à l'évaluation.
 */
final class EvaluatorSessionController extends ConnexionInterne
{
    protected function role(): UserRole
    {
        return UserRole::EVALUATOR;
    }

    protected function cleDeLimitation(): string
    {
        return 'interne-evaluateur';
    }

    protected function page(): string
    {
        return 'Evaluator/Login';
    }

    /**
     * L'évaluateur n'a pas de tableau de bord : son écran d'entrée est son plan
     * de travail. C'est la seule entrée de navigation de l'espace (ADR-015).
     */
    protected function routeDApres(): string
    {
        return 'evaluator.assignments';
    }

    protected function routeDeConnexion(): string
    {
        return 'evaluator.login';
    }

    protected function routeDeDeconnexion(): string
    {
        return 'evaluator.logout';
    }

    protected function prefixeDEspace(): string
    {
        return '/evaluator';
    }
}
