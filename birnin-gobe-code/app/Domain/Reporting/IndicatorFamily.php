<?php

namespace App\Domain\Reporting;

/**
 * Les familles d'indicateurs du §13.1.
 *
 * Les six familles du tableau sont reprises telles quelles, y compris celles
 * que la plateforme **ne sait pas encore renseigner**. C'est délibéré : une
 * famille absente de l'écran se lirait comme une famille qui n'existe pas, et
 * le pilotage croirait avoir une vue complète. Une famille présente mais
 * annoncée « pas encore mesurée » dit exactement ce qui est vrai, et pointe ce
 * qu'il reste à instrumenter.
 *
 * `disponible()` porte cette distinction, et le §13.4 la justifie : chaque
 * indicateur doit avoir « une source ». Une famille sans source ne peut rien
 * afficher.
 */
enum IndicatorFamily: string
{
    case MOBILISATION = 'MOBILISATION';
    case CANDIDATURES = 'CANDIDATURES';
    case ADMISSIBILITE = 'ADMISSIBILITE';
    case EVALUATION = 'EVALUATION';
    case FINALE = 'FINALE';
    case QUALITE_DE_SERVICE = 'QUALITE_DE_SERVICE';

    public function label(): string
    {
        return match ($this) {
            self::MOBILISATION => 'Mobilisation',
            self::CANDIDATURES => 'Candidatures',
            self::ADMISSIBILITE => 'Admissibilité',
            self::EVALUATION => 'Évaluation',
            self::FINALE => 'Finale',
            self::QUALITE_DE_SERVICE => 'Qualité de service',
        };
    }

    /**
     * La plateforme sait-elle renseigner cette famille aujourd'hui ?
     *
     * Trois ne le sont pas, et chacune pour une raison nette :
     *
     *  - **Mobilisation** demande une mesure d'audience (visites, sources de
     *    trafic, clics). Aucune n'est installée, et il n'en sera pas inventé :
     *    compter les visites suppose un choix de traitement de données
     *    personnelles qui n'a pas été arbitré ;
     *  - **Finale** suppose le §12 — convocations, présences, décisions de jury —
     *    dont rien n'existe ;
     *  - **Qualité de service** demande une supervision applicative
     *    (disponibilité, temps de réponse, échecs de notification) qui vit dans
     *    l'exploitation, pas dans la base métier.
     */
    public function disponible(): bool
    {
        return match ($this) {
            self::CANDIDATURES, self::ADMISSIBILITE, self::EVALUATION => true,
            self::MOBILISATION, self::FINALE, self::QUALITE_DE_SERVICE => false,
        };
    }

    /** Pourquoi la famille n'est pas mesurée. Vide quand elle l'est. */
    public function raisonDIndisponibilite(): ?string
    {
        return match ($this) {
            self::MOBILISATION => 'Aucune mesure d’audience n’est installée. Visites, sources de trafic et clics supposent un traitement de données de navigation qui n’a pas été arbitré.',
            self::FINALE => 'La sélection finale (§12) n’est pas implémentée : ni convocation, ni présence, ni décision de jury n’existe en base.',
            self::QUALITE_DE_SERVICE => 'Disponibilité, temps de réponse et échecs de notification relèvent de la supervision d’exploitation, pas de la base métier.',
            default => null,
        };
    }
}
