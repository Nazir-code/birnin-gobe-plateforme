<?php

namespace App\Domain\Verification;

/**
 * Gravité d'un verdict de contrôle.
 *
 * Trois niveaux, et la frontière qui compte est entre `ATTENTION` et
 * `BLOCKING` : seul `BLOCKING` peut fonder une irrecevabilité. Ce n'est pas une
 * nuance d'affichage, c'est la garantie du §10.3 rendue exécutable — un
 * signalement automatique se range en `ATTENTION`, et aucun chemin du code ne
 * le transforme en exclusion sans qu'une personne ait coché autre chose.
 *
 * Rien ne persiste cette valeur : elle se lit depuis `VerificationOutcome`.
 */
enum VerificationSeverity: string
{
    /** Le contrôle est passé. */
    case SATISFIED = 'SATISFIED';

    /** Un examen humain est nécessaire ; à lui seul, ce niveau n'exclut personne. */
    case ATTENTION = 'ATTENTION';

    /** Un motif d'irrecevabilité, constaté par une personne. */
    case BLOCKING = 'BLOCKING';
}
