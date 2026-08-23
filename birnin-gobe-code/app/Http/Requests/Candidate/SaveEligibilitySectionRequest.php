<?php

namespace App\Http\Requests\Candidate;

use App\Domain\Application\EligibilitySection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation serveur de la section « Éligibilité ».
 *
 * React affiche des boutons radio et une liste de régions, mais rien de tout
 * cela n'engage : la requête peut être forgée. Ce qui entre en base est ce que
 * cette classe accepte, et seulement les champs déclarés par
 * `EligibilitySection` — un `outcome` ou un `eligible` glissé dans la charge
 * utile est ignoré, jamais enregistré. Le verdict n'est pas une donnée
 * d'entrée : il est calculé par le serveur à partir des réponses.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `role:candidate`
 * et par `can:update,application` sur la route.
 */
final class SaveEligibilitySectionRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return EligibilitySection::rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            EligibilitySection::BIRTH_DATE => 'date de naissance',
            EligibilitySection::NIGERIEN_NATIONAL => 'nationalité nigérienne',
            EligibilitySection::RESIDES_IN_NIGER => 'résidence au Niger',
            EligibilitySection::INTERVENTION_REGION => 'région d’intervention',
            EligibilitySection::CANDIDATE_TYPE => 'type de candidature',
            EligibilitySection::TEAM_SIZE => 'taille de l’équipe',
        ];
    }

    /**
     * Réponses normalisées et typées, prêtes à être persistées.
     *
     * Le typage se fait ici, une fois, plutôt qu'à chaque lecture : `answers`
     * est du `jsonb`, il conserve donc de vrais booléens et de vrais entiers.
     * Les règles métier n'ont pas à réinterpréter des chaînes « 1 » ou « true »,
     * et une comparaison stricte reste possible partout.
     *
     * La chaîne vide vaut `null` : « vide » et « pas encore répondu » sont le
     * même état pour un brouillon, et deux représentations d'un même état
     * finissent toujours par diverger.
     *
     * @return array<string, string|bool|int|null>
     */
    public function answers(): array
    {
        $valide = $this->validated();

        $answers = [];

        foreach (EligibilitySection::fields() as $field) {
            $valeur = $valide[$field] ?? null;

            $answers[$field] = match (true) {
                $valeur === null || $valeur === '' => null,
                in_array($field, EligibilitySection::BOOLEAN_FIELDS, strict: true) => (bool) $valeur,
                $field === EligibilitySection::TEAM_SIZE => (int) $valeur,
                default => (string) $valeur,
            };
        }

        return $answers;
    }
}
