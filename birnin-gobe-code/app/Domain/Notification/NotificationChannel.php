<?php

namespace App\Domain\Notification;

/**
 * Les canaux d'envoi du §8.3.
 *
 * `servi()` distingue ce que le cahier des charges demande de ce que la
 * plateforme sait faire aujourd'hui. Le SMS est déclaré partout où le §8.3 le
 * réclame, et n'est servi nulle part : aucun fournisseur n'est choisi, et ce
 * n'est pas une décision technique — elle engage un opérateur, une identité
 * d'expéditeur, un coût par message et quelqu'un pour lire les réponses.
 *
 * Le jour où un fournisseur existera, `servi()` le dira et rien d'autre ne
 * bougera : les événements déclarent déjà leurs canaux, et les livraisons non
 * servies sont déjà enregistrées comme telles.
 */
enum NotificationChannel: string
{
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Courriel',
            self::SMS => 'SMS',
        };
    }

    /** Un envoi est-il réellement possible sur ce canal ? */
    public function servi(): bool
    {
        return match ($this) {
            self::EMAIL => true,
            self::SMS => (bool) config('notifications.sms.enabled', false),
        };
    }

    /** Pourquoi ce canal ne part pas, dit à l'écran de pilotage. */
    public function raisonDIndisponibilite(): ?string
    {
        return match ($this) {
            self::EMAIL => null,
            self::SMS => $this->servi()
                ? null
                : 'Aucun fournisseur SMS n’est configuré : opérateur, identité d’expéditeur et coût par message restent à arbitrer.',
        };
    }
}
