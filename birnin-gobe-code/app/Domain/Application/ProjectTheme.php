<?php

namespace App\Domain\Application;

/**
 * Les quatre thématiques officielles du concours.
 *
 * **Source de vérité unique.** Elles vivaient dans une méthode privée de
 * `HomeController`, qui les servait au portail. Le jour où la candidature a dû
 * demander au candidat sous quelle thématique il concourt, la question s'est
 * posée : recopier la liste, ou l'extraire. Recopier aurait laissé le portail
 * annoncer quatre thématiques et le formulaire en proposer quatre autres le
 * jour où l'une changerait de libellé — et personne ne l'aurait vu avant qu'un
 * dossier soit rangé sous une thématique qui n'existe plus.
 *
 * Le portail, le formulaire du candidat et l'administration lisent donc tous
 * cette enum. Il n'y a plus qu'un endroit à modifier, et il est typé.
 *
 * **Les valeurs persistées sont des codes stables**, jamais les libellés. Un
 * dossier enregistre `foncier`, pas « Gestion foncière et cadastrale » : le
 * libellé peut être reformulé sans réécrire une seule ligne en base, et le
 * contrat du dépôt — aucun libellé français comme valeur métier — est tenu.
 * Ces quatre codes sont exactement ceux que le portail servait déjà : les
 * changer aurait modifié le contenu public, ce que cette phase s'interdit.
 *
 * **Ce n'est pas un critère d'éligibilité.** La thématique dit de quoi parle le
 * projet ; l'éligibilité dit qui a le droit de déposer. `EvaluateEligibility`
 * n'en sait rien et ne doit rien en savoir.
 *
 * Le texte des problèmes et des résultats attendus vient du document du
 * concours, mot pour mot. Il est ici parce que le portail l'affiche déjà ; le
 * jour où un CMS existera, seule cette classe changera.
 */
enum ProjectTheme: string
{
    case URBAN_MANAGEMENT = 'gestion-urbaine';
    case LAND_REGISTRY = 'foncier';
    case CIVIL_REGISTRY = 'etat-civil';
    case MAPPING_RESILIENCE = 'cartographie';

    /** Intitulé officiel, tel qu'il figure au document du concours. */
    public function label(): string
    {
        return match ($this) {
            self::URBAN_MANAGEMENT => 'Gestion urbaine et services de base',
            self::LAND_REGISTRY => 'Gestion foncière et cadastrale',
            self::CIVIL_REGISTRY => 'État civil et services administratifs',
            self::MAPPING_RESILIENCE => 'Cartographie, géolocalisation, risques et résilience',
        };
    }

    /** Ce que le candidat doit résoudre. */
    public function problems(): string
    {
        return match ($this) {
            self::URBAN_MANAGEMENT => 'Signalement et suivi des déchets, voirie, caniveaux, éclairage, équipements, interventions et relation citoyenne.',
            self::LAND_REGISTRY => 'Dossiers dispersés, recherche lente, doublons, localisation difficile, faible suivi des étapes et litiges.',
            self::CIVIL_REGISTRY => 'Accueil, complétude, archivage, recherche, suivi des demandes, délais et statistiques.',
            self::MAPPING_RESILIENCE => 'Adressage, inventaire des actifs, zones inondables, ouvrages, ressources mobiles, alertes et décisions d’urgence.',
        };
    }

    /** Ce qu'on attend qu'il produise. */
    public function results(): string
    {
        return match ($this) {
            self::URBAN_MANAGEMENT => 'Collecte terrain, priorisation, affectation, traçabilité et tableau de bord opérationnel.',
            self::LAND_REGISTRY => 'Indexation sécurisée, recherche multicritère, suivi des demandes, cartographie interne et audit.',
            self::CIVIL_REGISTRY => 'Orientation des usagers, suivi interne, alertes, archivage sécurisé et amélioration des délais.',
            self::MAPPING_RESILIENCE => 'Données géoréférencées fiables, usages hors ligne, cartes opérationnelles et aide à la décision.',
        };
    }

    /**
     * Les quatre choix, pour un formulaire ou un filtre.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $theme): array => ['value' => $theme->value, 'label' => $theme->label()],
            self::cases(),
        );
    }

    /**
     * Le contenu complet, pour le portail public.
     *
     * Même forme que celle qu'il servait auparavant — `key`, `title`,
     * `problems`, `results` — et même ordre, de la première à la quatrième
     * thématique. La page ne change pas d'un caractère.
     *
     * @return list<array{key: string, title: string, problems: string, results: string}>
     */
    public static function content(): array
    {
        return array_map(
            static fn (self $theme): array => [
                'key' => $theme->value,
                'title' => $theme->label(),
                'problems' => $theme->problems(),
                'results' => $theme->results(),
            ],
            self::cases(),
        );
    }
}
