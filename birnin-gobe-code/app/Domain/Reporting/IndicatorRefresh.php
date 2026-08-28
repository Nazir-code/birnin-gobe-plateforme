<?php

namespace App\Domain\Reporting;

/**
 * Fréquence de rafraîchissement d'un indicateur — §13.4.
 *
 * Tous les indicateurs de cet écran sont en `LIVE` : ils sont calculés à la
 * lecture, par une requête. Aucun n'est pré-agrégé, et l'enum existe pour que
 * ce fait soit **dit** plutôt que supposé — le jour où une famille passera par
 * une table d'agrégats nocturne, sa fréquence changera ici, et l'écran cessera
 * de laisser croire au temps réel.
 */
enum IndicatorRefresh: string
{
    /** Calculé à chaque affichage. */
    case LIVE = 'LIVE';

    /** Agrégé par une tâche planifiée. Aucun indicateur ne l'utilise encore. */
    case DAILY = 'DAILY';

    public function label(): string
    {
        return match ($this) {
            self::LIVE => 'Calculé à l’affichage',
            self::DAILY => 'Agrégé une fois par jour',
        };
    }
}
