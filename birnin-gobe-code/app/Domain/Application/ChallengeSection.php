<?php

namespace App\Domain\Application;

use App\Domain\Reference\NigerRegion;
use Illuminate\Validation\Rule;

/**
 * Définition de la section « Défi ».
 *
 * Les cinq champs sont ceux réellement présents à l'écran, rien n'a été ajouté
 * pour les besoins des tests. Cette classe est la seule source : la
 * `FormRequest` en dérive ses règles, l'action de sauvegarde en dérive les clés
 * conservées, et la complétude de la section en dérive sa condition. Ajouter un
 * champ ici suffit à le propager partout.
 *
 * La thématique ouvre la section parce qu'elle cadre tout le reste : le défi
 * décrit ensuite se lit sous cette thématique. Un dossier soumis sans elle
 * serait impossible à ranger parmi les quatre axes du concours — et c'est
 * précisément ce que cette phase corrige.
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
     * Thématique officielle sous laquelle le projet concourt.
     *
     * Contrainte aux quatre codes de `ProjectTheme`, jamais du texte libre : la
     * valeur sert à ranger et à dénombrer les dossiers, une saisie libre les
     * rendrait incomparables.
     */
    public const THEME_FIELD = 'project_theme';

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

        // `nullable` comme les autres — un brouillon s'enregistre incomplet — mais
        // toute valeur hors des quatre thématiques est refusée définitivement.
        $rules[self::THEME_FIELD] = ['nullable', 'string', Rule::enum(ProjectTheme::class)];

        return $rules;
    }

    /**
     * Les champs de la section, dans l'ordre de l'écran.
     *
     * La thématique en tête : elle est demandée avant le récit du défi, et
     * l'administration la lit en premier sur la fiche du dossier.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [self::THEME_FIELD, ...self::TEXT_FIELDS, self::REGION_FIELD];
    }

    /**
     * La section est faite quand ses cinq réponses sont renseignées.
     *
     * Conséquence assumée pour les brouillons antérieurs à la thématique : ils
     * se chargent et se modifient normalement, mais leur section « Défi »
     * redevient incomplète tant que le candidat n'a pas choisi. Aucune valeur
     * par défaut n'est inventée — attribuer d'office une thématique à un projet
     * qu'on n'a pas lu serait une donnée fausse, et elle serait recopiée telle
     * quelle dans le dossier soumis.
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
