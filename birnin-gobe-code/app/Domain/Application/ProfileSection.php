<?php

namespace App\Domain\Application;

use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\Gender;
use App\Domain\Candidate\PreferredChannel;
use App\Domain\Reference\NigerRegion;
use Illuminate\Validation\Rule;

/**
 * Définition de la section « Profil du candidat » — étape 2.
 *
 * Le cahier des charges annonce l'étape (§5.2) : « Identité, contacts,
 * localisation, situation professionnelle, besoins d'accessibilité et
 * préférences de communication », puis détaille les champs au §6.1
 * (« Données du porteur principal »). Chaque champ ci-dessous vient de l'un ou
 * de l'autre, aucun n'a été déduit d'un formulaire d'inscription générique.
 *
 * Ce que cette section **ne demande pas**, parce que la donnée existe déjà :
 *
 *   | Donnée              | Source de vérité                    |
 *   |---------------------|-------------------------------------|
 *   | nom, adresse e-mail | `users` — le compte                 |
 *   | date de naissance   | section `eligibility`               |
 *   | nationalité         | section `eligibility`               |
 *   | résidence au Niger  | section `eligibility`               |
 *
 * Elles sont affichées en lecture seule sur l'écran, avec un lien vers l'étape
 * qui les détient. Les recopier ici créerait deux vérités pour une même
 * information — voir ADR-009.
 *
 * Attention à ne pas confondre deux localisations, que le §6.1 distingue :
 * `residence_region` est **où vit le candidat**, tandis que
 * `intervention_region` (étape 1) est **où le projet agira**. Ce sont deux
 * questions différentes, pas un doublon.
 */
final class ProfileSection
{
    public const SECTION = ApplicationSection::PROFILE;

    // — Identité complémentaire (§6.1, groupe Identité) ————————————
    public const BIRTH_PLACE = 'birth_place';

    public const GENDER = 'gender';

    // — Contacts (§6.1, groupe Contact) ————————————————————————————
    public const PHONE_PRIMARY = 'phone_primary';

    public const PHONE_SECONDARY = 'phone_secondary';

    public const PREFERRED_CHANNEL = 'preferred_channel';

    // — Localisation de résidence (§6.1, groupe Localisation) ——————
    public const RESIDENCE_REGION = 'residence_region';

    public const RESIDENCE_LOCALITY = 'residence_locality';

    // — Situation professionnelle (§6.1, groupe Profil) ————————————
    public const OCCUPATION = 'occupation';

    public const EDUCATION_LEVEL = 'education_level';

    public const SPECIALTY = 'specialty';

    // — Accessibilité (§6.1, groupe Accessibilité) —————————————————
    public const ACCESSIBILITY_NEED = 'accessibility_need';

    /** Longueur des réponses courtes, alignée sur les champs de l'écran. */
    public const SHORT_TEXT_MAX = 120;

    /** Longueur de la seule zone descriptive de la section. */
    public const LONG_TEXT_MAX = 500;

    /**
     * Champs sans lesquels la section n'est pas faite.
     *
     * Le sexe et le besoin d'accessibilité en sont volontairement absents :
     * le premier est conditionnel (§6.1 « si requis pour statistiques »), le
     * second explicitement facultatif (§6.1 « champ facultatif »). Le téléphone
     * secondaire et la spécialité sont, eux, des compléments.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        self::BIRTH_PLACE,
        self::PHONE_PRIMARY,
        self::PREFERRED_CHANNEL,
        self::RESIDENCE_REGION,
        self::RESIDENCE_LOCALITY,
        self::OCCUPATION,
        self::EDUCATION_LEVEL,
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            self::BIRTH_PLACE,
            self::GENDER,
            self::PHONE_PRIMARY,
            self::PHONE_SECONDARY,
            self::PREFERRED_CHANNEL,
            self::RESIDENCE_REGION,
            self::RESIDENCE_LOCALITY,
            self::OCCUPATION,
            self::EDUCATION_LEVEL,
            self::SPECIALTY,
            self::ACCESSIBILITY_NEED,
        ];
    }

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` partout, comme « Éligibilité » et « Défi » : un brouillon
     * incomplet doit pouvoir être enregistré. Le caractère obligatoire est
     * porté par `isComplete()`, qui décide de `completed_at`, puis à terme par
     * la soumission.
     *
     * Ce qui est refusé ici l'est définitivement : un numéro hors format E.164,
     * une région hors référentiel, un canal ou un niveau d'études inconnu
     * n'entrent pas en base, quoi qu'en dise le formulaire.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $texteCourt = ['nullable', 'string', 'max:'.self::SHORT_TEXT_MAX];

        return [
            self::BIRTH_PLACE => $texteCourt,
            self::GENDER => ['nullable', 'string', Rule::enum(Gender::class)],
            // Les numéros sont normalisés en E.164 avant validation : ce motif
            // s'applique donc à la valeur qui sera réellement stockée, pas à ce
            // que le candidat a tapé.
            self::PHONE_PRIMARY => ['nullable', 'string', 'regex:'.self::E164_PATTERN],
            self::PHONE_SECONDARY => ['nullable', 'string', 'regex:'.self::E164_PATTERN, 'different:'.self::PHONE_PRIMARY],
            self::PREFERRED_CHANNEL => ['nullable', 'string', Rule::enum(PreferredChannel::class)],
            self::RESIDENCE_REGION => ['nullable', 'string', Rule::enum(NigerRegion::class)],
            self::RESIDENCE_LOCALITY => $texteCourt,
            self::OCCUPATION => $texteCourt,
            self::EDUCATION_LEVEL => ['nullable', 'string', Rule::enum(EducationLevel::class)],
            self::SPECIALTY => $texteCourt,
            self::ACCESSIBILITY_NEED => ['nullable', 'string', 'max:'.self::LONG_TEXT_MAX],
        ];
    }

    /** Format international, tel qu'attendu par la passerelle SMS (§14). */
    public const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    /** Indicatif appliqué à un numéro national à huit chiffres. */
    public const DEFAULT_COUNTRY_CODE = '+227';

    /**
     * Ramène un numéro saisi à sa forme internationale.
     *
     * Le §6.1 impose un « code pays », et l'éligibilité admet des candidats
     * hors du Niger : le stockage doit donc être international. Mais un
     * candidat nigérien tape naturellement ses huit chiffres, séparés comme il
     * l'entend. On normalise plutôt que de le corriger :
     *
     *   « 90 12 34 56 »   → +22790123456
     *   « 0022790123456 » → +22790123456
     *   « +33 6 12 ... »  → +336…, conservé tel quel
     *
     * Ce qui ne rentre dans aucune de ces formes ressort inchangé et sera
     * refusé par la validation, avec un message sur le champ.
     */
    public static function normalizePhone(?string $saisie): ?string
    {
        if ($saisie === null) {
            return null;
        }

        $nettoye = preg_replace('/[\s.\-()\/]/', '', trim($saisie)) ?? '';

        if ($nettoye === '') {
            return null;
        }

        if (str_starts_with($nettoye, '00')) {
            $nettoye = '+'.substr($nettoye, 2);
        }

        // Numéro national nigérien : huit chiffres, sans indicatif.
        if (preg_match('/^\d{8}$/', $nettoye) === 1) {
            return self::DEFAULT_COUNTRY_CODE.$nettoye;
        }

        return $nettoye;
    }

    /**
     * La section est faite quand ses champs obligatoires sont renseignés.
     *
     * Ouvrir l'écran ne suffit pas : c'est le contenu qui décide, comme pour
     * les deux autres sections.
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
