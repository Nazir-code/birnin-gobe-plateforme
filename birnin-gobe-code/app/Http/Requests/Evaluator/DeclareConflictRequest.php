<?php

namespace App\Http\Requests\Evaluator;

use Illuminate\Foundation\Http\FormRequest;

/**
 * La récusation d'un évaluateur sur un dossier — §11.1.
 *
 * **Le motif est obligatoire, et c'est le seul champ.** Une récusation sans
 * explication est indéfendable : le responsable qui reprend le dossier doit
 * savoir s'il s'agit d'un lien personnel, d'un intérêt financier ou d'une
 * participation antérieure au projet — les trois n'appellent pas la même suite.
 *
 * Le minimum de dix caractères n'est pas une coquetterie : il écarte le « oui »
 * ou le « conflit » qui ne dit rien, sans exiger un rapport. Le geste est
 * définitif — un dossier récusé ne sera plus reproposé à cette personne — et
 * une décision définitive se motive.
 */
final class DeclareConflictRequest extends FormRequest
{
    private const REASON_MIN = 10;

    private const REASON_MAX = 2000;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.self::REASON_MIN, 'max:'.self::REASON_MAX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Une récusation doit être motivée.',
            'reason.min' => 'Précisez la nature du conflit : le responsable doit pouvoir décider de la suite.',
        ];
    }
}
