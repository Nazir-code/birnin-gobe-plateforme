<?php

namespace App\Domain\Notification;

/**
 * L'issue d'une tentative d'envoi — §8.3.
 *
 * Trois états, et la distinction entre les deux derniers est celle qui compte :
 * **« tenté et échoué » n'est pas « jamais tenté »**. Un SMS qui ne part pas
 * faute de fournisseur n'est pas une panne — c'est une fonctionnalité absente,
 * et la compter comme un échec noierait les vraies pannes dans un bruit
 * permanent. C'est la même règle qu'ADR-014 pour les alertes : un compteur qui
 * ne descend jamais apprend à ignorer l'écran.
 */
enum DeliveryStatus: string
{
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Envoyée',
            self::FAILED => 'Échec d’envoi',
            self::SKIPPED => 'Non envoyée',
        };
    }

    /** Un échec appelle un geste ; une absence de fournisseur appelle une décision. */
    public function estUnIncident(): bool
    {
        return $this === self::FAILED;
    }
}
