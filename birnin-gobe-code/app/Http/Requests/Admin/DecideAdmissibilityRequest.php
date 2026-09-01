<?php

namespace App\Http\Requests\Admin;

use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\VerificationControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation serveur de la décision d'admissibilité (§10.3).
 *
 * Cette classe vérifie la **forme** de la décision : les champs que le §10.3
 * exige sont-ils là, dans le bon référentiel, avec des dates qui tiennent. Elle
 * ne vérifie pas la **cohérence** — qu'un motif de rejet corresponde à un
 * contrôle réellement bloquant, que la grille soit complète, qu'aucun blocage
 * ne subsiste sous une recevabilité. Ces trois-là dépendent de l'état du
 * dossier au moment de l'écriture, et se relisent donc sous verrou dans
 * `DecideAdmissibility` : ce qui a été affiché n'engage pas la décision.
 *
 * Les règles conditionnelles reprennent mot pour mot celles de l'enum
 * (`requiresPrimaryReason`, `requiresRespondBy`, `requiresCandidateMessage`)
 * plutôt que de les redire en `required_if` figés : ajouter une quatrième
 * décision demain ne doit pas obliger à retrouver ces trois lignes.
 */
final class DecideAdmissibilityRequest extends FormRequest
{
    private const NOTE_MAX = 2000;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $decision = $this->decision();

        return [
            'decision' => ['required', Rule::enum(AdmissibilityDecision::class)],

            // Les motifs sont des contrôles du §10.2 — aucun second référentiel.
            'primary_reason' => [
                $decision?->requiresPrimaryReason() ? 'required' : 'nullable',
                Rule::enum(VerificationControl::class),
            ],
            'secondary_reason' => ['nullable', 'different:primary_reason', Rule::enum(VerificationControl::class)],

            'internal_note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],

            // Le §10.3 veut un message au candidat distinct de l'observation
            // interne. « Distinct » est ici structurel : deux champs, deux
            // colonnes, et l'écran ne recopie pas l'un dans l'autre.
            'candidate_message' => [
                $decision?->requiresCandidateMessage() ? 'required' : 'nullable',
                'string',
                'max:'.self::NOTE_MAX,
            ],

            // `after_or_equal:today` : une clarification peut être demandée pour
            // le jour même — c'est court, mais ce n'est pas une date passée.
            'respond_by' => [
                $decision?->requiresRespondBy() ? 'required' : 'nullable',
                'date_format:Y-m-d',
                'after_or_equal:today',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'primary_reason.required' => 'Un rejet doit porter un motif principal (§10.3).',
            'candidate_message.required' => 'Le candidat doit recevoir un message, distinct de l’observation interne.',
            'respond_by.required' => 'Une demande de clarification doit fixer une date limite de réponse.',
            'respond_by.after_or_equal' => 'La date limite ne peut pas être passée.',
            'secondary_reason.different' => 'Le motif secondaire doit différer du motif principal.',
        ];
    }

    public function decision(): ?AdmissibilityDecision
    {
        return AdmissibilityDecision::tryFrom((string) $this->input('decision'));
    }

    public function primaryReason(): ?VerificationControl
    {
        return VerificationControl::tryFrom((string) $this->input('primary_reason'));
    }

    public function secondaryReason(): ?VerificationControl
    {
        return VerificationControl::tryFrom((string) $this->input('secondary_reason'));
    }

    public function respondBy(): ?string
    {
        $valeur = trim((string) $this->input('respond_by'));

        return $valeur === '' ? null : $valeur;
    }
}
