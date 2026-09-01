<?php

namespace App\Domain\Evaluation;

use App\Models\Campaign;

/**
 * Paramètres d'évaluation d'une campagne — §9.2, ligne « Évaluation ».
 *
 * Lecture seule ici. Le §9.2 énumère « phases, critères, sous-critères, poids,
 * échelles, seuils, nombre d'évaluateurs, agrégation et règles d'égalité » ;
 * cette classe n'expose que les deux paramètres dont l'affectation (§11.1) a
 * réellement besoin aujourd'hui :
 *
 *  - le **nombre minimal d'évaluations** par dossier ;
 *  - le **seuil d'écart** au-delà duquel le §11.3 demande une revue.
 *
 * Les critères et leurs poids ne sont pas ici : le §11.2 en donne une grille
 * précise, mais tant qu'aucune notation n'existe, les rendre configurables
 * reviendrait à publier un réglage que rien ne lit.
 *
 * **Même règle qu'ADR-007 : un paramètre non renseigné n'a pas de valeur par
 * défaut.** `minEvaluations` vaut `null` tant que le comité ne l'a pas arrêté,
 * et la couverture d'un dossier est alors *inconnue*, pas *nulle*. Inventer
 * « deux évaluations » afficherait une couverture rassurante calculée sur un
 * seuil que personne n'a décidé.
 */
final readonly class EvaluationSettings
{
    /** Clé du bloc dans `campaigns.settings`. */
    public const KEY = 'evaluation';

    /** Au-delà, la saisie est une faute de frappe, pas une exigence. */
    public const MAX_EVALUATIONS = 10;

    /** L'échelle du §11.3 va de 0 à 5 ; un écart ne peut pas la dépasser. */
    public const MAX_SCORE_GAP = 5;

    private function __construct(
        public ?int $minEvaluations,
        public ?float $scoreGapThreshold,
    ) {}

    public static function fromCampaign(?Campaign $campagne): self
    {
        $brut = is_array($campagne?->settings[self::KEY] ?? null) ? $campagne->settings[self::KEY] : [];

        return new self(
            minEvaluations: self::entier($brut['min_evaluations'] ?? null, 1, self::MAX_EVALUATIONS),
            scoreGapThreshold: self::reel($brut['score_gap_threshold'] ?? null, 0, self::MAX_SCORE_GAP),
        );
    }

    public static function make(?int $minEvaluations, ?float $scoreGapThreshold): self
    {
        return new self(
            minEvaluations: self::entier($minEvaluations, 1, self::MAX_EVALUATIONS),
            scoreGapThreshold: self::reel($scoreGapThreshold, 0, self::MAX_SCORE_GAP),
        );
    }

    /**
     * La forme stockée. Une clé absente vaut « non arrêté » — jamais `null`.
     *
     * @return array<string, mixed>
     */
    public function toStoredArray(): array
    {
        $stocke = [];

        if ($this->minEvaluations !== null) {
            $stocke['min_evaluations'] = $this->minEvaluations;
        }

        if ($this->scoreGapThreshold !== null) {
            $stocke['score_gap_threshold'] = $this->scoreGapThreshold;
        }

        return $stocke;
    }

    /**
     * @return array{minEvaluations: int|null, scoreGapThreshold: float|null, configured: bool}
     */
    public function toArray(): array
    {
        return [
            'minEvaluations' => $this->minEvaluations,
            'scoreGapThreshold' => $this->scoreGapThreshold,
            'configured' => $this->minEvaluations !== null,
        ];
    }

    /**
     * La couverture d'un dossier, dite honnêtement.
     *
     * Rend `null` — et non `false` — quand le minimum n'est pas arrêté : « on ne
     * sait pas » et « ce n'est pas couvert » n'appellent pas la même action de
     * la part du responsable.
     */
    public function couvert(int $affectations): ?bool
    {
        return $this->minEvaluations === null ? null : $affectations >= $this->minEvaluations;
    }

    private static function entier(mixed $valeur, int $min, int $max): ?int
    {
        if (! is_numeric($valeur)) {
            return null;
        }

        $entier = (int) $valeur;

        return $entier >= $min && $entier <= $max ? $entier : null;
    }

    private static function reel(mixed $valeur, float $min, float $max): ?float
    {
        if (! is_numeric($valeur)) {
            return null;
        }

        $reel = (float) $valeur;

        return $reel >= $min && $reel <= $max ? $reel : null;
    }
}
