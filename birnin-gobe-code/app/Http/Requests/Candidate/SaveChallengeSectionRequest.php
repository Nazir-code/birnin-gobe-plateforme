<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\ChallengeSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Défi ».
 *
 * React affiche un compteur de caractères et pré-remplit la liste des régions,
 * mais rien de tout cela n'engage : la requête peut être forgée. Ce qui entre
 * en base est ce que cette classe accepte, et seulement les champs déclarés par
 * `ChallengeSection` — un champ inconnu glissé dans la charge utile est ignoré,
 * pas enregistré.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`
 * et par `can:update,application` sur la route.
 */
final class SaveChallengeSectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ChallengeSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'main_challenge' => 'défi principal',
            'affected_people' => 'personnes affectées',
            'location' => 'localisation',
            'root_causes' => 'causes profondes',
            ChallengeSection::THEME_FIELD => 'thématique du projet',
        ];
    }

    /**
     * Réponses normalisées, prêtes à être persistées.
     *
     * Les chaînes vides sont ramenées à `null` : « vide » et « pas encore
     * répondu » sont le même état pour un brouillon, et deux représentations
     * pour un même état finissent toujours par diverger.
     *
     * @return array<string, string|null>
     */
    public function answers(): array
    {
        $answers = [];

        foreach (ChallengeSection::fields() as $field) {
            $valeur = trim((string) $this->input($field, ''));
            $answers[$field] = $valeur === '' ? null : $valeur;
        }

        return $answers;
    }
}
