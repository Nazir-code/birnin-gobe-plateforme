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
     * **L'intersection de deux conditions, et il faut les deux.** Une section
     * compte si elle est *remplissable* — `SubmissionReadiness::requiredSections()`,
     * donc tout sauf « Relecture / envoi », qui n'écrit jamais de `completed_at`
     * — et si elle est sur le *parcours ouvert*, pour qu'une section développée
     * en avance derrière une étape fermée ne fasse pas avancer un parcours que
     * le candidat ne peut pas suivre (ADR-009).
     *
     * Ne garder que la seconde condition, comme c'était le cas, faisait compter
     * « Relecture » au dénominateur sans jamais pouvoir la compter au
     * numérateur : le plafond était 8/9, soit 89 %, y compris pour un dossier
     * déposé.
     *
     * @return list<string>
     */
    public static function countableSections(): array
    {
        $remplissables = SubmissionReadiness::requiredSections();

        return array_values(array_map(
            static fn (ApplicationSection $section): string => $section->value,
            array_filter(
                ApplicationSection::openPath(),
                static fn (ApplicationSection $section): bool => in_array($section, $remplissables, strict: true),
            ),
        ));
    }

    /**
     * Pourcentage à partir d'un nombre de sections achevées.
     *
     * **Le dénominateur est le nombre de sections remplissables, pas le total
     * des étapes.** Il valait `ApplicationSection::total()`, soit neuf, et le
     * raisonnement d'alors était juste : les étapes 8 et 9 n'existaient pas, le
     * parcours ouvert s'arrêtait à la 7, et compter sur neuf empêchait
     * d'annoncer 100 % à un candidat dont le dossier était en réalité
     * incomplet.
     *
     * L'étape 9 a depuis été livrée et rejointe au parcours ouvert, et la garde
     * s'est retournée contre elle-même : « Relecture / envoi » n'écrit aucun
     * `completed_at`, donc le numérateur plafonnait à huit pendant que le
     * dénominateur restait à neuf. **Un dossier complet, recevable et déposé
     * affichait 89 %**, sans qu'aucun geste du candidat puisse le porter plus
     * haut.
     *
     * Ce que le dénominateur d'origine protégeait est conservé : il compte
     * **toutes** les sections remplissables, y compris celles dont l'étape
     * n'est pas encore ouverte. Le jour où une dixième étape de contenu
     * s'ajoutera sans être ouverte, le plafond redescendra — ce qui est
     * exactement le comportement voulu, cette fois pour une section qu'un
     * candidat pourra un jour remplir.
     */
    public static function percentFromCompleted(int $completed): int
    {
        return (int) round($completed / self::total() * 100);
    }

    /**
     * Le dénominateur de la progression : combien de sections un dossier
     * complet compte.
     *
     * Exposé parce que les écrans l'affichent à côté du pourcentage — « 100 % ·
     * 8/8 ». Le laisser à `ApplicationSection::total()` ferait dire « 100 % ·
     * 8/9 » à un dossier déposé : deux chiffres issus de deux règles, côte à
     * côte, dont l'un contredirait l'autre.
     */
    public static function total(): int
    {
        return count(SubmissionReadiness::requiredSections());
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
