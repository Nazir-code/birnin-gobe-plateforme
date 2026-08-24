<?php

namespace App\Domain\Application;

/**
 * Définition de la section « Plan de mise en œuvre » — étape 7.
 *
 * Les champs viennent du cahier des charges, et d'eux seuls :
 *
 *   §5.2, étape 7 — « Activités, jalons, ressources, partenaires, risques,
 *     besoins d'accompagnement et budget indicatif » ;
 *   §7.1, rubrique Exécution — « Plan sur 3 à 12 mois, jalons, équipe, moyens,
 *     budget indicatif, risques, hypothèses, besoins de prototypage et
 *     d'expérimentation ».
 *
 * **Ce n'est pas un outil de gestion de projet.** Les jalons et les activités
 * sont rédigés, pas structurés en lignes ordonnançables : aucun diagramme,
 * aucune dépendance entre tâches, aucun pourcentage d'avancement. Ce que le
 * cahier demande, c'est que le candidat expose son plan — pas que la plateforme
 * le pilote.
 *
 * Deux valeurs sont numériques parce que le cahier les pose ainsi :
 *
 *   la durée, que le §7.1 borne explicitement — « Plan sur 3 à 12 mois ». C'est
 *     la seule borne chiffrée que la source donne pour cette étape, et elle est
 *     donc contrôlée côté serveur ;
 *   le budget indicatif, un montant. Le détail par poste relève de la pièce
 *     jointe « Budget et plan d'action » du §7.2 — donc de l'étape 8 ; le champ
 *     libre ci-dessous n'en demande que les grandes masses.
 *
 * « Équipe » (§7.1 Exécution) n'est pas redemandée : l'étape 3 la recueille déjà,
 * membre par membre. `RESOURCES` porte les moyens matériels et techniques.
 */
final class ImplementationSection
{
    public const SECTION = ApplicationSection::IMPLEMENTATION;

    /** Durée du plan en mois (§7.1, Exécution : « Plan sur 3 à 12 mois »). */
    public const DURATION_MONTHS = 'duration_months';

    /** Activités principales (§5.2 étape 7). */
    public const ACTIVITIES = 'activities';

    /** Jalons (§5.2 étape 7 ; §7.1 Exécution). Rédigés, pas ordonnancés. */
    public const MILESTONES = 'milestones';

    /** Ressources et moyens, hors équipe — celle-ci vit à l'étape 3. */
    public const RESOURCES = 'resources';

    /** Partenaires (§5.2 étape 7 ; §7.1 Viabilité). */
    public const PARTNERS = 'partners';

    /** Risques et hypothèses (§5.2 étape 7 ; §7.1 Exécution). */
    public const RISKS = 'risks';

    /** Besoins d'accompagnement, de prototypage et d'expérimentation (§5.2 ; §7.1). */
    public const SUPPORT_NEEDS = 'support_needs';

    /** Budget indicatif, en francs CFA (§5.2 étape 7 ; §7.1 Exécution). */
    public const BUDGET_AMOUNT = 'budget_amount';

    /** Grandes masses du budget. Le détail par poste est une pièce du §7.2. */
    public const BUDGET_BREAKDOWN = 'budget_breakdown';

    /** Longueur d'une réponse rédigée, alignée sur les étapes 5 et 6. */
    public const LONG_TEXT_MAX = 1000;

    /** Bornes du §7.1 : « Plan sur 3 à 12 mois ». */
    public const DURATION_MIN = 3;

    public const DURATION_MAX = 12;

    /**
     * Plafond du budget indicatif, en francs CFA.
     *
     * Il n'est pas un critère : aucune source ne fixe de montant maximal
     * finançable, et ce n'est pas à cette classe de l'inventer. C'est une borne
     * de saisie — dix milliards de FCFA — qui écarte la faute de frappe et le
     * dépassement d'entier, rien de plus.
     */
    public const BUDGET_CEILING = 10_000_000_000;

    /**
     * Champs sans lesquels l'étape n'est pas faite.
     *
     * Restent facultatifs les partenaires — un porteur individuel peut n'en
     * avoir aucun, et en exiger un produirait une réponse de complaisance — et
     * la ventilation du budget, dont la version qui engage est la pièce jointe
     * du §7.2.
     *
     * @var list<string>
     */
    public const REQUIRED_FIELDS = [
        self::DURATION_MONTHS,
        self::ACTIVITIES,
        self::MILESTONES,
        self::RESOURCES,
        self::RISKS,
        self::SUPPORT_NEEDS,
        self::BUDGET_AMOUNT,
    ];

    /** @return list<string> */
    public static function fields(): array
    {
        return [
            self::DURATION_MONTHS,
            self::ACTIVITIES,
            self::MILESTONES,
            self::RESOURCES,
            self::PARTNERS,
            self::RISKS,
            self::SUPPORT_NEEDS,
            self::BUDGET_AMOUNT,
            self::BUDGET_BREAKDOWN,
        ];
    }

    /** Les deux champs stockés comme entiers, et non comme texte. */
    public const NUMERIC_FIELDS = [self::DURATION_MONTHS, self::BUDGET_AMOUNT];

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable` partout : un brouillon incomplet doit pouvoir être enregistré.
     * Mais ce qui est saisi doit être plausible — une durée hors des bornes du
     * §7.1 ou un budget négatif n'entrent pas en base, quoi qu'en dise le
     * formulaire.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $texteLong = ['nullable', 'string', 'max:'.self::LONG_TEXT_MAX];

        return [
            self::DURATION_MONTHS => ['nullable', 'integer', 'min:'.self::DURATION_MIN, 'max:'.self::DURATION_MAX],
            self::ACTIVITIES => $texteLong,
            self::MILESTONES => $texteLong,
            self::RESOURCES => $texteLong,
            self::PARTNERS => $texteLong,
            self::RISKS => $texteLong,
            self::SUPPORT_NEEDS => $texteLong,
            self::BUDGET_AMOUNT => ['nullable', 'integer', 'min:0', 'max:'.self::BUDGET_CEILING],
            self::BUDGET_BREAKDOWN => $texteLong,
        ];
    }

    /**
     * La section est faite quand ses champs obligatoires sont renseignés.
     *
     * Un budget à zéro est une réponse : le projet ne demande rien. Elle compte
     * donc, là où une case laissée vide ne compte pas — d'où le test sur `null`
     * pour les champs numériques plutôt que sur la chaîne vide.
     *
     * @param  array<string, mixed>  $answers
     */
    public static function isComplete(array $answers): bool
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            $valeur = $answers[$field] ?? null;

            if (in_array($field, self::NUMERIC_FIELDS, strict: true)) {
                if (! is_int($valeur)) {
                    return false;
                }

                continue;
            }

            if (trim((string) $valeur) === '') {
                return false;
            }
        }

        return true;
    }
}
