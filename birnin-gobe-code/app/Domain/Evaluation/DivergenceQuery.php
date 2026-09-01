<?php

namespace App\Domain\Evaluation;

use App\Models\Application;
use App\Models\Campaign;
use App\Models\EvaluationReview;
use Illuminate\Database\Eloquent\Builder;

/**
 * La file des écarts de notation — §11.3.
 *
 * **Ne retient que les dossiers réellement comparables** : au moins deux
 * évaluations verrouillées. Un dossier qui n'en porte qu'une n'a pas d'écart,
 * il a une notation en cours — le faire figurer avec un écart de zéro
 * laisserait croire à un accord parfait entre des gens dont un seul s'est
 * prononcé. Le contraire de ce que la file doit dire.
 *
 * **Le tri est celui de l'urgence** : revues dues d'abord, puis écart
 * décroissant. Un tri par date d'arrivée ferait remonter les dossiers dont on
 * n'a rien à faire.
 *
 * **Le filtrage se fait en mémoire, et c'est assumé.** L'écart se calcule sur
 * les notes critère par critère : l'exprimer en SQL supposerait une jointure
 * croisée sur `evaluation_scores` reconstruisant en base ce que
 * `CriterionSpread` exprime en une ligne — pour une volumétrie qui reste celle
 * d'une campagne, quelques centaines de dossiers dont une fraction est
 * doublement notée. Les évaluations et leurs notes sont chargées en deux
 * requêtes ; c'est le nombre de requêtes qui compte, pas le lieu du calcul.
 */
final readonly class DivergenceQuery
{
    /** @var list<string> */
    public const SCOPES = ['a_revoir', 'revues', 'tous'];

    public function __construct(
        private ?Campaign $campagne,
        private string $scope = 'a_revoir',
    ) {}

    /** @return list<EvaluationDivergence> */
    public function get(): array
    {
        $seuil = EvaluationSettings::fromCampaign($this->campagne)->scoreGapThreshold;

        $dossiers = $this->base()->get();
        $revues = $this->dernieresRevues($dossiers->modelKeys());

        $divergences = $dossiers
            ->map(fn (Application $dossier): EvaluationDivergence => EvaluationDivergence::pour(
                $dossier,
                $seuil,
                $revues[$dossier->getKey()] ?? null,
            ))
            ->filter(static fn (EvaluationDivergence $divergence): bool => $divergence->comparable())
            ->filter(fn (EvaluationDivergence $divergence): bool => $this->retenu($divergence))
            ->values()
            ->all();

        usort(
            $divergences,
            static fn (EvaluationDivergence $a, EvaluationDivergence $b): int => [
                $a->reviewDue() === true ? 0 : 1, -$a->maxGap(),
            ] <=> [
                $b->reviewDue() === true ? 0 : 1, -$b->maxGap(),
            ],
        );

        return $divergences;
    }

    /** Le nombre de dossiers dont la revue est due, pour l'alerte et l'en-tête. */
    public static function totalARevoir(?Campaign $campagne): int
    {
        return count((new self($campagne, 'a_revoir'))->get());
    }

    /** @return Builder<Application> */
    private function base(): Builder
    {
        return Application::query()
            ->when($this->campagne !== null, fn (Builder $q) => $q->where('campaign_id', $this->campagne->getKey()))
            // Au moins deux avis arrêtés : le filtre grossier est fait en base,
            // le calcul fin en mémoire.
            ->whereHas(
                'evaluations',
                fn (Builder $q) => $q->where('status', EvaluationStatus::LOCKED->value),
                '>=',
                2,
            )
            ->with([
                'campaign',
                'evaluations' => fn ($q) => $q
                    ->where('status', EvaluationStatus::LOCKED->value)
                    ->with(['scores', 'evaluator:id,name']),
            ])
            ->orderBy('id');
    }

    /**
     * La dernière revue de chaque dossier, en une requête.
     *
     * @param  list<int>  $dossiers
     * @return array<int, EvaluationReview>
     */
    private function dernieresRevues(array $dossiers): array
    {
        if ($dossiers === []) {
            return [];
        }

        return EvaluationReview::query()
            ->whereIn('application_id', $dossiers)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            // La dernière écrase les précédentes : `keyBy` sur une collection
            // triée croissante laisse la plus récente.
            ->keyBy('application_id')
            ->all();
    }

    private function retenu(EvaluationDivergence $divergence): bool
    {
        return match ($this->scope) {
            'a_revoir' => $divergence->reviewDue() === true,
            'revues' => $divergence->lastReview !== null,
            default => true,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function scopeOptions(): array
    {
        return [
            ['value' => 'a_revoir', 'label' => 'Revue due'],
            ['value' => 'revues', 'label' => 'Déjà revus'],
            ['value' => 'tous', 'label' => 'Tous les dossiers comparables'],
        ];
    }
}
