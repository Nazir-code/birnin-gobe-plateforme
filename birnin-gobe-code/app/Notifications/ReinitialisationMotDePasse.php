<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Lang;

/**
 * Le courriel de réinitialisation de mot de passe.
 *
 * Il remplace la notification native de Laravel pour trois raisons, et
 * seulement trois :
 *
 * 1. **La langue.** La plateforme s'adresse à des candidats nigériens en
 *    français ; le message par défaut est en anglais.
 *
 * 2. **Le lien.** Le défaut pointe vers une route nommée `password.reset` que
 *    Laravel construit pour son propre échafaudage. Ici l'écran est une page
 *    Inertia, et l'adresse doit porter le jeton **et** l'adresse e-mail —
 *    cette dernière parce que le vérificateur de jeton en a besoin et qu'on ne
 *    peut pas la demander à quelqu'un qui vient de cliquer sur un lien.
 *
 * 3. **La durée de validité, écrite dans le message.** Elle vient de
 *    `config/auth.php` et non d'un texte figé : le jour où elle change, le
 *    courriel dit la vérité sans qu'on y touche. Un lien qui expire sans
 *    prévenir se lit comme une panne.
 *
 * Ce que ce message **ne dit pas** : rien sur le compte, ni le nom, ni l'état du
 * dossier. Un courriel peut se retrouver sous d'autres yeux que ceux de son
 * destinataire.
 *
 * Le message n'est pas mis en file d'attente. La réinitialisation est
 * synchrone du point de vue de la personne qui attend son lien, et
 * `QUEUE_CONNECTION=redis` ferait dépendre l'envoi d'un worker en vie —
 * une panne silencieuse de plus pour la seule fonctionnalité qui rend l'accès
 * à un compte perdu.
 */
final class ReinitialisationMotDePasse extends Notification
{
    use Queueable;

    public function __construct(private readonly string $jeton) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(Lang::get('Réinitialisation de votre mot de passe — BIRNIN GOBE'))
            ->greeting(Lang::get('Bonjour,'))
            ->line(Lang::get('Vous recevez ce message parce qu’une réinitialisation de mot de passe a été demandée pour votre compte BIRNIN GOBE.'))
            ->action(Lang::get('Choisir un nouveau mot de passe'), $this->lien($notifiable))
            ->line(Lang::get('Ce lien expire dans :minutes minutes.', ['minutes' => $minutes]))
            // Le rassurer explicitement : recevoir ce message sans l'avoir
            // demandé n'est pas un incident, et il n'y a rien à faire.
            ->line(Lang::get('Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.'))
            ->salutation(Lang::get('L’équipe BIRNIN GOBE'));
    }

    /**
     * L'adresse de l'écran de réinitialisation.
     *
     * Le jeton voyage dans le chemin et l'adresse e-mail en paramètre, comme
     * l'attend `Password::reset()`. L'URL est absolue et construite sur
     * `APP_URL` : un lien relatif dans un courriel ne mène nulle part.
     */
    private function lien(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->jeton,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));
    }
}
