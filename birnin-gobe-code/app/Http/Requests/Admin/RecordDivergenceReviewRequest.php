<?php

namespace App\Http\Requests\Admin;

use App\Domain\Evaluation\DivergenceReviewOutcome;
use App\Domain\Evaluation\RecordDivergenceReview;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La saisie d'une revue d'écart (§11.3).
 *
 * Deux champs, et les deux sont obligatoires. Le minimum de quinze caractères
 * n'est pas une contrainte de forme : il écarte le « vu » ou le « ok » qui
 * n'apprend rien à qui relira. Une revue est ce qu'on produira si l'arbitrage
 * est contesté, et « ok » ne défend rien.
 *
 * Le cas d'usage revérifie la longueur : cette classe sert l'écran, elle ne
 * protège pas le domaine. `RecordDivergenceReview` est appelable hors HTTP.
 */
final class RecordDivergenceReviewRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::enum(DivergenceReviewOutcome::class)],
            'reason' => [
                'required',
                'string',
                'min:'.RecordDivergenceReview::REASON_MIN,
                'max:'.RecordDivergenceReview::REASON_MAX,
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'outcome.required' => 'Une revue doit conclure : demander un avis de plus, ou acter le désaccord.',
            'reason.required' => 'Une revue doit être motivée.',
            'reason.min' => 'Expliquez l’arbitrage : c’est cette phrase qui répondra plus tard à « pourquoi ce désaccord a-t-il été jugé acceptable ? ».',
        ];
    }

    public function issue(): DivergenceReviewOutcome
    {
        return DivergenceReviewOutcome::from((string) $this->input('outcome'));
    }
}
