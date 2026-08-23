<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\ProfileSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Profil du candidat ».
 *
 * React propose des listes et un clavier téléphonique, mais rien de cela
 * n'engage : la requête peut être forgée. Ce qui entre en base est ce que cette
 * classe accepte, et seulement les champs déclarés par `ProfileSection` — un
 * `name`, un `email` ou un `birth_date` glissés dans la charge utile sont
 * ignorés, jamais enregistrés. Ces trois données ont leur propre source de
 * vérité (le compte, la section « Éligibilité ») et cette section ne les
 * réécrit pas.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveProfileSectionRequest extends FormRequest
{
    /**
     * Normalisation avant validation.
     *
     * Les numéros sont ramenés à leur forme internationale ici, si bien que la
     * règle de format porte sur la valeur qui sera réellement stockée, et que
     * le message d'erreur éventuel décrit ce que le serveur a compris.
     */
    protected function prepareForValidation(): void
    {
        $normalises = [];

        foreach ([ProfileSection::PHONE_PRIMARY, ProfileSection::PHONE_SECONDARY] as $champ) {
            if ($this->has($champ)) {
                $valeur = $this->input($champ);
                $normalises[$champ] = is_string($valeur) ? ProfileSection::normalizePhone($valeur) : $valeur;
            }
        }

        if ($normalises !== []) {
            $this->merge($normalises);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ProfileSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            ProfileSection::BIRTH_PLACE => 'lieu de naissance',
            ProfileSection::GENDER => 'sexe',
            ProfileSection::PHONE_PRIMARY => 'téléphone principal',
            ProfileSection::PHONE_SECONDARY => 'téléphone secondaire',
            ProfileSection::PREFERRED_CHANNEL => 'canal de communication préféré',
            ProfileSection::RESIDENCE_REGION => 'région de résidence',
            ProfileSection::RESIDENCE_LOCALITY => 'quartier ou village',
            ProfileSection::OCCUPATION => 'occupation principale',
            ProfileSection::EDUCATION_LEVEL => 'niveau d’études',
            ProfileSection::SPECIALTY => 'spécialité',
            ProfileSection::ACCESSIBILITY_NEED => 'besoin d’accessibilité',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ProfileSection::PHONE_PRIMARY.'.regex' => 'Indiquez un numéro joignable, par exemple 90 12 34 56 ou +227 90 12 34 56.',
            ProfileSection::PHONE_SECONDARY.'.regex' => 'Indiquez un numéro joignable, par exemple 90 12 34 56 ou +227 90 12 34 56.',
            ProfileSection::PHONE_SECONDARY.'.different' => 'Le second numéro doit être différent du premier.',
        ];
    }

    /**
     * Réponses normalisées, prêtes à être persistées.
     *
     * Les chaînes vides sont ramenées à `null` : « vide » et « pas encore
     * répondu » sont le même état pour un brouillon, et deux représentations
     * d'un même état finissent toujours par diverger.
     *
     * @return array<string, string|null>
     */
    public function answers(): array
    {
        $valide = $this->validated();

        $answers = [];

        foreach (ProfileSection::fields() as $field) {
            $valeur = trim((string) ($valide[$field] ?? ''));
            $answers[$field] = $valeur === '' ? null : $valeur;
        }

        return $answers;
    }
}
