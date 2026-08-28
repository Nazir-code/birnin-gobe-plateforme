<?php

namespace App\Domain\Evaluation;

/**
 * Les huit critères de la grille de présélection — §11.2.
 *
 * Le cahier des charges les appelle « grille indicative » sur 100 points. Ils
 * sont ici **dans le code**, et pas dans `campaigns.settings`, pour la même
 * raison qui a fait garder les poids hors des paramètres administrables : le
 * §9.2 prévoit bien de les rendre configurables, mais tant que rien ne lit une
 * grille alternative, un formulaire qui laisserait modifier les poids
 * publierait un réglage sans effet. Le jour où une campagne aura besoin de sa
 * propre grille, c'est cet enum qui deviendra une table — et le contrat
 * `poids()` restera le même.
 *
 * **Le total vaut exactement 100, et un test le vérifie.** Ce n'est pas de la
 * coquetterie : le score pondéré est présenté au comité comme une note sur 100,
 * et une somme de poids à 95 ou 105 rendrait ce chiffre faux sans que rien ne
 * l'affiche. Une faute de frappe dans un poids est précisément le genre
 * d'erreur qu'aucune relecture ne rattrape.
 *
 * Les éléments d'appréciation ne sont pas décoratifs : ce sont eux qui font que
 * deux évaluateurs notent la même chose. Ils sont repris mot pour mot du §11.2,
 * et l'écran les affiche sous chaque critère plutôt que dans une aide séparée
 * que personne n'ouvre.
 */
enum EvaluationCriterion: string
{
    case RELEVANCE = 'RELEVANCE';
    case INNOVATION = 'INNOVATION';
    case TECHNICAL_FEASIBILITY = 'TECHNICAL_FEASIBILITY';
    case VIABILITY = 'VIABILITY';
    case IMPACT = 'IMPACT';
    case SUSTAINABILITY = 'SUSTAINABILITY';
    case TEAM = 'TEAM';
    case INCLUSION = 'INCLUSION';

    /** La note maximale de l'échelle du §11.3. */
    public const MAX_SCORE = 5;

    /** Le total des poids, et donc l'échelle du score pondéré. */
    public const TOTAL_WEIGHT = 100;

    public function label(): string
    {
        return match ($this) {
            self::RELEVANCE => 'Pertinence par rapport au défi',
            self::INNOVATION => 'Innovation',
            self::TECHNICAL_FEASIBILITY => 'Faisabilité technique',
            self::VIABILITY => 'Viabilité économique et institutionnelle',
            self::IMPACT => 'Impact et résilience',
            self::SUSTAINABILITY => 'Durabilité et mise à l’échelle',
            self::TEAM => 'Équipe',
            self::INCLUSION => 'Inclusion et ancrage territorial',
        };
    }

    /** Le poids du §11.2, en points sur 100. */
    public function weight(): int
    {
        return match ($this) {
            self::RELEVANCE => 20,
            self::INNOVATION => 15,
            self::TECHNICAL_FEASIBILITY => 15,
            self::VIABILITY => 10,
            self::IMPACT => 15,
            self::SUSTAINABILITY => 10,
            self::TEAM => 10,
            self::INCLUSION => 5,
        };
    }

    /** Les éléments d'appréciation du §11.2, mot pour mot. */
    public function elements(): string
    {
        return match ($this) {
            self::RELEVANCE => 'Compréhension du problème, réponse aux besoins municipaux, alignement PIDUREM et thématique.',
            self::INNOVATION => 'Originalité utile, différenciation, usage pertinent du numérique ; l’innovation ne se réduit pas à la nouveauté technologique.',
            self::TECHNICAL_FEASIBILITY => 'Architecture, maturité, compétences, connectivité, sécurité, délais et dépendances.',
            self::VIABILITY => 'Coûts, modèle, capacité de maintenance, adoption et soutenabilité.',
            self::IMPACT => 'Bénéficiaires, résultats mesurables, qualité du service, risques climatiques ou urbains.',
            self::SUSTAINABILITY => 'Interopérabilité, réplication, appropriation et évolution.',
            self::TEAM => 'Complémentarité, expérience, engagement, gouvernance et disponibilité.',
            self::INCLUSION => 'Femmes, jeunes, vulnérabilités, accessibilité et réalités locales.',
        };
    }

    /**
     * Le score pondéré d'une note, en points sur le poids du critère.
     *
     * `note / 5 × poids`. Aucun arrondi ici : l'arrondi appartient à
     * l'affichage et au total, et arrondir huit fois avant de sommer ferait
     * dériver la note finale de plusieurs dixièmes.
     */
    public function weightedScore(int $score): float
    {
        return $this->weight() * $score / self::MAX_SCORE;
    }

    /** La somme des poids. Vaut 100 — c'est la propriété que le test protège. */
    public static function totalWeight(): int
    {
        return array_sum(array_map(static fn (self $critere): int => $critere->weight(), self::cases()));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $critere): string => $critere->value, self::cases());
    }
}
