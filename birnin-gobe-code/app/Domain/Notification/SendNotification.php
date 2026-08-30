<?php

namespace App\Domain\Notification;

use App\Models\Application;
use App\Models\Campaign;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifier;
use Throwable;

/**
 * Le point de passage unique des notifications du §8.3.
 *
 * **Aucun cas d'usage n'appelle `Notification::send()` directement**, et c'est
 * la propriété principale de cette classe. Trois choses doivent arriver à
 * chaque envoi — choisir les canaux réellement servis, tenter, et enregistrer
 * ce qui s'est passé — et les recopier à six endroits garantirait qu'un
 * événement finisse un jour par partir sans laisser de trace. C'est le même
 * raisonnement que pour `StoreApplicationDocument::servir()` : une règle
 * transversale vit à un seul endroit.
 *
 * **Un envoi ne fait jamais échouer ce qui l'a déclenché.** Un serveur SMTP
 * indisponible ne doit pas empêcher un dépôt de candidature ni une décision
 * d'admissibilité : le geste métier est fait, committé, et la notification est
 * une conséquence. L'échec est donc attrapé, enregistré en `FAILED`, et
 * l'alerte du §9.3 le remonte — plutôt que de remonter une exception à un
 * candidat qui n'y peut rien.
 *
 * **Ce qui est enregistré n'est pas le message.** La trace dit qui, quoi, quel
 * canal, quand, et pourquoi si ça a échoué. Elle ne conserve pas le contenu :
 * ce serait une seconde copie de données personnelles, à protéger et à purger,
 * pour une question — « qu'y avait-il dedans ? » — à laquelle le gabarit répond
 * déjà.
 */
final readonly class SendNotification
{
    /**
     * Envoie un événement à un destinataire, sur tous les canaux qu'il déclare.
     *
     * @return list<NotificationDelivery> une ligne par canal tenté ou ignoré
     */
    public function handle(
        NotificationEvent $evenement,
        User $destinataire,
        LaravelNotification $message,
        ?Application $dossier = null,
        ?Campaign $campagne = null,
    ): array {
        $traces = [];

        foreach ($evenement->channels() as $canal) {
            $traces[] = $canal === NotificationChannel::EMAIL
                ? $this->parCourriel($evenement, $canal, $destinataire, $message, $dossier, $campagne)
                : $this->nonServi($evenement, $canal, $destinataire, $dossier, $campagne);
        }

        return $traces;
    }

    /**
     * Le destinataire a-t-il déjà reçu cet événement ?
     *
     * Sert au rappel de clôture, qui tourne tous les jours et ne doit pas
     * partir deux fois. La question porte sur l'envoi réussi : un rappel qui a
     * échoué mérite d'être retenté, sinon la panne d'un soir prive
     * définitivement quelqu'un de son rappel.
     */
    public function dejaEnvoye(NotificationEvent $evenement, User $destinataire, ?Campaign $campagne = null): bool
    {
        return NotificationDelivery::query()
            ->where('event', $evenement->value)
            ->where('recipient_id', $destinataire->getKey())
            ->where('status', DeliveryStatus::SENT->value)
            ->when($campagne !== null, fn ($q) => $q->where('campaign_id', $campagne->getKey()))
            ->exists();
    }

    private function parCourriel(
        NotificationEvent $evenement,
        NotificationChannel $canal,
        User $destinataire,
        LaravelNotification $message,
        ?Application $dossier,
        ?Campaign $campagne,
    ): NotificationDelivery {
        $adresse = trim((string) $destinataire->email);

        if ($adresse === '') {
            return $this->tracer($evenement, $canal, DeliveryStatus::SKIPPED, $destinataire, null, $dossier, $campagne,
                'Le compte ne porte aucune adresse électronique.');
        }

        try {
            Notifier::send($destinataire, $message);
        } catch (Throwable $erreur) {
            // Journalisé côté exploitation, jamais remonté à l'appelant : le
            // geste métier est déjà committé, et le faire échouer maintenant
            // laisserait le candidat devant une erreur pour un dépôt réussi.
            Log::error('Échec d’envoi d’une notification.', [
                'event' => $evenement->value,
                'recipient' => $destinataire->getKey(),
                'message' => $erreur->getMessage(),
            ]);

            return $this->tracer($evenement, $canal, DeliveryStatus::FAILED, $destinataire, $adresse, $dossier, $campagne,
                $erreur->getMessage());
        }

        return $this->tracer($evenement, $canal, DeliveryStatus::SENT, $destinataire, $adresse, $dossier, $campagne, null);
    }

    /** Un canal déclaré par le §8.3 mais qu'aucun fournisseur ne sert. */
    private function nonServi(
        NotificationEvent $evenement,
        NotificationChannel $canal,
        User $destinataire,
        ?Application $dossier,
        ?Campaign $campagne,
    ): NotificationDelivery {
        return $this->tracer(
            $evenement, $canal, DeliveryStatus::SKIPPED, $destinataire, null, $dossier, $campagne,
            $canal->raisonDIndisponibilite(),
        );
    }

    private function tracer(
        NotificationEvent $evenement,
        NotificationChannel $canal,
        DeliveryStatus $statut,
        User $destinataire,
        ?string $adresse,
        ?Application $dossier,
        ?Campaign $campagne,
        ?string $detail,
    ): NotificationDelivery {
        return NotificationDelivery::query()->create([
            'event' => $evenement->value,
            'channel' => $canal->value,
            'status' => $statut->value,
            'recipient_id' => $destinataire->getKey(),
            'recipient_role' => $evenement->recipient()->value,
            'recipient_address' => $adresse,
            'application_id' => $dossier?->getKey(),
            'campaign_id' => $campagne?->getKey() ?? $dossier?->campaign_id,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}
