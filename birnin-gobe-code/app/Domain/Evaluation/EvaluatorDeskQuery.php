<?php

namespace App\Domain\Evaluation;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Models\ApplicationSectionAnswers;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le plan de travail d'un évaluateur — §11.1.
 *
 * **Ne rend que les affectations en vigueur de l'évaluateur qui interroge.**
 * L'identifiant n'est jamais lu dans la requête HTTP : le passer en paramètre
 * ferait de la file de chacun une URL devinable, et l'espace évaluateur est
 * précisément celui où l'indépendance des notations doit être étanche.
 *
 * **Les affectations levées ne figurent pas.** Un dossier retiré ou dont
 * l'évaluateur s'est récusé n'a plus à s'ouvrir : le garder visible « pour
 * mémoire » donnerait un accès en lecture à un dossier qu'on a justement cessé
 * de pouvoir juger. La trace, elle, reste en base et dans le journal, du côté
 * de l'administration.
 *
 * **L'ordre est celui de l'urgence, pas de l'affectation.** Les dossiers à
 * traiter d'abord, les verrouillés à la fin ; à l'intérieur, le plus ancien
 * d'abord. Une file ordonnée par date d'affectation ferait remonter en tête ce
 * qui vient d'arriver.
 *
 * La thématique est extraite par sous-requête scalaire, comme dans
 * `ApplicationIndexQuery` : la liste l'affiche, mais n'a que faire des récits
 * de la section « Défi » qui l'accompagnent.
 */
final readonly class EvaluatorDeskQuery
{
    public function __construct(private User $evaluateur) {}

    /** @return Collection<int, EvaluationAssignment> */
    public function get(): Collection
    {
        return $this->base()->get();
    }

    /** @return Builder<EvaluationAssignment> */
    private function base(): Builder
    {
        return EvaluationAssignment::query()
            ->where('evaluator_id', $this->evaluateur->getKey())
            ->enVigueur()
            ->with([
                'application' => fn ($q) => $q
                    ->with('campaign')
                    ->addSelect(['project_theme' => ApplicationSectionAnswers::query()
                        ->selectRaw("answers->>'".ChallengeSection::THEME_FIELD."'")
                        ->whereColumn('application_id', 'applications.id')
                        ->where('section', ApplicationSection::CHALLENGE->value)
                        ->limit(1),
                    ]),
                'evaluation.scores',
            ])
            // Les dossiers encore à traiter d'abord : une évaluation
            // verrouillée n'appelle plus aucun geste.
            ->orderByRaw(
                'CASE WHEN EXISTS ('
                .'SELECT 1 FROM evaluations e WHERE e.evaluation_assignment_id = evaluation_assignments.id'
                .' AND e.status = ?) THEN 1 ELSE 0 END',
                [EvaluationStatus::LOCKED->value],
            )
            ->orderBy('assigned_at')
            ->orderBy('id');
    }

    /**
     * Ce qui reste à faire, pour le compteur de l'en-tête.
     *
     * Compte les affectations en vigueur dont l'évaluation n'est pas
     * verrouillée — y compris celles dont la charte n'a pas encore été
     * acceptée, qui sont justement celles qu'on oublie.
     */
    public function restantes(): int
    {
        return EvaluationAssignment::query()
            ->where('evaluator_id', $this->evaluateur->getKey())
            ->enVigueur()
            ->whereDoesntHave('evaluation', fn (Builder $q) => $q->where('status', EvaluationStatus::LOCKED->value))
            ->count();
    }
}
