<?php

namespace App\Domain\Alerting;

/**
 * Gravité d'une alerte de pilotage.
 *
 * Trois niveaux, et le nombre est le sujet : un écran d'alertes où tout est
 * urgent ne signale plus rien. `CRITICAL` est réservé à ce qui a déjà des
 * conséquences pour un candidat ou pour le calendrier — un délai de réponse
 * dépassé, une clôture franchie avec des dossiers non traités. `WARNING`
 * annonce ce qui le deviendra. `INFO` est une observation qui n'appelle pas de
 * geste aujourd'hui.
 */
enum AlertSeverity: string
{
    case CRITICAL = 'CRITICAL';
    case WARNING = 'WARNING';
    case INFO = 'INFO';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critique',
            self::WARNING => 'À surveiller',
            self::INFO => 'Information',
        };
    }

    /** Ordre d'affichage : le plus grave d'abord. */
    public function rang(): int
    {
        return match ($this) {
            self::CRITICAL => 0,
            self::WARNING => 1,
            self::INFO => 2,
        };
    }
}
