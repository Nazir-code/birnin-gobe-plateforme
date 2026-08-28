<?php

namespace App\Domain\Verification;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Models\Application;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * La file de vérification — §10.1.
 *
 * Même forme que `ApplicationIndexQuery`, dont elle reprend les trois partis
 * pris (tout filtrer dans PostgreSQL, trier par liste blanche, départager par
 * identifiant). Ce qui lui est propre tient en trois points.
 *
 * 1. **Une file n'est pas une liste.** Le tri par défaut est le plus ancien
 *    dépôt d'abord, pas le plus récent : un dossier déposé en premier doit être
 *    contrôlé en premier, et un tri antichronologique enterrerait au fond de la
 *    file exactement les dossiers qui attendent depuis le plus longtemps.
 *
 * 2. **Elle ne montre que ce qui la concerne.** Un brouillon n'y entre pas — il
 *    appartient encore au candidat — et un dossier déjà en évaluation en est
 *    sorti. Les statuts admis sont ceux du §10.3, et ils sont énumérés, jamais
 *    déduits d'une comparaison d'ordre : `ApplicationStatus` est un enum de
 *    chaînes, pas une échelle.
 *
 * 3. **Le périmètre distingue « à traiter » de « traité ».** Par défaut la file
 *    ne montre que les dossiers en attente d'un geste. Les décidés restent
 *    consultables, sur demande explicite — c'est ce qui permet de relire une
 *    décision sans la rouvrir.
 */
