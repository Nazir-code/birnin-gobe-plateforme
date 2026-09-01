<?php

namespace App\Http\Requests\Admin;

use App\Domain\Evaluation\EvaluationSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur des paramètres d'évaluation (§9.2).
 *
 * Même règle qu'ADR-007, reprise de `SaveEligibilitySettingsRequest` : **le
 * vide n'est pas une valeur**. Un champ laissé vide n'est pas « zéro
 * évaluation » ni « aucun seuil » — il est absent, donc le paramètre reste non
 * arrêté, et la couverture des dossiers reste *inconnue* plutôt que réputée
 * insuffisante.
 *
 * Les bornes hautes ne sont pas des règles métier : ce sont les limites
 * au-delà desquelles une saisie est forcément une faute de frappe. Dix
 * évaluations par dossier n'est pas une politique, c'est un zéro de trop.
 */
final class SaveEvaluationSettingsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'min_evaluations' => ['nullable', 'integer', 'min:1', 'max:'.EvaluationSettings::MAX_EVALUATIONS],
            // L'échelle du §11.3 va de 0 à 5 : un écart ne peut pas la dépasser.
            'score_gap_threshold' => ['nullable', 'numeric', 'min:0', 'max:'.EvaluationSettings::MAX_SCORE_GAP],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'min_evaluations.max' => 'Au-delà de '.EvaluationSettings::MAX_EVALUATIONS.' évaluations par dossier, il s’agit d’une faute de frappe.',
            'score_gap_threshold.max' => 'L’échelle de notation va de 0 à '.EvaluationSettings::MAX_SCORE_GAP.' : un écart ne peut pas la dépasser.',
        ];
    }

    public function reglages(): EvaluationSettings
    {
        return EvaluationSettings::make(
            minEvaluations: $this->entier('min_evaluations'),
            scoreGapThreshold: $this->reel('score_gap_threshold'),
        );
    }

    private function entier(string $champ): ?int
    {
        $valeur = $this->input($champ);

        return is_numeric($valeur) ? (int) $valeur : null;
    }

    private function reel(string $champ): ?float
    {
        $valeur = $this->input($champ);

        return is_numeric($valeur) ? (float) $valeur : null;
    }
}
