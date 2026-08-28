<?php

namespace App\Domain\Evaluation;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Le tableau d'affectation — §11.1.
 *
 * Deux questions, une classe, parce qu'elles se répondent l'une l'autre : quels
 * dossiers attendent un évaluateur, et quelle charge porte déjà chaque
 * évaluateur. Les séparer conduirait à afficher une charge calculée sur un
 * périmètre différent de celui des dossiers listés — l'erreur classique de ce
 * genre d'écran.
 *
 * Trois partis pris :
 *
 * 1. **Seuls les dossiers recevables sont affectables.** Le §11 vient après le
 *    §10 : un dossier dont l'admissibilité n'est pas tranchée n'a rien à faire
 *    dans une file d'évaluation. `ADMISSIBLE` et `IN_EVALUATION` sont donc les
 *    deux seuls statuts admis, et ils sont énumérés, jamais déduits d'un ordre.
 *
 * 2. **La charge se compte en SQL, jamais en PHP.** Un `withCount` contraint
 *    par `released_at IS NULL` donne la charge de tous les évaluateurs en une
 *    requête. Compter par boucle rendrait la page proportionnelle au nombre
 *    d'évaluateurs.
 *
 * 3. **La couverture d'un dossier est un compte, pas un verdict.** C'est
 *    `EvaluationSettings::couvert()` qui tranche, et il peut rendre « on ne
 *    sait pas » quand le minimum n'est pas arrêté.
 */
final readonly class AssignmentBoardQuery
{
    /** Une page d'affectation se travaille par lots courts. */
    public const PER_PAGE = 25;

    /** Tris proposés. Toute autre valeur retombe sur `decouvert`. */
    public const SORTS = ['decouvert', 'ancien', 'nom'];

    public function __construct(
        public ?int $campaignId = null,
        public ?string $search = null,
        public string $sort = 'decouvert',
    ) {}

    /**
     * Les statuts d'un dossier affectable.
     *
     * @return list<ApplicationStatus>
     */
    public static function statutsAffectables(): array
    {
        return [ApplicationStatus::ADMISSIBLE, ApplicationStatus::IN_EVALUATION];
    }

    /** @return LengthAwarePaginator<int, Application> */
    public function paginate(): LengthAwarePaginator
    {
        return $this->builder()->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Le nombre de dossiers affectables, filtres exclus — distingue les deux vides. */
    public static function totalAffectables(): int
    {
        return Application::query()
            ->whereIn('status', array_map(
                static fn (ApplicationStatus $statut): string => $statut->value,
                self::statutsAffectables(),
            ))
            ->count();
    }

    /**
     * Les évaluateurs, avec leur charge courante.
     *
     * Tous les comptes du rôle sont rendus, y compris ceux qui ne portent aucun
     * dossier : c'est précisément l'information utile au responsable qui doit
     * équilibrer. Une liste qui ne montrerait que les évaluateurs déjà chargés
     * cacherait ceux qu'il faut solliciter.
     *
     * @return Collection<int, User>
     */
    public static function evaluateurs(): Collection
    {
        return User::query()
            ->where('role', UserRole::EVALUATOR->value)
            ->withCount([
                'evaluationAssignments as charge_courante' => fn (Builder $q) => $q->whereNull('released_at'),
                'evaluationAssignments as prises_en_charge' => fn (Builder $q) => $q
                    ->whereNull('released_at')
                    ->where('status', AssignmentStatus::ACCEPTED->value),
                'evaluationAssignments as conflits_declares' => fn (Builder $q) => $q
                    ->where('status', AssignmentStatus::CONFLICT->value),
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /**
     * Les évaluateurs déjà en conflit ou déjà affectés sur chaque dossier visé.
     *
     * Sert à ne pas reproposer quelqu'un qui s'est récusé — le §11.1 demande
     * que l'affectation « tienne compte des conflits déclarés ». Une seule
     * requête pour toute la page ; la calculer dossier par dossier coûterait
     * une requête par ligne.
     *
     * @param  list<int>  $dossiers
     * @return array<int, list<int>> Identifiants d'évaluateurs, par dossier.
     */
    public static function evaluateursEcartes(array $dossiers): array
    {
        if ($dossiers === []) {
            return [];
        }

        $ecartes = [];

        $lignes = EvaluationAssignment::query()
            ->whereIn('application_id', $dossiers)
            ->where(fn (Builder $q) => $q
                ->whereNull('released_at')
                ->orWhere('status', AssignmentStatus::CONFLICT->value))
            ->get(['application_id', 'evaluator_id']);

        foreach ($lignes as $ligne) {
            $ecartes[$ligne->application_id][] = $ligne->evaluator_id;
        }

        return array_map('array_unique', $ecartes);
    }

    /** @return Builder<Application> */
    private function builder(): Builder
    {
        $requete = Application::query()
            ->with(['candidate:id,name,email', 'campaign'])
            ->withCount([
                'assignments as affectations_en_vigueur' => fn (Builder $q) => $q->whereNull('released_at'),
            ])
            ->whereIn('status', array_map(
                static fn (ApplicationStatus $statut): string => $statut->value,
                self::statutsAffectables(),
            ));

        if ($this->campaignId !== null) {
            $requete->where('campaign_id', $this->campaignId);
        }

        $this->appliquerRecherche($requete);
        $this->appliquerTri($requete);

        return $requete;
    }

    /** @param Builder<Application> $requete */
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
     * `decouvert` d'abord : un tableau d'affectation sert à trouver ce qui
     * manque, donc les dossiers les moins couverts passent devant. C'est le
     * pendant du FIFO de la file de vérification — l'ordre par défaut d'un
     * écran de travail doit désigner ce qu'il reste à faire.
     *
     * @param  Builder<Application>  $requete
     */
    private function appliquerTri(Builder $requete): void
    {
        match (in_array($this->sort, self::SORTS, strict: true) ? $this->sort : 'decouvert') {
            'ancien' => $requete->orderBy('submitted_at')->orderBy('id'),
            'nom' => $requete
                ->orderBy(User::query()->select('name')->whereColumn('users.id', 'applications.candidate_id'))
                ->orderBy('id'),
            default => $requete->orderBy('affectations_en_vigueur')->orderBy('submitted_at')->orderBy('id'),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function sortOptions(): array
    {
        return [
            ['value' => 'decouvert', 'label' => 'Les moins couverts d’abord'],
            ['value' => 'ancien', 'label' => 'Le plus ancien dépôt d’abord'],
            ['value' => 'nom', 'label' => 'Nom du candidat'],
        ];
    }
}
