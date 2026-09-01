<?php

namespace App\Console\Commands;

use App\Domain\Auth\UserRole;

/**
 * Provisionnement d'un compte administrateur (ADR-006).
 *
 *   php artisan admin:create
 *   php artisan admin:create --name="Aïcha Diallo" --email=aicha@exemple.ne
 *   printf '%s' "$MDP" | php artisan admin:create --name=… --email=… --password-stdin
 *
 * Tout le comportement — politique de mot de passe, normalisation de l'adresse,
 * unicité, refus de promouvoir un compte existant — vit dans
 * `CreerUtilisateurInterne`. Cette classe n'ajoute que le rôle qu'elle impose.
 */
final class CreerAdministrateur extends CreerUtilisateurInterne
{
    protected $signature = 'admin:create
                            {--name= : Nom complet de l\'administrateur}
                            {--email= : Adresse e-mail, qui sert d\'identifiant}
                            {--password-stdin : Lire le mot de passe sur l\'entrée standard, sans invite}';

    protected $description = 'Crée un compte administrateur. Aucun formulaire public ne peut en produire.';

    protected function role(): UserRole
    {
        return UserRole::ADMIN;
    }

    protected function intitule(): string
    {
        return 'Administrateur';
    }
}
