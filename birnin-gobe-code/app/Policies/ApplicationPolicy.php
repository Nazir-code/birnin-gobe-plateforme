<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

/**
 * Qui a le droit de faire quoi sur une candidature.
 *
 * Point unique du contrôle d'accès à la ressource. Les contrôleurs ne
 * comparent aucun identifiant : les routes déclarent `can:view,application` ou
 * `can:update,application`, et toute route ajoutée à ce groupe sans déclaration
 * saute aux yeux à la relecture — contrairement à un `if` oublié au fond d'une
 * méthode.
 *
 * Le middleware `role:candidate` protège l'espace ; cette policy protège la
 * ressource. Les deux sont nécessaires : le premier laisse passer tous les
 * candidats, y compris vers le dossier d'un autre.
 */
final class ApplicationPolicy
{
    public function view(User $user, Application $application): bool
    {
        return $this->estLeSien($user, $application);
    }

    /**
     * La modification s'arrête à la soumission.
     *
     * C'est la traduction du contrat « formulaire en lecture seule après
     * soumission » : une fois le dossier déposé, plus aucune requête — même
     * forgée, même émise par le propriétaire — ne peut réécrire les réponses.
     */
    public function update(User $user, Application $application): bool
    {
        return $this->estLeSien($user, $application) && $application->isDraft();
    }

    private function estLeSien(User $user, Application $application): bool
    {
        return $user->isCandidate() && $user->getKey() === $application->candidate_id;
    }
}
