<?php

namespace App\Domain\Application;

use App\Domain\Reference\NigerRegion;
use Illuminate\Validation\Rule;

/**
 * Définition de la section « Défi ».
 *
 * Les quatre champs sont ceux réellement présents à l'écran, rien n'a été
 * ajouté pour les besoins des tests. Cette classe est la seule source : la
 * `FormRequest` en dérive ses règles, l'action de sauvegarde en dérive les clés
 * conservées, et la complétude de la section en dérive sa condition. Ajouter un
 * champ ici suffit à le propager partout.
 */
final class ChallengeSection
{
    public const SECTION = ApplicationSection::CHALLENGE;

    /** Longueur imposée par l'écran, et donc contrôlée côté serveur. */
    public const MAX_LENGTH = 500;

    /** @var list<string> */
    public const TEXT_FIELDS = ['main_challenge', 'affected_people', 'root_causes'];

    /** Champ à valeurs contraintes : une région du référentiel, pas du texte libre. */
    public const REGION_FIELD = 'location';

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` est délibéré : un brouillon incomplet doit pouvoir être
     * enregistré, c'est le principe même de la sauvegarde continue. Le
     * caractère obligatoire des réponses est une règle de soumission, portée
     * par `isComplete()` puis, à terme, par `SubmitApplication`. Ce qui est
     * refusé ici l'est définitivement : une réponse trop longue ou une région
     * inconnue n'entre pas en base, quoi qu'en dise le formulaire.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::TEXT_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.self::MAX_LENGTH];
        }

        $rules[self::REGION_FIELD] = ['nullable', 'string', Rule::enum(NigerRegion::class)];

        return $rules;
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return [...self::TEXT_FIELDS, self::REGION_FIELD];
    }

    /**
     * La section est faite quand ses quatre réponses sont renseignées.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isComplete(array $answers): bool
    {
        foreach (self::fields() as $field) {
            if (trim((string) ($answers[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
