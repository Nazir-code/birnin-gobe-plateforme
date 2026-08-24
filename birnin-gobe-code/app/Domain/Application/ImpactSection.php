<?php

namespace App\Domain\Application;

/**
 * Définition de la section « Impact / viabilité » — étape 6.
 *
 * Les champs viennent du cahier des charges, et d'eux seuls :
 *
 *   §5.2, étape 6 — « Indicateurs, inclusion, résilience, adoption, modèle
 *     économique, coûts, pérennité et mise à l'échelle » ;
 *   §7.1, rubriques Impact, Inclusion et Viabilité — bénéficiaires
 *     directs/indirects, résultats attendus, indicateurs mesurables,
 *     contribution à la résilience et à la qualité des services urbains ; accès
 *     des femmes, jeunes, personnes vulnérables, personnes handicapées et zones
 *     moins connectées ; modèle économique ou institutionnel, utilisateurs
 *     payeurs, coûts, maintenance, appropriation par les collectivités et mise
 *     à l'échelle.
 *
 * **Cette étape décrit, elle ne note pas.** Le candidat déclare ici ce qu'il
 * attend de son projet ; l'appréciation de ces déclarations appartient à
 * l'évaluation, qui vit dans un autre contexte borné. On ne trouvera donc dans
 * cette classe ni score, ni note, ni pondération, ni classement — et
 * `impact_indicators` est bien la façon dont le candidat compte mesurer son
 * projet, jamais une mesure calculée par la plateforme.
 *
 * Deux regroupements, pour ne pas découper une même question en trois champs :
 *
 *   « modèle économique », « utilisateurs payeurs » et « coûts » tiennent en une
 *     réponse — c'est un seul raisonnement, pas trois ;
 *   « adoption », « pérennité », « maintenance » et « appropriation par les
 *     collectivités » aussi : ce sont les étapes d'une même trajectoire.
 *
 * « Partenaires », que le §7.1 range dans Viabilité, est demandé à l'étape 7 :
 * le §5.2 en fait explicitement une fonction déterminante du plan de mise en
 * œuvre, et c'est là que le candidat les cite naturellement.
 */
final class ImpactSection
{
    public const SECTION = ApplicationSection::IMPACT;

    /** Bénéficiaires directs et indirects (§7.1, Impact). */
    public const BENEFICIARIES = 'beneficiaries';

    /** Résultats attendus (§7.1, Impact). */
    public const EXPECTED_RESULTS = 'expected_results';

    /**
     * Indicateurs mesurables (§5.2 étape 6 ; §7.1 Impact).
     *
     * Ce que le candidat propose de suivre. Aucune valeur n'est calculée ni
     * comparée par la plateforme à ce stade.
     */
    public const IMPACT_INDICATORS = 'impact_indicators';

    /** Inclusion (§5.2 étape 6 ; §7.1, rubrique Inclusion en entier). */
    public const INCLUSION_MEASURES = 'inclusion_measures';

    /** Résilience et qualité des services urbains (§5.2 étape 6 ; §7.1 Impact). */
    public const RESILIENCE_CONTRIBUTION = 'resilience_contribution';

    /** Modèle économique, utilisateurs payeurs et coûts (§5.2 étape 6 ; §7.1 Viabilité). */
    public const BUSINESS_MODEL = 'business_model';

    /** Adoption, maintenance, pérennité et appropriation (§5.2 étape 6 ; §7.1 Viabilité). */
    public const SUSTAINABILITY = 'sustainability';

    /** Mise à l'échelle (§5.2 étape 6 ; §7.1 Viabilité). */
    public const SCALING_PLAN = 'scaling_plan';

    /** Longueur d'une réponse rédigée, alignée sur l'étape 5. */
    public const LONG_TEXT_MAX = 1000;

    /**
     * Champs sans lesquels l'étape n'est pas faite.
     *
     * Seule la mise à l'échelle reste facultative : elle décrit une suite qu'un
     * projet au stade de l'idée n'a pas encore à formuler, et l'exiger
     * reviendrait à demander une réponse de complaisance.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        self::BENEFICIARIES,
        self::EXPECTED_RESULTS,
        self::IMPACT_INDICATORS,
        self::INCLUSION_MEASURES,
        self::RESILIENCE_CONTRIBUTION,
        self::BUSINESS_MODEL,
        self::SUSTAINABILITY,
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            self::BENEFICIARIES,
            self::EXPECTED_RESULTS,
            self::IMPACT_INDICATORS,
            self::INCLUSION_MEASURES,
            self::RESILIENCE_CONTRIBUTION,
            self::BUSINESS_MODEL,
            self::SUSTAINABILITY,
            self::SCALING_PLAN,
        ];
    }

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` partout : un brouillon incomplet doit pouvoir être enregistré.
     * Le caractère obligatoire est porté par `isComplete()`.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::fields() as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.self::LONG_TEXT_MAX];
        }

        return $rules;
    }

    /**
     * La section est faite quand ses champs obligatoires sont renseignés.
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
