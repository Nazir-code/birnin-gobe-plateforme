<?php

namespace App\Domain\Eligibility;

/**
 * État d'une règle d'éligibilité pour un jeu de réponses donné.
 *
 * `NOT_CONFIGURED` n'est pas un détail technique : le cahier des charges
 * annonce explicitement (§1.1, §18.3) que la tranche d'âge, les zones et les
 * pièces « restent configurables par campagne » et que le comité de pilotage
 * doit encore les arrêter. Une règle dont le paramètre n'existe pas ne peut ni
 * bloquer ni rassurer — le dire est plus honnête que de deviner un seuil.
 */
enum RuleStatus: string
{
    /** La ou les réponses nécessaires manquent encore. */
    case UNANSWERED = 'UNANSWERED';

    /** La campagne n'a pas fixé le paramètre : la règle ne conclut rien. */
    case NOT_CONFIGURED = 'NOT_CONFIGURED';

    case SATISFIED = 'SATISFIED';

    /** Règle bloquante déclenchée : la suite du formulaire reste fermée. */
    case BLOCKING = 'BLOCKING';
}
