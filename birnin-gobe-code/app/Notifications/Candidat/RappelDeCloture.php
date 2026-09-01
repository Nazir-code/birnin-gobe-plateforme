<?php

namespace App\Notifications\Candidat;

use App\Domain\Notification\NotificationEvent;
use App\Models\Application;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Rappel de clôture » — §8.3, ligne 2.
 *
 * Contenu minimum exigé : temps restant, complétude, lien direct.
 *
 * **Les trois éléments servent le même but** : faire revenir quelqu'un qui a
 * commencé et s'est arrêté. Le temps restant crée l'urgence, la complétude dit
 * si l'effort restant est de dix minutes ou de trois heures, et le lien évite
 * d'avoir à retrouver son chemin. Il en manque un et le message devient une
 * inquiétude sans prise.
 *
 * **Le pourcentage vient du domaine**, jamais d'un calcul refait ici : c'est le
 * même chiffre que le candidat voit sur son tableau de bord, et deux chiffres
 * différents pour la même chose feraient douter des deux.
 */
final class RappelDeCloture extends MessageTransactionnel
{
    public function __construct(
        private readonly Application $dossier,
        private readonly int $joursRestants,
    ) {}

    public function evenement(): NotificationEvent
    {
        return NotificationEvent::CLOSING_REMINDER;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cloture = $this->dossier->campaign?->closes_at;
        $reste = $this->joursRestants;

        return (new MailMessage)
            ->subject($reste <= 1
                ? 'Dernier jour pour déposer votre candidature'
                : 'Il vous reste '.$reste.' jours pour déposer votre candidature')
            ->greeting('Bonjour,')
            ->line('**Temps restant** — les candidatures '.($this->dossier->campaign?->name ?? 'BIRNIN GOBE').' ferment '
                .($cloture !== null ? 'le '.$cloture->timezone(config('app.timezone'))->format('d/m/Y à H\hi') : 'prochainement')
                .', soit dans '.$reste.' jour'.($reste > 1 ? 's' : '').'.')
            ->line('**Où vous en êtes** — votre dossier est renseigné à '.$this->dossier->completion_percent.' %. '
                .($this->dossier->completion_percent >= 80
                    ? 'Il ne vous reste presque rien à compléter.'
                    : 'Les sections non terminées sont signalées dans votre espace.'))
            ->action('Terminer ma candidature', url('/candidate/dashboard'))
            ->line('Un dossier non déposé à la clôture n’est pas examiné, même complet. Le dépôt est un geste explicite : pensez à le confirmer.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
