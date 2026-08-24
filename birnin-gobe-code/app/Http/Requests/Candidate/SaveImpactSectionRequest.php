<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\ImpactSection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Impact / viabilité ».
 *
 * Ce qui entre en base est ce que cette classe accepte, et seulement les champs
 * déclarés par `ImpactSection`. Aucun champ de notation n'existe ici et aucun ne
 * pourrait donc être glissé dans la charge utile : cette étape recueille les
 * déclarations du candidat, l'évaluation vit ailleurs.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`,
 * `can:update,application` et le middleware `eligible` sur la route.
 */
final class SaveImpactSectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ImpactSection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            ImpactSection::BENEFICIARIES => 'bénéficiaires',
            ImpactSection::EXPECTED_RESULTS => 'résultats attendus',
            ImpactSection::IMPACT_INDICATORS => 'indicateurs de suivi',
            ImpactSection::INCLUSION_MEASURES => 'mesures d’inclusion',
            ImpactSection::RESILIENCE_CONTRIBUTION => 'contribution à la résilience',
            ImpactSection::BUSINESS_MODEL => 'modèle économique',
            ImpactSection::SUSTAINABILITY => 'adoption et pérennité',
            ImpactSection::SCALING_PLAN => 'mise à l’échelle',
        ];
    }

    /**
     * Réponses normalisées, prêtes à être persistées.
     *
     * Les chaînes vides sont ramenées à `null`, comme dans les sections
     * précédentes.
     *
     * @return array<string, string|null>
     */
    public function answers(): array
    {
        $valide = $this->validated();

        $answers = [];

        foreach (ImpactSection::fields() as $field) {
            $valeur = trim((string) ($valide[$field] ?? ''));
            $answers[$field] = $valeur === '' ? null : $valeur;
        }

        return $answers;
    }
}
