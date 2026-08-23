<?php

namespace App\Domain\Eligibility;

/**
 * Résultat complet de l'auto-test : un verdict global et le détail par règle.
 *
 * Rien de tout cela n'est persisté. Le résultat est **dérivé** des réponses
 * enregistrées et des paramètres de la campagne : le recalculer à chaque
 * lecture garantit qu'il reste exact quand le comité de pilotage fixe enfin la
 * tranche d'âge ou les zones. Un verdict figé en base deviendrait faux ce
 * jour-là, sans que rien ne le signale.
 */
final readonly class EligibilityAssessment
{
    /**
     * @param  list<RuleFinding>  $findings
     */
    public function __construct(
        public EligibilityOutcome $outcome,
        public array $findings,
    ) {}

    /**
     * Le verdict global se déduit des verdicts de règle, dans cet ordre.
     *
     * Une règle bloquante l'emporte sur des réponses manquantes : dès qu'on
     * sait qu'une condition n'est pas remplie, le dire tout de suite vaut mieux
     * que de laisser le candidat remplir huit sections pour l'apprendre après.
     * C'est l'« explication en cas de risque d'inéligibilité » du §5.2.
     *
     * @param  list<RuleFinding>  $findings
     */
    public static function fromFindings(array $findings): self
    {
        $statuts = array_map(static fn (RuleFinding $f): RuleStatus => $f->status, $findings);

        $outcome = match (true) {
            in_array(RuleStatus::BLOCKING, $statuts, true) => EligibilityOutcome::INELIGIBLE,
            in_array(RuleStatus::UNANSWERED, $statuts, true) => EligibilityOutcome::INCOMPLETE,
            in_array(RuleStatus::NOT_CONFIGURED, $statuts, true) => EligibilityOutcome::TO_CONFIRM,
            default => EligibilityOutcome::ELIGIBLE,
        };

        return new self($outcome, $findings);
    }

    /** @return list<RuleFinding> */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (RuleFinding $f): bool => $f->status === RuleStatus::BLOCKING,
        ));
    }

    /**
     * @return array{outcome: string, label: string, blocksNextSections: bool, findings: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'label' => $this->outcome->label(),
            'blocksNextSections' => $this->outcome->blocksNextSections(),
            'findings' => array_map(static fn (RuleFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
