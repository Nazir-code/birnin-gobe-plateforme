<?php

namespace App\Console\Commands;

use App\Domain\Auth\UserRole;

/**
 * Provisionnement d'un compte évaluateur (ADR-021).
 *
 *   php artisan evaluator:create
 *   php artisan evaluator:create --name="Ibrahim Yacouba" --email=ibrahim@exemple.ne
 *   printf '%s' "$MDP" | php artisan evaluator:create --name=… --email=… --password-stdin
 *
 * **Ce qui manquait.** `UserRole::EVALUATOR` existait depuis ADR-004 et l'espace
 * évaluateur depuis ADR-015, protégé par `role:evaluator` — mais rien dans le
 * code applicatif n'écrivait jamais ce rôle. Le seul endroit qui l'attribuait
 * était `DemonstrationSeeder`, un jeu de démonstration. Un évaluateur ne pouvait
 * donc exister qu'en base, à la main : cinq routes protégées que personne ne
 * pouvait franchir.
 *
 * Comme pour l'administration, il n'y a pas d'écran de création. Le §11.1 confie
 * la répartition des dossiers au responsable, pas le recrutement des
 * évaluateurs : celui-ci relève d'une décision institutionnelle hors plateforme,
 * et lui donner un formulaire dans le back-office laisserait croire le contraire.
 *
 * Le comportement est celui de `CreerUtilisateurInterne` ; cette classe n'ajoute
 * que le rôle.
 */
final class CreerEvaluateur extends CreerUtilisateurInterne
{
    protected $signature = 'evaluator:create
                            {--name= : Nom complet de l\'évaluateur}
                            {--email= : Adresse e-mail, qui sert d\'identifiant}
                            {--password-stdin : Lire le mot de passe sur l\'entrée standard, sans invite}';

    protected $description = 'Crée un compte évaluateur. Aucun formulaire public ne peut en produire.';

    protected function role(): UserRole
    {
        return UserRole::EVALUATOR;
    }

    protected function intitule(): string
    {
        return 'Évaluateur';
    }
}
