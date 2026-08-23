<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;

/**
 * Définition de la section « Structure / équipe » — étape 3.
 *
 * Le cahier des charges annonce l'étape (§5.2) : « Candidature individuelle,
 * équipe ou startup; membres, rôles, compétences, représentant et données
 * légales éventuelles », puis détaille au §6.2. Chaque champ ci-dessous vient
 * de ce tableau, aucun n'a été déduit d'un formulaire startup générique.
 *
 * Trois variantes, décidées par la **seule** source du type de candidature —
 * la section « Éligibilité » (§6.2 : « Type de candidature […] détermine les
 * champs et pièces conditionnels ») :
 *
 *   INDIVIDUAL  rien à renseigner. Le §6.2 ne prévoit ni structure ni membres
 *               pour une candidature individuelle, et inventer des champs pour
 *               remplir l'écran serait pire que l'écran vide.
 *   TEAM        « équipe informelle » : des membres, pas de personne morale.
 *               C'est la mention « non constituée » admise par le §6.2.
 *   STARTUP     « startup constituée » : les données légales **et** les membres.
 *
 * Ce que cette section ne demande pas, parce que la donnée existe déjà :
 *
 *   | Donnée              | Source de vérité       |
 *   |---------------------|------------------------|
 *   | type de candidature | section `eligibility`  |
 *   | effectif déclaré    | section `eligibility`  |
 *   | porteur principal   | `users` + `profile`    |
 *
 * Voir ADR-011.
 */
final class TeamSection
{
    public const SECTION = ApplicationSection::TEAM;

    // — Structure constituée (§6.2, objet « Structure ») ————————————
    public const STRUCTURE_NAME = 'structure_name';

    public const STRUCTURE_ACRONYM = 'structure_acronym';

    public const STRUCTURE_FOUNDED_YEAR = 'structure_founded_year';

    public const STRUCTURE_SECTOR = 'structure_sector';

    public const STRUCTURE_ADDRESS = 'structure_address';

    public const STRUCTURE_RCCM = 'structure_rccm';

    public const STRUCTURE_NIF = 'structure_nif';

    public const STRUCTURE_WEBSITE = 'structure_website';

    public const STRUCTURE_SOCIAL = 'structure_social';

    /** Collection des autres membres (§6.2, objet « Membres »). */
    public const MEMBERS = 'members';

    // — Champs d'un membre —————————————————————————————————————————
    public const MEMBER_NAME = 'full_name';

    public const MEMBER_EMAIL = 'email';

    public const MEMBER_PHONE = 'phone';

    public const MEMBER_ROLE = 'role';

    public const MEMBER_SKILLS = 'skills';

    public const MEMBER_AVAILABILITY = 'availability';

    public const MEMBER_IS_FOUNDER = 'is_founder';

    public const MEMBER_CONSENT = 'consent';

    public const SHORT_TEXT_MAX = 120;

    public const LONG_TEXT_MAX = 300;

    /**
     * Garde-fou de saisie, pas une règle métier : au-delà, c'est une faute de
     * frappe. Le §6.2 ne fixe aucune ancienneté minimale.
     */
    public const FOUNDED_YEAR_FLOOR = 1900;

    /**
     * Plafond du nombre de membres saisissables.
     *
     * Ce n'est pas la règle métier : le §6.2 annonce un « nombre minimal/maximal
     * configurable », déjà porté par `campaign.settings.eligibility.team_size`
     * et appliqué par le moteur d'éligibilité. Ce plafond-ci n'existe que pour
     * qu'un formulaire forgé ne fasse pas exploser la ligne `jsonb`.
     */
    public const MEMBERS_CEILING = 50;

    /** @return list<string> Champs de la structure, dans l'ordre de l'écran. */
    public static function structureFields(): array
    {
        return [
            self::STRUCTURE_NAME,
            self::STRUCTURE_ACRONYM,
            self::STRUCTURE_FOUNDED_YEAR,
            self::STRUCTURE_SECTOR,
            self::STRUCTURE_ADDRESS,
            self::STRUCTURE_RCCM,
            self::STRUCTURE_NIF,
            self::STRUCTURE_WEBSITE,
            self::STRUCTURE_SOCIAL,
        ];
    }

    /**
     * Champs sans lesquels une structure constituée n'est pas décrite.
     *
     * `RCCM` et `NIF` en sont absents : le §6.2 les qualifie explicitement de
     * « si applicable ». Sigle, site et réseaux non plus — toutes les structures
     * n'en ont pas.
     *
     * @var list<string>
     */
    public const REQUIRED_STRUCTURE_FIELDS = [
        self::STRUCTURE_NAME,
        self::STRUCTURE_FOUNDED_YEAR,
        self::STRUCTURE_SECTOR,
        self::STRUCTURE_ADDRESS,
    ];

    /** @return list<string> */
    public static function memberFields(): array
    {
        return [
            self::MEMBER_NAME,
            self::MEMBER_EMAIL,
            self::MEMBER_PHONE,
            self::MEMBER_ROLE,
            self::MEMBER_SKILLS,
            self::MEMBER_AVAILABILITY,
            self::MEMBER_IS_FOUNDER,
            self::MEMBER_CONSENT,
        ];
    }

    /** Cette variante attend-elle une personne morale ? */
    public static function attendUneStructure(?CandidateType $type): bool
    {
        return $type === CandidateType::STARTUP;
    }

    /** Cette variante attend-elle des membres en plus du porteur principal ? */
    public static function attendDesMembres(?CandidateType $type): bool
    {
        return $type?->isCollective() === true;
    }

    /**
     * Un membre est décrit quand on sait qui il est, ce qu'il fait, comment le
     * joindre, et qu'il a consenti à figurer au dossier.
     *
     * Le contact accepte l'e-mail **ou** le téléphone : le §6.2 demande « contact »
     * au singulier, et exiger une adresse e-mail exclurait les membres qui n'en
     * ont pas — ce que la plateforme prend déjà en compte ailleurs en proposant
     * le SMS comme canal.
     *
     * Le consentement est une règle explicite du §6.2 (« consentement de chaque
     * membre »), pas une précaution ajoutée.
     *
     * @param  array<string, mixed>  $membre
     */
    public static function membreEstComplet(array $membre): bool
    {
        foreach ([self::MEMBER_NAME, self::MEMBER_ROLE] as $champ) {
            if (trim((string) ($membre[$champ] ?? '')) === '') {
                return false;
            }
        }

        $contact = trim((string) ($membre[self::MEMBER_EMAIL] ?? ''))
            .trim((string) ($membre[self::MEMBER_PHONE] ?? ''));

        return $contact !== '' && ($membre[self::MEMBER_CONSENT] ?? false) === true;
    }

    /**
     * Effectif total réellement décrit : les membres listés, plus le porteur
     * principal, qui n'est pas dans la liste — son identité vit dans le compte
     * et dans la section « Profil ».
     *
     * @param  array<int, mixed>  $membres
     */
    public static function effectifDecrit(array $membres): int
    {
        return count($membres) + 1;
    }
}
