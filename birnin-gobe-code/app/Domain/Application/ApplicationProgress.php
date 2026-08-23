<?php

namespace App\Domain\Application;

use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * La règle de progression d'une candidature, à un seul endroit.
 *
 * Elle vivait dans `SaveApplicationSection`, qui la calculait pour alimenter la
 * colonne `applications.completion_percent`. L'administration a besoin de la
 * même règle en lecture : la remettre à plat côté admin produirait deux
 * pourcentages pour un même dossier, et le jour où ils divergeraient personne ne
 * saurait lequel croire. La règle est donc extraite ici, telle quelle, et les
 * deux côtés l'appellent.
 *
 * La règle, inchangée (ADR-009) : **sections achevées du parcours ouvert, sur
 * les neuf**. Deux restrictions, pour deux raisons différentes :
 *
 *  - seules les sections **achevées** comptent — ouvrir un écran n'est pas le
 *    remplir, et `completed_at` est la seule preuve du contraire ;
 *  - seules les sections du **parcours ouvert** comptent — « Défi » est
 *    développé mais se trouve derrière « Structure / équipe », qui ne l'est pas.
 *    Le compter ferait croire que le parcours avance alors qu'il est fermé à
 *    l'étape 3.
 *
 * Conséquence voulue : le jour où une section s'ouvre, `openPath()` s'allonge et
 * les deux côtés suivent sans rien changer ici.
 *
 * Attention à la nature de `completion_percent` : c'est un **cache**, écrit à
 * chaque sauvegarde de section. Il reste exact tant que le parcours ouvert ne
 * bouge pas ; le jour où il bouge, les lignes non ré-enregistrées portent
 * l'ancien calcul. C'est pourquoi l'administration lit le compte **vif** —
 * `sql()` — plutôt que la colonne : un écran de pilotage ne doit pas afficher
 * un cache comme une vérité.
 */
final readonly class ApplicationProgress
{
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
     * Progression relue en base pour une candidature.
     *
     * Une requête par appel : réservé au cas unitaire — l'écriture d'une
     * section, le détail d'un dossier. Pour une liste, voir `countSubQuery()`,
     * qui fait la même chose en une seule requête pour toute la page.
     */
    public static function forApplication(Application $application): int
    {
        $achevees = ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->whereIn('section', self::countableSections())
            ->count();

        return self::percentFromCompleted($achevees);
    }

    /**
     * Contrainte à appliquer à un `withCount` sur la relation `sections`.
     *
     * Permet à une liste de compter les sections achevées de chaque dossier en
     * une seule requête — et de trier dessus côté PostgreSQL — sans dupliquer
     * la règle. Le compte obtenu passe ensuite par `percentFromCompleted()`.
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