final readonly class VerificationQueueQuery
{
    /** Une file se travaille par lots courts ; vingt-cinq tient sur un écran. */
    public const PER_PAGE = 25;

    /** Tris proposés. Toute autre valeur retombe sur `attente`. */
    public const SORTS = ['attente', 'recent', 'nom'];

    /** Périmètres proposés. Toute autre valeur retombe sur `ouverts`. */
    public const SCOPES = ['ouverts', 'traites', 'tous'];

    /**
     * Les statuts qui appellent encore un geste du vérificateur.
     *
     * @return list<ApplicationStatus>
     */
    public static function statutsOuverts(): array
    {
        return [
            ApplicationStatus::SUBMITTED,
            ApplicationStatus::PENDING_REVIEW,
            ApplicationStatus::CLARIFICATION_REQUESTED,
            ApplicationStatus::CLARIFICATION_RECEIVED,
        ];
    }

    /**
     * Les statuts déjà tranchés par le contrôle d'admissibilité.
     *
     * @return list<ApplicationStatus>
     */
    public static function statutsTraites(): array
    {
        return [ApplicationStatus::ADMISSIBLE, ApplicationStatus::INADMISSIBLE];
    }

    public function __construct(
        public ?int $campaignId = null,
        public ?ApplicationStatus $status = null,
        public ?string $search = null,
        public string $scope = 'ouverts',
        public string $sort = 'attente',
    ) {}

    /** @return LengthAwarePaginator<int, Application> */
    public function paginate(): LengthAwarePaginator
    {
        return $this->builder()->paginate(self::PER_PAGE)->withQueryString();
    }

    /**
     * Le nombre de dossiers en attente, filtres exclus.
     *
     * Sert à distinguer « la file est vide » — l'état normal d'une campagne
     * bien tenue — de « aucun résultat », qui n'est qu'un filtre trop étroit.
     */
    public static function totalOuverts(): int
    {
        return Application::query()
            ->whereIn('status', array_map(
                static fn (ApplicationStatus $statut): string => $statut->value,
                self::statutsOuverts(),
            ))
            ->count();
    }

    /** @return Builder<Application> */
    private function builder(): Builder
    {
        $requete = Application::query()
            ->with([
                'candidate:id,name,email',
                'campaign',
            ])
            ->withCount([
                'sections as completed_sections_count' => ApplicationProgress::countConstraint(),
                // Combien de contrôles de la grille sont déjà cochés : la file
                // doit montrer un dossier entamé autrement qu'un dossier
                // intact, sinon deux vérificateurs reprennent le même.
                'verificationChecks as verification_checks_count',
            ])
            ->whereIn('status', $this->statutsDuPerimetre());

        $this->appliquerFiltres($requete);
        $this->appliquerRecherche($requete);
        $this->appliquerTri($requete);

        return $requete;
    }

    /** @return list<string> */
    private function statutsDuPerimetre(): array
    {
        $statuts = match (in_array($this->scope, self::SCOPES, strict: true) ? $this->scope : 'ouverts') {
            'traites' => self::statutsTraites(),
            'tous' => array_merge(self::statutsOuverts(), self::statutsTraites()),
            default => self::statutsOuverts(),
        };

        return array_map(static fn (ApplicationStatus $statut): string => $statut->value, $statuts);
    }

    /** @param Builder<Application> $requete */
    private function appliquerFiltres(Builder $requete): void
    {
        $requete
            ->when($this->campaignId !== null, fn (Builder $q) => $q->where('campaign_id', $this->campaignId))
            // Le filtre de statut est intersecté avec le périmètre, jamais
            // substitué : demander « irrecevable » dans la file ouverte ne doit
            // pas en faire sortir des dossiers que le périmètre exclut.
            ->when(
                $this->status !== null && in_array($this->status->value, $this->statutsDuPerimetre(), true),
                fn (Builder $q) => $q->where('status', $this->status->value),
            );
    }

    /**
     * Recherche sur ce qui identifie un dossier, et rien d'autre.
     *
     * Numéro de dépôt, nom et adresse du compte. Le numéro passe en premier
     * dans l'intention : c'est la référence qu'un candidat cite au téléphone.
     *
     * @param  Builder<Application>  $requete
     */
    private function appliquerRecherche(Builder $requete): void
    {
        $terme = trim((string) $this->search);

        if ($terme === '') {
            return;
        }

        $motif = '%'.str_replace(['%', '_'], ['\%', '\_'], $terme).'%';

        $requete->where(function (Builder $q) use ($motif): void {
            $q->where('submission_number', 'ilike', $motif)
                ->orWhereHas('candidate', fn ($c) => $c
                    ->where('name', 'ilike', $motif)
                    ->orWhere('email', 'ilike', $motif));
        });
    }

    /**
     * Tri, exclusivement par liste blanche.
     *
     * `attente` — le plus ancien dépôt d'abord — est la valeur par défaut parce
     * que c'est la règle d'une file. Les dossiers sans date de dépôt (il ne
     * devrait pas y en avoir dans ces statuts) se rangent en fin plutôt que de
     * remonter en tête à la faveur d'un `NULL`.
     *
     * @param  Builder<Application>  $requete
     */
    private function appliquerTri(Builder $requete): void
    {
        match (in_array($this->sort, self::SORTS, strict: true) ? $this->sort : 'attente') {
            'recent' => $requete->orderByDesc('submitted_at')->orderByDesc('id'),
            'nom' => $requete
                ->orderBy(User::query()->select('name')->whereColumn('users.id', 'applications.candidate_id'))
                ->orderBy('id'),
            default => $requete->orderByRaw('submitted_at IS NULL')->orderBy('submitted_at')->orderBy('id'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function scopeOptions(): array
    {
        return [
            ['value' => 'ouverts', 'label' => 'À traiter'],
            ['value' => 'traites', 'label' => 'Déjà décidés'],
            ['value' => 'tous', 'label' => 'Tous'],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    public static function sortOptions(): array
    {
        return [
            ['value' => 'attente', 'label' => 'Le plus ancien dépôt d’abord'],
            ['value' => 'recent', 'label' => 'Le plus récent dépôt d’abord'],
            ['value' => 'nom', 'label' => 'Nom du candidat'],
        ];
    }

    /**
     * Les sections que l'écran de contrôle charge pour un dossier.
     *
     * Rassemblées ici plutôt que dans le contrôleur : l'écran de contrôle et le
     * cas d'usage de décision doivent voir le même dossier, faute de quoi le
     * verdict serait rendu sur des données que l'écran n'a pas montrées.
     *
     * @return list<string>
     */
    public static function relationsDuDossier(): array
    {
        return ['candidate:id,name,email', 'campaign', 'sections', 'attachments', 'verificationChecks', 'verificationDecisions'];
    }

    /** Les sections dont l'écran de contrôle affiche les réponses, dans l'ordre du parcours. */
    public static function sectionsAffichees(): array
    {
        return ApplicationSection::openPath();
    }
}
