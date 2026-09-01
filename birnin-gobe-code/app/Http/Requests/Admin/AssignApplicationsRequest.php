<?php

namespace App\Http\Requests\Admin;

use App\Domain\Auth\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation serveur d'une affectation en lot (§11.1).
 *
 * On refuse, on ne réduit pas : une affectation partiellement retenue laisserait
 * le responsable croire qu'il a réparti vingt dossiers alors que dix-sept sont
 * partis. Le lot est accepté en entier ou refusé en entier — c'est aussi ce que
 * garantit la transaction du cas d'usage.
 *
 * Le rôle du destinataire est vérifié **ici** par une règle d'existence
 * conditionnée, et **de nouveau** dans `AssignApplications` : la première rend
 * un message de formulaire, la seconde protège le domaine, qui est appelable
 * hors HTTP.
 */
final class AssignApplicationsRequest extends FormRequest
{
    /** Un lot plus gros qu'une page d'affectation est une erreur de manipulation. */
    private const MAX_LOT = 100;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'evaluator_id' => [
                'required',
                'integer',
                // La règle porte le rôle : un identifiant d'administrateur ou de
                // candidat ne « existe » pas au sens de ce formulaire.
                Rule::exists('users', 'id')->where('role', UserRole::EVALUATOR->value),
            ],
            'application_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_LOT],
            'application_ids.*' => ['integer', 'distinct', Rule::exists('applications', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'evaluator_id.exists' => 'Ce compte n’est pas un évaluateur.',
            'application_ids.required' => 'Choisissez au moins un dossier à affecter.',
            'application_ids.max' => 'Un lot d’affectation ne dépasse pas '.self::MAX_LOT.' dossiers.',
        ];
    }

    /** @return list<int> */
    public function dossiers(): array
    {
        return array_values(array_map('intval', (array) $this->input('application_ids', [])));
    }

    public function evaluateurId(): int
    {
        return (int) $this->input('evaluator_id');
    }
}
