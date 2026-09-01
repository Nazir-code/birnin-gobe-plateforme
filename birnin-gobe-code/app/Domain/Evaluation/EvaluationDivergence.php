<?php

namespace App\Domain\Evaluation;

use App\Models\Application;
use App\Models\Evaluation;
use App\Models\EvaluationReview;
use Illuminate\Support\Collection;

/**
 * L'état de divergence d'un dossier — §11.3.
 *
 * Rassemble ce qu'il faut pour décider si une revue est due : les évaluations
 * **verrouillées** du dossier, l'écart par critère, et la dernière revue
 * enregistrée.
 *
 * **Rien n'est persisté ici.** La divergence est un calcul sur l'état réel,
 * comme les alertes d'ADR-014 : la stocker obligerait à la lever quand une
 * évaluation supplémentaire arrive, donc à écrire un mécanisme d'extinction.
 * Ce qui est persisté, c'est la revue — l'acte humain — et non son motif.
 *
 * **Une revue vaut pour l'état qu'elle a vu.** `EvaluationReview` retient le
 * nombre d'évaluations verrouillées au moment où elle a été écrite ; une
 * évaluation de plus, et la revue redevient due. C'est ce qui évite le piège
 * de l'acquittement définitif qu'ADR-014 refuse pour les alertes : on ne fait
 * pas taire un écart, on le revoit tel qu'il est.
 *
 * **Aucune note consolidée n'est calculée.** Le §11.3 veut que « la moyenne,
 * médiane ou note de consensus soit choisie et documentée avant l'ouverture » :
 * tant que ce choix n'est pas arrêté, produire un chiffre unique donnerait un
 * classement fondé sur une règle que personne n'a décidée. L'écran montre les
 * notes côte à côte ; il ne les résume pas.
 */
final readonly class EvaluationDivergence
{
    /**
     * @param  list<CriterionSpread>  $spreads
     * @param  Collection<int, Evaluation>  $evaluations  verrouillées, par ordre de verrouillage
     */
    private function __construct(
        public Application $application,
        public Collection $evaluations,
        public array $spreads,
        public ?float $threshold,
        public ?EvaluationReview $lastReview,
    ) {}

    public static function pour(Application $dossier, ?float $seuil, ?EvaluationReview $derniereRevue = null): self
    {
        $verrouillees = $dossier->evaluations
            ->filter(static fn (Evaluation $evaluation): bool => $evaluation->estVerrouillee())
            ->sortBy('locked_at')
            ->values();

        $spreads = array_map(
            static fn (EvaluationCriterion $critere): CriterionSpread => CriterionSpread::make(
                $critere,
                $verrouillees
                    ->map(static fn (Evaluation $evaluation): ?int => $evaluation->scores
                        ->firstWhere('criterion', $critere)?->score)
                    ->all(),
            ),
            EvaluationCriterion::cases(),
        );

        return new self($dossier, $verrouillees, $spreads, $seuil, $derniereRevue);
    }

    /** Une divergence suppose au moins deux avis arrêtés. */
    public function comparable(): bool
    {
        return $this->evaluations->count() >= 2;
    }

    public function lockedCount(): int
    {
        return $this->evaluations->count();
    }

    /** Le plus grand écart constaté, sur l'échelle 0–5. */
    public function maxGap(): int
    {
        return $this->comparable()
            ? max(array_map(static fn (CriterionSpread $spread): int => $spread->gap(), $this->spreads))
            : 0;
    }

    /**
     * Les critères dont l'écart dépasse le seuil.
     *
     * @return list<CriterionSpread>
     */
    public function divergentCriteria(): array
    {
        if ($this->threshold === null || ! $this->comparable()) {
            return [];
        }

        return array_values(array_filter(
            $this->spreads,
            fn (CriterionSpread $spread): bool => $spread->exceeds($this->threshold) === true,
        ));
    }

    /**
     * Une revue est-elle due ?
     *
     * `null` tant qu'aucun seuil n'est arrêté : sans seuil, un écart n'est ni
     * acceptable ni excessif, il est simplement non comparé.
     */
    public function reviewDue(): ?bool
    {
        if ($this->threshold === null || ! $this->comparable()) {
            return $this->threshold === null ? null : false;
        }

        if ($this->divergentCriteria() === []) {
            return false;
        }

        // Une revue antérieure ne vaut que pour l'état qu'elle a vu.
        return $this->lastReview === null
            || $this->lastReview->covered_evaluations < $this->lockedCount();
    }

    /** L'écart entre la meilleure et la moins bonne note globale. Contexte, pas déclencheur. */
    public function totalSpread(): ?float
    {
        if (! $this->comparable()) {
            return null;
        }

        $totaux = $this->evaluations
            ->map(static fn (Evaluation $evaluation): ?float => $evaluation->total_score)
            ->filter(static fn (?float $total): bool => $total !== null);

        return $totaux->count() < 2 ? null : round($totaux->max() - $totaux->min(), 2);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applicationId' => $this->application->getKey(),
            'submissionNumber' => $this->application->submission_number,
            'statusLabel' => $this->application->status->label(),
            'lockedCount' => $this->lockedCount(),
            'maxGap' => $this->maxGap(),
            'totalSpread' => $this->totalSpread(),
            'threshold' => $this->threshold,
            'reviewDue' => $this->reviewDue(),
            'divergentCriteria' => array_map(
                static fn (CriterionSpread $spread): array => $spread->toArray(),
                $this->divergentCriteria(),
            ),
        ];
    }
}
