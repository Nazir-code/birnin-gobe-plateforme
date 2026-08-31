<?php

namespace App\Listeners;

use App\Domain\Notification\DeliveryStatus;
use App\Models\NotificationDelivery;
use App\Notifications\MessageTransactionnel;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Un message du §8.3 est réellement parti : sa trace peut se refermer.
 *
 * **Pourquoi un écouteur, et non un retour de `SendNotification`.** L'envoi
 * n'a pas lieu là où il est demandé : le cas d'usage confie le message au
 * répartiteur et rend la main, et c'est un `worker`, dans un autre processus,
 * quelques secondes plus tard, qui parle au serveur SMTP. Seul cet écouteur se
 * trouve au bon endroit pour dire « ça, c'est parti » — `SendNotification`, lui,
 * ne peut honnêtement dire que « ça, c'est confié ».
 *
 * **Il ne referme que ce que `SendNotification` a ouvert.** La réinitialisation
 * de mot de passe passe par le même événement Laravel sans être au tableau du
 * §8.3 : elle n'a pas de trace, et n'en veut pas. Le filtre est le type du
 * message, pas une liste de classes à tenir à jour.
 */
final class RefermerLaTraceDEnvoi
{
    public function handle(NotificationSent $evenement): void
    {
        $message = $evenement->notification;

        if (! $message instanceof MessageTransactionnel) {
            return;
        }

        NotificationDelivery::refermer($message->traceId, DeliveryStatus::SENT);
    }
}
