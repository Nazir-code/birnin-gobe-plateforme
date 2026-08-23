<?php

namespace App\Domain\Application;

use App\Models\Application;
use App\Models\ApplicationSectionAnswers;

/**
 * Avancement du dossier, calculé à partir des sections réellement achevées.
 *
 * Extrait de `SaveApplicationSection` à l'arrivée de l'étape 3, pour une raison
 * précise : `applications.completion_percent` est une valeur **dérivée**, mais
 * elle n'était recalculée qu'à l'écriture. Ouvrir une nouvelle étape change la
 * règle du parcours pour tout le monde — or les dossiers déjà en base, eux, ne
 * sont pas réécrits. Ils affichaient donc un pourcentage calculé sous l'ancienne
 * règle jusqu'à leur prochaine sauvegarde.
 *
 * Le cas concret que cela corrige : un brouillon d'avant cette phase, « Défi »
 * rempli, comptait 0 %, parce que « Défi » était alors hors parcours. Depuis que
 * l'étape 3 existe, il vaut 2/9 — et doit le montrer sans que le candidat ait
 * eu à toucher quoi que ce soit.
 *
 * D'où la règle : la **lecture** recalcule toujours, la colonne n'est qu'un
 * cache rafraîchi à chaque sauvegarde, utile aux requêtes d'administration et
 * de reporting qui ne peuvent pas charger chaque dossier.
 */
final class ApplicationProgress
{
    /**
     * Sections achevées **du parcours ouvert**, sur les neuf.
     *
     * Deux restrictions, pour deux raisons différentes :
     *
     *  - seules les sections **achevées** comptent : ouvrir un écran n'est pas
     *    le remplir, et `completed_at` est la seule preuve du contraire ;
     *  - seules les sections du **parcours ouvert** comptent — une section
     *    développée en avance, derrière une étape qui ne l'est pas, ne fait pas
     *    avancer un parcours encore fermé (ADR-009).
     */
    public function percent(Application $application): int
    {
        return (int) round($this->completedOnOpenPath($application) / ApplicationSection::total() * 100);
    }

    /** Nombre de sections achevées qui comptent réellement. */
    public function completedOnOpenPath(Application $application): int
    {
        $surLeParcours = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            ApplicationSection::openPath(),
        );

        return ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->whereIn('section', $surLeParcours)
            ->count();
    }
}
