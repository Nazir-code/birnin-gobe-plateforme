<?php

namespace App\Domain\Notification;

/**
 * L'issue d'une tentative d'envoi — §8.3.
 *
 * Quatre états, et deux distinctions portent tout le sens.
 *
 * **« Tenté et échoué » n'est pas « jamais tenté ».** Un SMS qui ne part pas
 * faute de fournisseur n'est pas une panne — c'est une fonctionnalité absente,
 * et la compter comme un échec noierait les vraies pannes dans un bruit
 * permanent. C'est la règle qu'ADR-014 pose pour les alertes : un compteur qui
 * ne descend jamais apprend à ignorer l'écran.
 *
 * **« Confié » n'est pas « parti ».** Les six messages du §8.3 sont mis en file
 * d'attente : au moment où le cas d'usage rend la main, personne n'a encore
 * parlé à un serveur SMTP. Écrire `SENT` là serait un mensonge utile à
 * personne — il ferait dire à la trace « le candidat a été prévenu » alors que
 * le message pouvait encore échouer, et laisserait l'alerte du §9.3 à zéro
 * pendant exactement la panne qu'elle est censée signaler. La ligne naît donc
 * `QUEUED`, et c'est le processus qui a réellement tenté l'envoi qui la
 * referme.
 */
enum DeliveryStatus: string
{
    case QUEUED = 'QUEUED';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    case SKIPPED = 'SKIPPED';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'En attente d’envoi',
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

    /** L'issue est-elle connue ? `QUEUED` est le seul état qui attend encore. */
    public function estClos(): bool
    {
        return $this !== self::QUEUED;
    }

    /**
     * Le message a-t-il été pris en charge, au point de ne pas le renvoyer ?
     *
     * Sert à la garde du rappel de clôture. Un message confié au répartiteur
     * compte : le renvoyer parce qu'il n'est pas encore parti produirait deux
     * courriels dès que la file prend une minute de retard, et c'est
     * précisément ce que la garde existe pour éviter. Un échec, lui, n'est pas
     * une prise en charge — il mérite d'être retenté, sinon la panne d'un soir
     * prive définitivement quelqu'un de son rappel.
     */
    public function vautPourUnEnvoi(): bool
    {
        return $this === self::SENT || $this === self::QUEUED;
    }
}
