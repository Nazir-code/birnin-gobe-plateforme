<?php

namespace App\Domain\Notification;

use App\Models\Application;
use App\Models\Campaign;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\MessageTransactionnel;
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
 *
 * **Cette classe ne sait pas si un message est parti, et ne prétend plus le
 * savoir.** Les six messages du §8.3 sont mis en file d'attente : quand
 * `Notifier::send()` rend la main, personne n'a encore parlé à un serveur SMTP.
 * Écrire `SENT` ici reviendrait à faire dire à la trace « le candidat a été
 * prévenu » sur la foi d'un `RPUSH` dans Redis — et à laisser l'alerte du §9.3
 * à zéro pendant exactement la panne qu'elle doit signaler, puisque l'échec
 * survient plus tard, ailleurs. La ligne naît donc `QUEUED` ; c'est le
 * processus qui tente réellement l'envoi qui la referme, par
 * `RefermerLaTraceDEnvoi` en cas de succès et par
 * `MessageTransactionnel::failed()` en cas d'échec définitif.
 *
 * **La signature n'accepte qu'un `MessageTransactionnel`, et c'est délibéré.**
 * Un message quelconque ouvrirait une trace que rien ne saurait refermer : elle
 * resterait `QUEUED` pour toujours et déclencherait, une heure plus tard, une
 * alerte de file bloquée sur un envoi parfaitement réussi. Le type l'interdit,
 * plutôt qu'un `instanceof` qui laisserait le cas passer en silence.
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
        MessageTransactionnel $message,
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
     * partir deux fois. La question porte sur la prise en charge, pas sur la
     * seule réussite : un message encore en file compte, sinon la commande du
     * lendemain en produirait un second dès que le répartiteur prend une nuit
     * de retard — le doublon que la garde existe pour éviter. Un échec, lui,
     * ne compte pas : il mérite d'être retenté, sinon la panne d'un soir prive
     * définitivement quelqu'un de son rappel. Voir
     * `DeliveryStatus::vautPourUnEnvoi()`.
     */
    public function dejaEnvoye(NotificationEvent $evenement, User $destinataire, ?Campaign $campagne = null): bool
    {
        $prisEnCharge = array_values(array_map(
            fn (DeliveryStatus $statut) => $statut->value,
            array_filter(DeliveryStatus::cases(), fn (DeliveryStatus $statut) => $statut->vautPourUnEnvoi()),
        ));

        return NotificationDelivery::query()
            ->where('event', $evenement->value)
            ->where('recipient_id', $destinataire->getKey())
            ->whereIn('status', $prisEnCharge)
            ->when($campagne !== null, fn ($q) => $q->where('campaign_id', $campagne->getKey()))
            ->exists();
    }

    private function parCourriel(
        NotificationEvent $evenement,
        NotificationChannel $canal,
        User $destinataire,
        MessageTransactionnel $message,
        ?Application $dossier,
        ?Campaign $campagne,
    ): NotificationDelivery {
        $adresse = trim((string) $destinataire->email);

        if ($adresse === '') {
            return $this->tracer($evenement, $canal, DeliveryStatus::SKIPPED, $destinataire, null, $dossier, $campagne,
                'Le compte ne porte aucune adresse électronique.');
        }

        // La trace est ouverte **avant** l'envoi, et c'est ce qui la rend
        // fiable. Écrite après, elle manquerait précisément dans le cas où elle
        // compte : un processus tué entre l'envoi et l'écriture laisserait un
        // courriel parti sans aucune trace — un candidat prévenu que la
        // plateforme croit n'avoir jamais prévenu.
        $trace = $this->tracer($evenement, $canal, DeliveryStatus::QUEUED, $destinataire, $adresse, $dossier, $campagne, null);
        $message->traceId = $trace->getKey();

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

            // Ce qui échoue ici est la remise au répartiteur — Redis
            // injoignable — ou l'envoi lui-même quand la file est synchrone.
            // Dans ce second cas `failed()` a déjà refermé la trace, et la
            // garde de `refermer()` fait que ce second signal ne la contredit
            // pas.
            NotificationDelivery::refermer($trace->getKey(), DeliveryStatus::FAILED, $erreur->getMessage());

            return $trace->refresh();
        }

        // Rendue telle qu'elle est en base : sur une file synchrone,
        // l'écouteur l'a déjà refermée en `SENT` pendant l'appel ci-dessus.
        return $trace->refresh();
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
