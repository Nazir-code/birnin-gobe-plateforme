<?php

namespace App\Notifications;

use App\Domain\Notification\NotificationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Base des six messages du §8.3.
 *
 * **Mise en file d'attente, à la différence de la réinitialisation de mot de
 * passe.** Celle-ci reste synchrone parce que quelqu'un attend son lien, écran
 * ouvert ; ceux-ci accompagnent un geste déjà accompli — un dépôt, une
 * décision — et rien ne doit ralentir la réponse rendue au candidat. Un serveur
 * SMTP lent ne peut pas faire attendre une soumission.
 *
 * `evenement()` rattache chaque message à sa ligne du tableau du §8.3. Cela sert
 * au répartiteur, qui en tire les canaux, et à la suite de tests, qui vérifie
 * que le contenu produit couvre bien ce que le cahier des charges exige.
 */
abstract class MessageTransactionnel extends Notification implements ShouldQueue
{
    use Queueable;

    abstract public function evenement(): NotificationEvent;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }
}
