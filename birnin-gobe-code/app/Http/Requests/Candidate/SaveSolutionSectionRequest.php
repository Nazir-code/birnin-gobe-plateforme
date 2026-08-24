<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\SolutionSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Solution ».
 *
 * React affiche des compteurs de caractères et pré-remplit la liste des stades
 * de maturité, mais rien de cela n'engage : la requête peut être forgée. Ce qui
 * entre en base est ce que cette classe accepte, et seulement les champs
 * déclarés par `SolutionSection` — un champ inconnu glissé dans la charge utile
 * est ignoré, pas enregistré.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveSolutionSectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return SolutionSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            SolutionSection::SOLUTION_NAME => 'nom de la solution',
            SolutionSection::VALUE_PROPOSITION => 'proposition de valeur',
            SolutionSection::KEY_FEATURES => 'fonctionnalités principales',
            SolutionSection::USAGE_SCENARIO => 'scénario d’usage',
            SolutionSection::INNOVATION => 'caractère innovant',
            SolutionSection::MATURITY_STAGE => 'stade de maturité',
            SolutionSection::PROTOTYPE_STATUS => 'état du prototype',
            SolutionSection::TECHNOLOGIES => 'technologies',
            SolutionSection::INTEROPERABILITY => 'interopérabilité',
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

        foreach (SolutionSection::fields() as $field) {
            $valeur = trim((string) ($valide[$field] ?? ''));
            $answers[$field] = $valeur === '' ? null : $valeur;
        }

        return $answers;
    }
}
