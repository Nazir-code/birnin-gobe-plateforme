<?php

namespace App\Http\Presenters;

use App\Domain\Evaluation\CriterionSpread;
use App\Domain\Evaluation\DivergenceReviewOutcome;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationDivergence;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Met la file et l'écran de revue d'écart en forme — §11.3.
 *
 * **Les notes des évaluateurs sont nominatives, et c'est le sujet.** Comparer
 * deux notations sans savoir de qui elles viennent ne permettrait pas
 * d'arbitrer : le responsable doit pouvoir voir qu'un même évaluateur est
 * systématiquement plus sévère, ce qui est une information de pilotage que le
 * §11.1 lui demande justement de prendre en compte. C'est l'inverse de l'espace
 * évaluateur, où rien d'un collègue n'est visible — l'indépendance protège la
 * notation *pendant* qu'elle se fait, pas après le verrouillage.
 *
 * **Aucune note consolidée.** Ni moyenne, ni médiane, ni classement : le §11.3
 * veut cette règle « choisie et documentée avant l'ouverture », et l'écran ne
 * l'invente pas. Il montre les notes côte à côte et nomme les critères qui
 * divergent ; c'est ce qui permet d'arbitrer, pas un chiffre unique qui
 * masquerait le désaccord sous une moyenne.
 *
 * **Les acteurs sont résolus en une fois**, comme dans `AdminAuditPresenter` :
 * `reviewed_by` n'a pas de clé étrangère — un compte supprimé ne doit pas
 * effacer la trace de son arbitrage — donc aucune relation ne les charge.
 */
final readonly class AdminDivergencePresenter
{
    /** Une ligne de la file. */
    public function row(EvaluationDivergence $divergence): array
    {
        $revue = $divergence->lastReview;

        return [
            ...$divergence->toArray(),
            'campaignName' => $divergence->application->campaign?->name ?? '—',
            'evaluators' => $divergence->evaluations
                ->map(static fn (Evaluation $evaluation): array => [
                    'name' => $evaluation->evaluator?->name ?? '—',
                    'total' => $evaluation->total_score,
                ])
                ->all(),
            'lastReview' => $revue === null ? null : [
                'outcome' => $revue->outcome->value,
                'outcomeLabel' => $revue->outcome->label(),
                'coveredEvaluations' => $revue->covered_evaluations,
                'reviewedAt' => $revue->created_at?->toIso8601String(),
                'stale' => $revue->covered_evaluations < $divergence->lockedCount(),
            ],
            'showUrl' => route('admin.divergences.show', $divergence->application),
        ];
    }

    /**
     * L'écran de revue d'un dossier.
     *
     * @param  Collection<int, EvaluationReview>  $historique
     * @return array<string, mixed>
     */
    public function dossier(EvaluationDivergence $divergence, Collection $historique): array
    {
        $dossier = $divergence->application;
        $divergents = array_map(
            static fn (CriterionSpread $spread): string => $spread->criterion->value,
            $divergence->divergentCriteria(),
        );

        return [
            'application' => [
                'id' => $dossier->getKey(),
                'submissionNumber' => $dossier->submission_number,
                'campaignName' => $dossier->campaign?->name ?? '—',
                'statusLabel' => $dossier->status->label(),
            ],
            'threshold' => $divergence->threshold,
            'reviewDue' => $divergence->reviewDue(),
            'maxGap' => $divergence->maxGap(),
            'totalSpread' => $divergence->totalSpread(),
            'lockedCount' => $divergence->lockedCount(),
            // Une colonne par évaluateur, dans l'ordre de verrouillage : c'est
            // l'ordre dans lequel les avis se sont formés, et il compte quand on
            // se demande qui a pu être influencé par quoi.
            'evaluators' => $divergence->evaluations
                ->map(static fn (Evaluation $evaluation): array => [
                    'id' => $evaluation->evaluator_id,
                    'name' => $evaluation->evaluator?->name ?? '—',
                    'total' => $evaluation->total_score,
                    'recommendation' => $evaluation->recommendation?->label(),
                    'comment' => $evaluation->comment,
                    'lockedAt' => $evaluation->locked_at?->toIso8601String(),
                ])
                ->all(),
            'criteria' => array_map(
                function (EvaluationCriterion $critere) use ($divergence, $divergents): array {
                    $spread = $this->spread($divergence, $critere);

                    return [
                        ...$spread->toArray(),
                        'divergent' => in_array($critere->value, $divergents, strict: true),
                        // La note et sa justification, évaluateur par
                        // évaluateur : c'est la justification qui explique
                        // l'écart, et la lire ailleurs obligerait à recoller
                        // deux écrans.
                        'scores' => $divergence->evaluations
                            ->map(static function (Evaluation $evaluation) use ($critere): array {
                                $ligne = $evaluation->scores->firstWhere('criterion', $critere);

                                return [
                                    'evaluator' => $evaluation->evaluator?->name ?? '—',
                                    'score' => $ligne?->score,
                                    'comment' => $ligne?->comment,
                                ];
                            })
                            ->all(),
                    ];
                },
                EvaluationCriterion::cases(),
            ),
            'reviews' => $this->historique($historique),
            'outcomes' => DivergenceReviewOutcome::options(),
            'limits' => ['maxScore' => EvaluationCriterion::MAX_SCORE],
            'urls' => [
                'store' => route('admin.divergences.store', $dossier),
                'assignments' => route('admin.evaluators.index'),
                'settings' => route('admin.settings.index'),
                'back' => route('admin.divergences.index'),
            ],
        ];
    }

    private function spread(EvaluationDivergence $divergence, EvaluationCriterion $critere): CriterionSpread
    {
        foreach ($divergence->spreads as $spread) {
            if ($spread->criterion === $critere) {
                return $spread;
            }
        }

        return CriterionSpread::make($critere, []);
    }

    /**
     * @param  Collection<int, EvaluationReview>  $historique
     * @return list<array<string, mixed>>
     */
    private function historique(Collection $historique): array
    {
        $acteurs = User::query()
            ->whereKey($historique->pluck('reviewed_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $historique
            ->map(static fn (EvaluationReview $revue): array => [
                'id' => $revue->getKey(),
                'outcome' => $revue->outcome->value,
                'outcomeLabel' => $revue->outcome->label(),
                'reason' => $revue->reason,
                'coveredEvaluations' => $revue->covered_evaluations,
                'observedGap' => $revue->observed_gap,
                // Un compte supprimé laisse sa trace sans son nom : c'est le
                // prix de l'absence de clé étrangère, et c'est le bon prix.
                'actor' => $acteurs[$revue->reviewed_by] ?? null,
                'reviewedAt' => $revue->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
