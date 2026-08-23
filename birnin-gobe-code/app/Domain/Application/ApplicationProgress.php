<?php

namespace App\Domain\Application;

use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Avancement du dossier, calculé à partir des sections réellement achevées.
 *
 * **Une seule règle, un seul endroit.** Candidat et administration affichent le
 * même pourcentage pour un même dossier parce qu'ils appellent tous deux cette
 * classe — jamais leur propre arithmétique. Les deux phases qui ont abouti à ce
 * fichier l'avaient extraite chacune de son côté, de `SaveApplicationSection`,
 * pour la même raison et avec le même calcul ; l'intégration les a réunies en
 * une seule implémentation plutôt que d'en choisir une.
 *
 * Elle a été extraite parce que `applications.completion_percent` est une
 * valeur **dérivée** qui n'était recalculée qu'à l'écriture. Ouvrir une nouvelle
 * étape change la règle du parcours pour tout le monde — or les dossiers déjà
 * en base, eux, ne sont pas réécrits. Ils affichaient donc un pourcentage
 * calculé sous l'ancienne règle jusqu'à leur prochaine sauvegarde.
 *
 * Le cas concret que cela corrige : un brouillon d'avant l'étape 3, « Défi »
 * rempli, comptait 0 % parce que « Défi » était alors hors parcours. Depuis que
 * l'étape 3 existe, ce même « Défi » compte — sans que le candidat ait eu à
 * toucher quoi que ce soit, et sans qu'aucune ligne ait été réécrite.
 *
 * D'où la règle : la **lecture recalcule toujours**, la colonne n'est qu'un
 * cache rafraîchi à chaque sauvegarde.
 *
 * Deux façons de poser la même question, selon qui la pose :
 *
 *   `percent()` / `completedOnOpenPath()` — un dossier, une requête. Pour
 *     l'écriture d'une section et pour l'écran d'un dossier ;
 *   `countConstraint()` / `percentFromCompleted()` — la même règle exprimée
 *     pour un `withCount`, afin qu'une liste de vingt-cinq dossiers la calcule
 *     en une seule requête et puisse trier dessus côté PostgreSQL.
 *
 * Les secondes sont statiques et pures : elles décrivent la règle sans toucher
 * la base. Les premières l'appliquent à un dossier stocké.
 */
final readonly class ApplicationProgress
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
        return self::percentFromCompleted($this->completedOnOpenPath($application));
    }

    /** Nombre de sections achevées qui comptent réellement. */
    public function completedOnOpenPath(Application $application): int
    {
        return ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->whereIn('section', self::countableSections())
            ->count();
    }

    /**
     * Les sections que la progression a le droit de compter, en valeurs
     * persistées — donc directement utilisables dans une requête.
     *
     * @return list<string>
     */
    public static function countableSections(): array
    {
        return array_map(
            static fn (ApplicationSection $section): string => $section->value,
            ApplicationSection::openPath(),
        );
    }

    /**
     * Pourcentage à partir d'un nombre de sections achevées.
     *
     * Le dénominateur est le total des neuf étapes, et non la longueur du
     * parcours ouvert : le candidat doit voir la part de son dossier réellement
     * faite, pas une fraction d'un parcours qui s'allongera.
     */
    public static function percentFromCompleted(int $completed): int
    {
        return (int) round($completed / ApplicationSection::total() * 100);
    }

    /**
     * La même règle, exprimée comme contrainte d'un `withCount` sur `sections`.
     *
     * Permet à une liste de compter les sections achevées de chaque dossier en
     * une seule requête — et de trier dessus côté PostgreSQL — sans dupliquer
     * la règle. Le compte obtenu passe ensuite par `percentFromCompleted()`, et
     * rend donc exactement ce que `percent()` aurait rendu dossier par dossier.
     *
     * @return Closure(Builder<ApplicationSectionAnswers>): void
     */
    public static function countConstraint(): Closure
    {
        return static function (Builder $requete): void {
            $requete->whereNotNull('completed_at')->whereIn('section', self::countableSections());
        };
    }
}
