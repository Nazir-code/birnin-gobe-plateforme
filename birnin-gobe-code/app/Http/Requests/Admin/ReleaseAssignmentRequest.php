<?php

namespace App\Http\Requests\Admin;

use App\Domain\Evaluation\AssignmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation serveur de la levée d'une affectation (§11.1).
 *
 * Le motif écrit est obligatoire, et ce n'est pas une formalité : une
 * affectation retirée sans explication ne peut être comprise ni par
 * l'évaluateur qui la perd, ni par le responsable suivant qui reprendra le
 * dossier. C'est la même exigence que l'observation d'un contrôle
 * d'admissibilité.
 *
 * Le statut demandé doit être une *levée*. `ASSIGNED` et `ACCEPTED` sont des
 * états d'affectation en vigueur : les accepter ici reviendrait à « lever » une
 * affectation en la laissant active. La liste blanche est dérivée de
 * `compteDansLaCouverture()`, jamais recopiée — ajouter demain un état de levée
 * ne doit pas obliger à retrouver cette ligne.
 */
final class ReleaseAssignmentRequest extends FormRequest
{
    private const REASON_MAX = 1000;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in($this->motifsAdmis())],
            'reason' => ['required', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                // `required` accepte une chaîne d'espaces ; le domaine, non.
                // Sans ce contrôle, l'écran renverrait une erreur générique
                // venue du `DomainException` au lieu d'un message de champ.
                if (trim((string) $this->input('reason')) === '') {
                    $validateur->errors()->add('reason', 'Un motif écrit est exigé pour lever une affectation.');
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Un motif écrit est exigé pour lever une affectation.',
            'status.in' => 'Ce motif ne lève pas une affectation.',
        ];
    }

    public function motif(): AssignmentStatus
    {
        return AssignmentStatus::from((string) $this->input('status'));
    }

    public function raison(): string
    {
        return trim((string) $this->input('reason'));
    }

    /** @return list<string> */
    private function motifsAdmis(): array
    {
        return array_values(array_map(
            static fn (AssignmentStatus $statut): string => $statut->value,
            array_filter(
                AssignmentStatus::cases(),
                static fn (AssignmentStatus $statut): bool => ! $statut->compteDansLaCouverture(),
            ),
        ));
    }
}
