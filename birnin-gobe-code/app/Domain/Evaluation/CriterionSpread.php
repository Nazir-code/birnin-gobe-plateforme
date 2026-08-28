<?php

namespace App\Domain\Evaluation;

/**
 * L'écart entre évaluateurs sur un critère — §11.3.
 *
 * Le §11.3 dit « écart supérieur à un seuil configurable déclenche une revue »
 * sans dire écart entre quoi. Deux lectures étaient possibles, et le choix
 * décide de ce que la revue permet de faire :
 *
 *  - **l'écart entre les notes globales sur 100** dit qu'on n'est pas d'accord,
 *    mais pas sur quoi. Vingt points d'écart peuvent venir d'un désaccord franc
 *    sur la faisabilité technique comme de huit petits désaccords sans objet ;
 *  - **l'écart critère par critère, sur l'échelle 0–5**, nomme le désaccord.
 *    Deux évaluateurs qui mettent 1 et 5 sur « Faisabilité technique » ne
 *    lisent pas le même dossier, et c'est cette phrase-là qu'une revue doit
 *    pouvoir écrire.
 *
 * C'est la seconde qui est retenue, et l'échelle du seuil déjà enregistré la
 * confirme : `EvaluationSettings::MAX_SCORE_GAP` vaut 5 parce que le réglage
 * a toujours porté sur l'échelle du §11.3, pas sur le total. L'écart des
 * totaux reste calculé, mais comme contexte de lecture — jamais comme
 * déclencheur.
 */
final readonly class CriterionSpread
{
    private function __construct(
        public EvaluationCriterion $criterion,
        public int $min,
        public int $max,
        /** Nombre d'évaluations verrouillées ayant noté ce critère. */
        public int $count,
    ) {}

    /**
     * @param  list<int>  $notes  les notes verrouillées de ce critère
     */
    public static function make(EvaluationCriterion $critere, array $notes): self
    {
        $valeurs = array_values(array_filter($notes, static fn (mixed $n): bool => is_int($n)));

        // Une feuille verrouillée est forcément complète : ce cas ne devrait
        // pas exister. On rend un écart nul plutôt que de diviser par zéro —
        // un écran de pilotage n'a pas à tomber sur une donnée aberrante.
        if ($valeurs === []) {
            return new self($critere, 0, 0, 0);
        }

        return new self($critere, min($valeurs), max($valeurs), count($valeurs));
    }

    public function gap(): int
    {
        return $this->max - $this->min;
    }

    /**
     * L'écart dépasse-t-il le seuil arrêté ?
     *
     * Rend `null` — et non `false` — quand aucun seuil n'a été fixé. « On ne
     * sait pas » et « l'écart est acceptable » n'appellent pas la même suite,
     * et colorer en vert un écart jamais comparé à rien serait un verdict
     * inventé. Même règle qu'ADR-007.
     */
    public function exceeds(?float $seuil): ?bool
    {
        return $seuil === null ? null : $this->gap() > $seuil;
    }

    /** @return array{criterion: string, label: string, weight: int, min: int, max: int, gap: int, count: int} */
    public function toArray(): array
    {
        return [
            'criterion' => $this->criterion->value,
            'label' => $this->criterion->label(),
            'weight' => $this->criterion->weight(),
            'min' => $this->min,
            'max' => $this->max,
            'gap' => $this->gap(),
            'count' => $this->count,
        ];
    }
}
