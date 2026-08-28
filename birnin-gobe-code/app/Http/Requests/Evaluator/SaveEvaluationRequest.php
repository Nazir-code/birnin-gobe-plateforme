<?php

namespace App\Http\Requests\Evaluator;

use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\SaveEvaluationDraft;
use App\Domain\Evaluation\ScoreSheet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La saisie de la grille de notation — §11.2, §11.3.
 *
 * **Cette classe valide la forme, jamais la complétude.** Une feuille à moitié
 * remplie est une saisie valide : elle s'enregistre, et c'est le verrouillage
 * qui exige les huit notes, les justifications des notes extrêmes et la
 * recommandation. Refuser ici un brouillon incomplet obligerait à noter un
 * dossier en une seule séance, ou à perdre son travail.
 *
 * Ce qui est refusé, en revanche, l'est fermement : une note hors de l'échelle
 * 0–5 du §11.3, un critère inconnu, une recommandation inventée. Ce ne sont pas
 * des saisies partielles, ce sont des saisies fausses, et les réduire
 * silencieusement écrirait autre chose que ce que l'évaluateur croit avoir
 * saisi.
 *
 * `''` est traduit en `null` plutôt que refusé : c'est ce que rend un champ
 * numérique qu'on vide, et vider une note est un geste légitime — c'est ainsi
 * qu'on revient sur un critère qu'on veut relire.
 */
final class SaveEvaluationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $lignes = [];

        foreach ((array) $this->input('scores', []) as $rang => $saisie) {
            $note = $saisie['score'] ?? null;

            $lignes[$rang] = [
                'criterion' => $saisie['criterion'] ?? null,
                // Un champ numérique vidé rend `''` : c'est « pas noté », pas
                // une saisie illisible.
                'score' => ($note === '' || $note === null) ? null : $note,
                'comment' => $saisie['comment'] ?? null,
            ];
        }

        $this->merge([
            'scores' => $lignes,
            'recommendation' => $this->input('recommendation') === '' ? null : $this->input('recommendation'),
        ]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'scores' => ['present', 'array'],
            'scores.*.criterion' => ['required', Rule::enum(EvaluationCriterion::class)],
            'scores.*.score' => ['nullable', 'integer', 'min:0', 'max:'.EvaluationCriterion::MAX_SCORE],
            'scores.*.comment' => ['nullable', 'string', 'max:'.SaveEvaluationDraft::CRITERION_COMMENT_MAX],
            'recommendation' => ['nullable', Rule::enum(EvaluationRecommendation::class)],
            'comment' => ['nullable', 'string', 'max:'.SaveEvaluationDraft::COMMENT_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scores.*.score.min' => 'L’échelle du §11.3 va de 0 à '.EvaluationCriterion::MAX_SCORE.'.',
            'scores.*.score.max' => 'L’échelle du §11.3 va de 0 à '.EvaluationCriterion::MAX_SCORE.'.',
        ];
    }

    /**
     * La feuille, indexée par critère.
     *
     * L'indexation dédoublonne au passage une saisie qui enverrait deux fois le
     * même critère — propriété de la requête, pas du domaine.
     */
    public function feuille(): ScoreSheet
    {
        $lignes = [];

        foreach ((array) $this->input('scores', []) as $saisie) {
            $critere = EvaluationCriterion::tryFrom((string) ($saisie['criterion'] ?? ''));

            if ($critere === null) {
                continue;
            }

            $note = $saisie['score'] ?? null;

            $lignes[$critere->value] = [
                'score' => $note === null ? null : (int) $note,
                'comment' => $saisie['comment'] ?? null,
            ];
        }

        return ScoreSheet::make($lignes);
    }

    public function recommandation(): ?EvaluationRecommendation
    {
        return EvaluationRecommendation::tryFrom((string) $this->input('recommendation'));
    }
}
