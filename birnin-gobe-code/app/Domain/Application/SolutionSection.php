<?php

namespace App\Domain\Application;

use Illuminate\Validation\Rule;

/**
 * Définition de la section « Solution » — étape 5.
 *
 * Les champs viennent de deux passages du cahier des charges, et d'eux seuls :
 *
 *   §5.2, étape 5 — « Proposition de valeur, fonctionnalités, innovation,
 *     maturité, technologies, prototype et interopérabilité » ;
 *   §7.1, rubriques Identification et Solution — « Nom de la solution […] stade
 *     de maturité », « Proposition de valeur, utilisateurs, fonctionnalités,
 *     scénario d'usage, architecture simplifiée, différenciation et état du
 *     prototype ».
 *
 * Deux allocations méritent d'être dites, parce qu'elles évitent de poser deux
 * fois la même question au candidat :
 *
 *   « utilisateurs » (§7.1 Solution) n'est pas redemandé ici. L'étape 4 recueille
 *     déjà qui subit le défi, et l'étape 6 demande qui bénéficiera de la solution
 *     — une troisième variante de la même question n'apporterait rien ;
 *   « architecture simplifiée » n'est pas un champ de texte : c'est un schéma,
 *     qui relève des pièces jointes du §7.2 — donc de l'étape 8.
 *
 * Le reste de la rubrique Technique du §7.1 — hébergement, connectivité, mode
 * hors ligne, sécurité, données manipulées, dépendances, propriété
 * intellectuelle — n'est pas repris : le §5.2 ne retient de cette rubrique, pour
 * l'étape 5, que « technologies » et « interopérabilité ». Ces détails
 * techniques sont documentés comme volontairement différés.
 *
 * Comme les sections précédentes, cette classe est la source unique : la
 * `FormRequest` en dérive ses règles, l'action de sauvegarde les clés
 * conservées, et la complétude sa condition.
 */
final class SolutionSection
{
    public const SECTION = ApplicationSection::SOLUTION;

    /** Nom de la solution (§7.1, Identification). Une ligne, pas un paragraphe. */
    public const SOLUTION_NAME = 'solution_name';

    /** Proposition de valeur (§5.2 étape 5 ; §7.1 Solution). */
    public const VALUE_PROPOSITION = 'value_proposition';

    /** Fonctionnalités principales (§5.2 étape 5 ; §7.1 Solution). */
    public const KEY_FEATURES = 'key_features';

    /** Scénario d'usage (§7.1 Solution). Complète les fonctionnalités. */
    public const USAGE_SCENARIO = 'usage_scenario';

    /** Innovation / différenciation (§5.2 étape 5 ; §7.1 Solution). */
    public const INNOVATION = 'innovation';

    /** Stade de maturité (§5.2 étape 5 ; §7.1 Identification). Liste contrôlée. */
    public const MATURITY_STAGE = 'maturity_stage';

    /** État du prototype (§5.2 étape 5 ; §7.1 Solution). */
    public const PROTOTYPE_STATUS = 'prototype_status';

    /** Technologies employées (§5.2 étape 5 ; §7.1 Technique). */
    public const TECHNOLOGIES = 'technologies';

    /** Interopérabilité (§5.2 étape 5 ; §7.1 Technique). */
    public const INTEROPERABILITY = 'interoperability';

    /** Longueur d'un intitulé tenant sur une ligne. */
    public const SHORT_TEXT_MAX = 120;

    /** Longueur d'une réponse rédigée, alignée sur les sections précédentes. */
    public const LONG_TEXT_MAX = 1000;

    /**
     * Champs sans lesquels l'étape n'est pas faite.
     *
     * Le partage suit la règle des sections précédentes : est obligatoire ce que
     * le §5.2 nomme comme fonction déterminante de l'étape, plus le nom de la
     * solution — un dossier sans nom de solution n'est exploitable nulle part en
     * aval. Restent facultatifs les compléments du §7.1 et ce qui peut
     * légitimement ne pas s'appliquer : toutes les solutions n'ont pas de
     * système tiers avec lequel dialoguer.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        self::SOLUTION_NAME,
        self::VALUE_PROPOSITION,
        self::KEY_FEATURES,
        self::INNOVATION,
        self::MATURITY_STAGE,
        self::PROTOTYPE_STATUS,
        self::TECHNOLOGIES,
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            self::SOLUTION_NAME,
            self::VALUE_PROPOSITION,
            self::KEY_FEATURES,
            self::USAGE_SCENARIO,
            self::INNOVATION,
            self::MATURITY_STAGE,
            self::PROTOTYPE_STATUS,
            self::TECHNOLOGIES,
            self::INTEROPERABILITY,
        ];
    }

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` partout, comme les quatre sections précédentes : un brouillon
     * incomplet doit pouvoir être enregistré, c'est le principe même de la
     * sauvegarde continue. Le caractère obligatoire est porté par
     * `isComplete()`, qui décide de `completed_at`.
     *
     * Ce qui est refusé ici l'est définitivement : une réponse trop longue ou un
     * stade de maturité hors liste n'entre pas en base, quoi qu'en dise le
     * formulaire.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $texteLong = ['nullable', 'string', 'max:'.self::LONG_TEXT_MAX];

        return [
            self::SOLUTION_NAME => ['nullable', 'string', 'max:'.self::SHORT_TEXT_MAX],
            self::VALUE_PROPOSITION => $texteLong,
            self::KEY_FEATURES => $texteLong,
            self::USAGE_SCENARIO => $texteLong,
            self::INNOVATION => $texteLong,
            self::MATURITY_STAGE => ['nullable', 'string', Rule::enum(MaturityStage::class)],
            self::PROTOTYPE_STATUS => $texteLong,
            self::TECHNOLOGIES => $texteLong,
            self::INTEROPERABILITY => $texteLong,
        ];
    }

    /**
     * La section est faite quand ses champs obligatoires sont renseignés.
     *
     * Ouvrir l'écran ne suffit pas : c'est le contenu qui décide.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isComplete(array $answers): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string) ($answers[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}
