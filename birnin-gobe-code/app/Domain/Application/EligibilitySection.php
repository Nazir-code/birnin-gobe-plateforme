<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use Illuminate\Validation\Rule;

/**
 * Définition de la section « Éligibilité guidée » — étape 1.
 *
 * Les six questions sont celles que le cahier des charges énumère, pas des
 * questions inventées :
 *
 *   §4.1  « conditions paramétrées, auto-test indicatif, cas individu/équipe/
 *          startup, zones, âge, nationalité/résidence, exclusions et pièces » ;
 *   §5.2  étape 1, « questions courtes » ;
 *   §6.1  « date et lieu de naissance, nationalité », « âge calculé à une date
 *          de référence », « région […] zone d'intervention » ;
 *   §9.2  « Âge et date de référence, nationalité/résidence, zones, types de
 *          candidats, taille d'équipe ».
 *
 * Deux axes du §9.2 restent volontairement absents : les « pièces », qui sont
 * l'étape 8 et supposent le stockage de fichiers, et les « motifs d'exclusion »,
 * que le cahier des charges annonce comme paramétrables sans en énoncer aucun.
 *
 * Ce que cette classe ne contient pas : les seuils. Le verdict est calculé par
 * `App\Domain\Eligibility\EvaluateEligibility`, à partir des paramètres de la
 * campagne. Ici on décrit ce qu'on demande, pas ce qu'on en conclut.
 */
final class EligibilitySection
{
    public const SECTION = ApplicationSection::ELIGIBILITY;

    public const BIRTH_DATE = 'birth_date';

    public const NIGERIEN_NATIONAL = 'is_nigerien_national';

    public const RESIDES_IN_NIGER = 'resides_in_niger';

    public const INTERVENTION_REGION = 'intervention_region';

    public const CANDIDATE_TYPE = 'candidate_type';

    public const TEAM_SIZE = 'team_size';

    /**
     * Garde-fou de saisie, pas une règle métier : au-delà, c'est une faute de
     * frappe. La taille réellement admise est décidée par la campagne.
     */
    public const TEAM_SIZE_CEILING = 999;

    /** @var list<string> */
    public const BOOLEAN_FIELDS = [self::NIGERIEN_NATIONAL, self::RESIDES_IN_NIGER];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            self::BIRTH_DATE,
            self::NIGERIEN_NATIONAL,
            self::RESIDES_IN_NIGER,
            self::INTERVENTION_REGION,
            self::CANDIDATE_TYPE,
            self::TEAM_SIZE,
        ];
    }

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` partout, comme pour « Défi » : un brouillon incomplet doit
     * pouvoir être enregistré. Ce qui est refusé ici l'est définitivement — une
     * région hors référentiel, un type de candidature inconnu ou une date de
     * naissance dans le futur n'entrent pas en base, quoi qu'en dise le
     * formulaire.
     *
     * Ces règles disent ce qui est *saisissable*. Elles ne disent pas qui est
     * éligible : c'est le rôle des règles métier, côté serveur également.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            // `before:today` et non une borne d'âge : une naissance future est
            // une saisie impossible, une naissance ancienne est une question
            // d'éligibilité, pas de validation.
            self::BIRTH_DATE => ['nullable', 'date', 'date_format:Y-m-d', 'before:today'],
            self::NIGERIEN_NATIONAL => ['nullable', 'boolean'],
            self::RESIDES_IN_NIGER => ['nullable', 'boolean'],
            self::INTERVENTION_REGION => ['nullable', 'string', Rule::enum(NigerRegion::class)],
            self::CANDIDATE_TYPE => ['nullable', 'string', Rule::enum(CandidateType::class)],
            self::TEAM_SIZE => ['nullable', 'integer', 'min:1', 'max:'.self::TEAM_SIZE_CEILING],
        ];
    }

    /**
     * La section est faite quand toutes ses questions ont une réponse.
     *
     * L'effectif n'est demandé qu'aux candidatures collectives : une personne
     * seule n'a pas d'équipe à dimensionner, et exiger la réponse la
     * bloquerait sur une question qui ne la concerne pas.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isComplete(array $answers): bool
    {
        foreach (self::fields() as $field) {
            if ($field === self::TEAM_SIZE) {
                continue;
            }

            if (($answers[$field] ?? null) === null || $answers[$field] === '') {
                return false;
            }
        }

        $type = CandidateType::tryFrom((string) ($answers[self::CANDIDATE_TYPE] ?? ''));

        if ($type?->isCollective() === true) {
            return ($answers[self::TEAM_SIZE] ?? null) !== null;
        }

        return true;
    }
}
