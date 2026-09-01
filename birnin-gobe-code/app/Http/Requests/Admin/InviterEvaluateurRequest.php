<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Création d'un évaluateur depuis le back-office — ADR-022.
 *
 * **Deux champs, et pas un de plus.** Ni mot de passe — l'invité le définira
 * lui-même — ni rôle : celui-ci est imposé par le contrôleur, et l'accepter
 * ici ferait dépendre un privilège d'une donnée de formulaire. C'est la règle
 * d'ADR-006, et elle ne s'assouplit pas parce que l'expéditeur est
 * administrateur.
 *
 * Les mêmes règles d'adresse que partout ailleurs, `unique` compris : la
 * création ne promeut pas un compte existant, pas plus ici qu'en ligne de
 * commande.
 */
final class InviterEvaluateurRequest extends FormRequest
{
    /** L'autorisation est portée par le middleware `role:admin` de la route. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalisée avant validation : la règle `lowercase` refuserait
        // « Ibrahim@… », alors que l'intention est sans ambiguïté.
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom de l’évaluateur est obligatoire.',
            'name.max' => 'Le nom ne doit pas dépasser 120 caractères.',
            'email.required' => 'L’adresse e-mail est obligatoire : c’est là que part l’invitation.',
            'email.email' => 'L’adresse e-mail n’est pas valide.',
            'email.unique' => 'Un compte existe déjà avec cette adresse. La création n’attribue pas le rôle d’évaluateur à un compte existant.',
        ];
    }
}
