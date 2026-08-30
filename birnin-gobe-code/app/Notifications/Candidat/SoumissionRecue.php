<?php

namespace App\Notifications\Candidat;

use App\Domain\Notification\NotificationEvent;
use App\Models\Application;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Soumission reçue » — §8.3, ligne 3.
 *
 * Contenu minimum exigé : numéro, date, campagne, résumé, contact.
 *
 * **C'est le message le plus important des six**, parce qu'il fait office de
 * preuve de dépôt. Un candidat qui conteste une exclusion produira ce courriel :
 * il doit donc porter le numéro et l'horodatage tels qu'ils sont en base, sans
 * reformulation.
 *
 * Le §8.3 demande « Email + reçu », donc un document. L'accusé téléchargeable
 * n'existe pas encore — voir ce qui reste ouvert dans ADR-018 — et ce message
 * en tient lieu en attendant : il contient les mêmes éléments, et un courriel
 * horodaté par le serveur d'envoi est déjà opposable.
 */
final class SoumissionRecue extends MessageTransactionnel
{
    public function __construct(private readonly Application $dossier) {}

    public function evenement(): NotificationEvent
    {
        return NotificationEvent::SUBMISSION_RECEIVED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $depot = $this->dossier->submitted_at;

        return (new MailMessage)
            ->subject('Candidature déposée — '.($this->dossier->submission_number ?? 'BIRNIN GOBE'))
            ->greeting('Bonjour,')
            ->line('Votre candidature a bien été reçue. Conservez ce message : il atteste de votre dépôt.')
            ->line('**Numéro de dépôt** : '.($this->dossier->submission_number ?? '—'))
            ->line('**Date et heure** : '.($depot?->timezone(config('app.timezone'))->format('d/m/Y à H\hi') ?? '—'))
            ->line('**Campagne** : '.($this->dossier->campaign?->name ?? '—'))
            ->line('**Résumé** — dossier complet, '.$this->dossier->completion_percent.' % des sections renseignées, déposé au nom de '.($notifiable->name ?? '—').'.')
            ->action('Revoir mon dossier déposé', url('/candidate/dashboard'))
            ->line('Votre dossier n’est plus modifiable. Il entre maintenant en contrôle de recevabilité ; vous serez informé de la suite par ce même canal.')
            ->line('**Contact** — pour toute question sur ce dépôt, écrivez à '.config('mail.from.address').' en rappelant votre numéro.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
