<?php

namespace App\Notifications\Candidat;

use App\Domain\Notification\NotificationEvent;
use App\Models\Application;
use App\Models\VerificationDecision;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Clarification demandée » — §8.3, ligne 4.
 *
 * Contenu minimum exigé : point précis, délai, canal de réponse.
 *
 * **Le « point précis » est celui que le vérificateur a écrit**, repris tel
 * quel depuis `candidate_message`. Le §10.3 impose déjà que ce texte soit
 * distinct de l'observation interne, précisément pour qu'il puisse être envoyé.
 * Le reformuler ici en ferait une seconde version, et c'est la version envoyée
 * qui engagera l'administration.
 *
 * **Aucune observation interne ne figure dans ce message**, jamais. Le §10.3
 * sépare les deux pour éviter la divulgation d'informations sensibles, et cette
 * séparation ne vaut que si elle est tenue au moment de l'envoi.
 */
final class ClarificationDemandee extends MessageTransactionnel
{
    public function __construct(
        private readonly Application $dossier,
        private readonly VerificationDecision $decision,
    ) {}

    public function evenement(): NotificationEvent
    {
        return NotificationEvent::CLARIFICATION_REQUESTED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $echeance = $this->decision->respond_by;

        $message = (new MailMessage)
            ->subject('Complément demandé sur votre candidature — '.($this->dossier->submission_number ?? 'BIRNIN GOBE'))
            ->greeting('Bonjour,')
            ->line('L’examen de votre dossier '.($this->dossier->submission_number ?? '').' appelle un complément de votre part.')
            ->line('**Ce qui est demandé** — '.trim((string) $this->decision->candidate_message));

        if ($echeance !== null) {
            $message->line('**Délai de réponse** — vous avez jusqu’au '
                .$echeance->timezone(config('app.timezone'))->format('d/m/Y')
                .'. Passé cette date, le dossier est examiné en l’état.');
        }

        return $message
            ->action('Ouvrir mon dossier', url('/candidate/dashboard'))
            ->line('**Comment répondre** — répondez depuis votre espace candidat, en corrigeant la section ou en redéposant la pièce concernée. N’envoyez pas de pièce par courriel : elle ne serait pas rattachée à votre dossier.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
