<?php

namespace App\Domain\Eligibility;

/**
 * Résultat global de l'auto-test d'éligibilité.
 *
 * Résultat **indicatif**, comme l'exige le cahier des charges (§5.2 « résultat
 * indicatif ») et comme l'annonce déjà l'interface. Il ne préjuge pas de
 * l'admissibilité, qui est une décision administrative prise plus tard par un
 * vérificateur sur pièces (§10.2) : ce sont deux concepts distincts, et cet
 * enum ne doit jamais être écrit dans `ApplicationStatus`.
 */
enum EligibilityOutcome: string
{
    /** Toutes les questions n'ont pas encore de réponse. */
    case INCOMPLETE = 'INCOMPLETE';

    /** Aucune règle bloquante, aucun paramètre manquant. */
    case ELIGIBLE = 'ELIGIBLE';

    /**
     * Rien ne bloque, mais au moins un paramètre de campagne n'est pas arrêté.
     * Le candidat poursuit : §5.2 « possibilité de poursuivre tant qu'aucune
     * règle bloquante n'est validée ».
     */
    case TO_CONFIRM = 'TO_CONFIRM';

    /** Au moins une règle bloquante est déclenchée. */
    case INELIGIBLE = 'INELIGIBLE';

    public function label(): string
    {
        return match ($this) {
            self::INCOMPLETE => 'Réponses incomplètes',
            self::ELIGIBLE => 'Vous remplissez les conditions',
            self::TO_CONFIRM => 'Vous pouvez poursuivre, sous réserve',
            self::INELIGIBLE => 'Vous ne remplissez pas les conditions',
        };
    }

    /**
     * Seul le résultat bloquant ferme la suite du formulaire.
     *
     * Un dossier incomplet ou en attente d'un paramètre de campagne n'est pas
     * un refus : le candidat continue de remplir son dossier.
     */
    public function blocksNextSections(): bool
    {
        return $this === self::INELIGIBLE;
    }
}
