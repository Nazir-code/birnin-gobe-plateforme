<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\ImplementationSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Plan de mise en œuvre ».
 *
 * Ce qui entre en base est ce que cette classe accepte, et seulement les champs
 * déclarés par `ImplementationSection`. Les deux bornes du cahier — la durée de
 * 3 à 12 mois du §7.1, et un budget qui ne peut être négatif — sont tenues ici,
 * pas par le formulaire.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveImplementationSectionRequest extends FormRequest
{
    /**
     * Normalisation avant validation.
     *
     * Un montant en francs CFA se saisit avec des espaces : « 5 000 000 » est
     * la forme naturelle, y compris l'espace insécable que produisent certains
     * claviers et copier-coller. On la comprend plutôt que de la refuser — et la
     * règle de format porte ensuite sur la valeur qui sera réellement stockée.
     *
     * Ce qui ne se ramène à aucun entier ressort inchangé et sera refusé par la
     * validation, avec un message sur le champ.
     */
    protected function prepareForValidation(): void
    {
        $normalises = [];

        foreach (ImplementationSection::NUMERIC_FIELDS as $champ) {
            if (! $this->has($champ)) {
                continue;
            }

            $valeur = $this->input($champ);

            if (! is_string($valeur)) {
                continue;
            }

            $nettoye = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $valeur) ?? '';
            $normalises[$champ] = $nettoye === '' ? null : $nettoye;
        }

        if ($normalises !== []) {
            $this->merge($normalises);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ImplementationSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            ImplementationSection::DURATION_MONTHS => 'durée du plan',
            ImplementationSection::ACTIVITIES => 'activités',
            ImplementationSection::MILESTONES => 'jalons',
            ImplementationSection::RESOURCES => 'ressources',
            ImplementationSection::PARTNERS => 'partenaires',
            ImplementationSection::RISKS => 'risques',
            ImplementationSection::SUPPORT_NEEDS => 'besoins d’accompagnement',
            ImplementationSection::BUDGET_AMOUNT => 'budget indicatif',
            ImplementationSection::BUDGET_BREAKDOWN => 'répartition du budget',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ImplementationSection::DURATION_MONTHS.'.min' => 'Le cahier des charges attend un plan de :min à '.ImplementationSection::DURATION_MAX.' mois.',
            ImplementationSection::DURATION_MONTHS.'.max' => 'Le cahier des charges attend un plan de '.ImplementationSection::DURATION_MIN.' à :max mois.',
            ImplementationSection::BUDGET_AMOUNT.'.integer' => 'Indiquez un montant en francs CFA, par exemple 5 000 000.',
            ImplementationSection::BUDGET_AMOUNT.'.min' => 'Un budget ne peut pas être négatif.',
        ];
    }

    /**
     * Réponses normalisées, prêtes à être persistées.
     *
     * Les chaînes vides sont ramenées à `null`, comme dans les sections
     * précédentes. Les deux champs numériques sont stockés comme entiers et non
     * comme texte : « 0 » et « pas de budget indiqué » ne sont pas le même état,
     * et une chaîne les confondrait.
     *
     * @return array<string, string|int|null>
     */
    public function answers(): array
    {
        $valide = $this->validated();

        $answers = [];

        foreach (ImplementationSection::fields() as $field) {
            $valeur = $valide[$field] ?? null;

            if (in_array($field, ImplementationSection::NUMERIC_FIELDS, strict: true)) {
                $answers[$field] = is_numeric($valeur) ? (int) $valeur : null;

                continue;
            }

            $texte = trim((string) $valeur);
            $answers[$field] = $texte === '' ? null : $texte;
        }

        return $answers;
    }
}
