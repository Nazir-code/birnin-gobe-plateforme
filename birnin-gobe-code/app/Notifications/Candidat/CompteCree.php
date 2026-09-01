<?php

namespace App\Notifications\Candidat;

use App\Domain\Notification\NotificationEvent;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * « Compte créé » — §8.3, ligne 1.
 *
 * Contenu minimum exigé : vérification, sécurité, lien de reprise.
 *
 * **La ligne « vérification » est tenue autrement que le §8.3 ne l'imagine.**
 * Le cahier des charges suppose un lien de vérification d'adresse ; la
 * plateforme n'a pas encore ce parcours, et fabriquer un lien qui ne vérifie
 * rien serait pire que de ne rien envoyer. Le message dit donc l'état réel :
 * l'adresse a été enregistrée, et c'est elle qui servira à joindre le candidat —
 * ce qui est précisément l'information dont il a besoin pour la corriger si
 * elle est fausse.
 */
final class CompteCree extends MessageTransactionnel
{
    public function evenement(): NotificationEvent
    {
        return NotificationEvent::ACCOUNT_CREATED;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre compte BIRNIN GOBE est ouvert')
            ->greeting('Bonjour,')
            ->line('Votre compte candidat BIRNIN GOBE a été créé. Cette adresse est celle qui servira à vous joindre pour toutes les étapes du concours : si elle est inexacte, corrigez-la depuis votre profil dès maintenant.')
            ->line('**Sécurité** — nous ne vous demanderons jamais votre mot de passe, ni par courriel, ni par téléphone. Aucun agent n’est habilité à le recueillir. Si l’on vous le demande, ne répondez pas et signalez-le.')
            ->action('Reprendre ma candidature', url('/candidate/dashboard'))
            ->line('Ce lien de reprise vous ramène à votre dossier là où vous l’avez laissé. Vos réponses sont enregistrées au fil de la saisie : vous pouvez fermer la page et revenir plus tard sans rien perdre.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
