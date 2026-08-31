<?php

namespace App\Notifications;

use App\Domain\Notification\DeliveryStatus;
use App\Domain\Notification\NotificationEvent;
use App\Models\NotificationDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Throwable;

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
 *
 * **`$traceId` voyage avec le message, et c'est ce qui rend la file honnête.**
 * La mise en file sépare le geste — écrire la trace — de son issue, qui tombe
 * ailleurs, plus tard, dans un autre processus. Sans identifiant embarqué dans
 * la charge sérialisée, le répartiteur n'aurait aucun moyen de retrouver la
 * ligne à refermer, et la trace en resterait à « confié » pour toujours.
 */
abstract class MessageTransactionnel extends Notification implements ShouldQueue
{
    use Queueable;

    /** La ligne de `notification_deliveries` que cet envoi doit refermer. */
    public ?int $traceId = null;

    abstract public function evenement(): NotificationEvent;

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Le message a épuisé ses tentatives — §9.3.
     *
     * Appelée par `SendQueuedNotifications::failed()`, donc **une seule fois,
     * après le dernier essai**. C'est la raison de ne pas écouter
     * `NotificationFailed`, qui est émis à chaque tentative : un serveur SMTP
     * qui bafouille une fois puis répond allumerait une alerte `CRITICAL` que
     * la réussite suivante éteindrait, et un responsable qui voit ce compteur
     * clignoter sans conséquence apprend à ne plus le regarder. On n'alerte que
     * sur ce qui est acquis.
     */
    public function failed(Throwable $erreur): void
    {
        NotificationDelivery::refermer($this->traceId, DeliveryStatus::FAILED, $erreur->getMessage());
    }
}
