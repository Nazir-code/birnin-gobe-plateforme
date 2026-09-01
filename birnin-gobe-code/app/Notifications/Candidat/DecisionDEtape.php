<?php

namespace App\Notifications\Candidat;

use App\Domain\Notification\NotificationEvent;
use App\Domain\Verification\AdmissibilityDecision;
use App\Models\Application;
use App\Models\VerificationDecision;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Décision d'étape » — §8.3, ligne 5.
 *
 * Contenu minimum exigé : statut, suite, convocation ou information appropriée.
 *
 * **Le statut est dit en français, la suite est dite explicitement.** Un
 * candidat déclaré irrecevable qui ne lit que « statut : INADMISSIBLE » n'a rien
 * appris : il lui faut savoir ce que cela signifie pour lui, et ce qu'il peut
 * faire ensuite. Les deux cas ne se ressemblent pas et n'ont pas le même ton.
 *
 * **Le motif de rejet n'est pas envoyé tel qu'il est codifié.** Le §10.3 exige
 * un message candidat distinct, et c'est lui qui part — pas le libellé du
 * contrôle bloquant, qui est un terme d'instruction interne.
 */
final class DecisionDEtape extends MessageTransactionnel
{
    public function __construct(
        private readonly Application $dossier,
        private readonly VerificationDecision $decision,
    ) {}

    public function evenement(): NotificationEvent
    {
        return NotificationEvent::STAGE_DECISION;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $recevable = $this->decision->decision === AdmissibilityDecision::ADMISSIBLE;
        $reference = $this->dossier->submission_number ?? '';

        $message = (new MailMessage)
            ->subject(($recevable ? 'Votre candidature est recevable' : 'Suite donnée à votre candidature').' — '.($reference ?: 'BIRNIN GOBE'))
            ->greeting('Bonjour,')
            ->line('**Statut de votre dossier '.$reference.'** : '.$this->dossier->status->label().'.');

        $candidat = trim((string) $this->decision->candidate_message);

        if ($candidat !== '') {
            $message->line($candidat);
        }

        if ($recevable) {
            return $message
                ->line('**La suite** — votre dossier entre en phase d’évaluation. Il sera examiné par des évaluateurs indépendants sur la grille publiée avec l’appel à candidatures. Vous n’avez aucune démarche à faire ; vous serez informé du résultat par ce même canal.')
                ->action('Suivre mon dossier', url('/candidate/dashboard'))
                ->salutation('L’équipe BIRNIN GOBE');
        }

        return $message
            ->line('**La suite** — votre dossier ne poursuit pas le processus pour cette édition. Cette décision porte sur la recevabilité, et ne préjuge en rien de la valeur de votre projet ni de vos prochaines candidatures.')
            ->line('Si vous estimez que cette décision repose sur une erreur, écrivez à '.config('mail.from.address').' en rappelant votre numéro de dépôt.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
