<?php

namespace App\Notifications;

use App\Domain\Auth\UserRole;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation à définir le mot de passe d'un compte interne — ADR-022.
 *
 * **Hors du tableau du §8.3, comme la réinitialisation de mot de passe.**
 * `NotificationEvent` recense les six événements du cahier des charges, et un
 * test compare l'enum à cette liste : une invitation n'y figure pas, et l'y
 * ajouter ferait mentir la correspondance qu'ADR-018 a établie. Ce message
 * appartient au parcours d'authentification, pas au suivi d'une candidature —
 * exactement comme `ReinitialisationMotDePasse`, qui suit la même règle.
 *
 * **Il ne dit pas « vous avez demandé ».** Personne n'a rien demandé : c'est un
 * administrateur qui a créé le compte. Reprendre les mots de la
 * réinitialisation ferait croire le destinataire victime d'une usurpation, ou
 * lui ferait ignorer le message comme une erreur.
 *
 * **Il nomme le rôle, et rien du concours.** Savoir qu'on est évaluateur est
 * nécessaire pour comprendre le message ; le reste — dossiers, campagne,
 * candidats — n'a rien à faire dans un courriel, qui peut se retrouver sous
 * d'autres yeux que ceux de son destinataire. C'est la règle que suit déjà
 * `ReinitialisationMotDePasse`.
 *
 * **Pas de mise en file d'attente.** Un administrateur qui crée un compte
 * attend, écran ouvert, de pouvoir dire à la personne que le message est parti.
 * Le confier à un `worker` ferait dépendre d'un processus en vie la seule
 * chose qui ouvre l'accès au compte — et cette session l'a vérifié dans les
 * faits : un `worker` sur image périmée a laissé des notifications en file
 * pendant des heures sans que rien ne l'annonce.
 */
final class InvitationCompteInterne extends Notification
{
    public function __construct(
        private readonly string $jeton,
        private readonly UserRole $role,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jours = (int) round(config('auth.passwords.invitations.expire', 60 * 24 * 7) / (60 * 24));

        $lien = url(route('invitation.create', [
            'token' => $this->jeton,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));

        return (new MailMessage)
            ->subject('Votre accès '.$this->role->label().' — BIRNIN GOBE')
            ->greeting('Bonjour,')
            ->line('Un accès '.$this->role->label().' vient d’être créé pour vous sur la plateforme BIRNIN GOBE.')
            // Le mot de passe n'existe pas encore : le dire évite qu'on cherche
            // un identifiant provisoire dans un autre message.
            ->line('Aucun mot de passe ne vous a été attribué : vous le définissez vous-même, et vous serez seul à le connaître.')
            ->action('Définir mon mot de passe', $lien)
            ->line('Ce lien est valable '.$jours.' jours et ne peut servir qu’une fois.')
            ->line('Passé ce délai, utilisez « mot de passe oublié » depuis l’écran de connexion pour en recevoir un nouveau.')
            ->salutation('L’équipe BIRNIN GOBE');
    }
}
