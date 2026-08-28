<?php

namespace App\Domain\Audit;

/**
 * Poids d'un événement du journal.
 *
 * Sert uniquement à l'accent visuel de la liste. Aucune décision métier n'en
 * dépend, et rien ne le persiste : c'est une lecture de `AuditAction`, pas une
 * colonne.
 */
enum AuditWeight: string
{
    /** Irréversible, ou visible des candidats. */
    case DECISIVE = 'DECISIVE';

    /** Une modification qui mérite qu'on la retrouve. */
    case NOTABLE = 'NOTABLE';

    /** Le cours normal du parcours. */
    case ROUTINE = 'ROUTINE';
}
