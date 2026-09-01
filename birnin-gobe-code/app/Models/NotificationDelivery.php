<?php

namespace App\Models;

use App\Domain\Notification\DeliveryStatus;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationEvent;
use App\Domain\Notification\NotificationRecipient;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La trace d'une tentative d'envoi — §8.3.
 *
 * En ajout seul : `UPDATED_AT` est nul parce qu'un envoi ne se réécrit pas. Une
 * seconde tentative est une seconde ligne, et c'est ce qui permet de voir qu'un
 * message a dû être renvoyé.
 *
 * **`refermer()` n'est pas une exception à cette règle.** Une ligne naît
 * `QUEUED` — le message est confié au répartiteur, son issue n'est pas encore
 * connue — et se referme une fois, quand elle l'est. Ce n'est pas un envoi
 * réécrit, c'est un envoi dont on apprend le sort. La garde sur `QUEUED` le
 * tient : une ligne déjà refermée ne bouge plus, quel que soit le nombre de
 * signaux qui lui parviennent.
 */
class NotificationDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'status' => DeliveryStatus::class,
            'recipient_role' => NotificationRecipient::class,
            'created_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Les envois qui ont réellement échoué.
     *
     * Exclut `SKIPPED` : une absence de fournisseur n'est pas une panne, et
     * l'alerte du §9.3 ne doit pas la compter — voir `DeliveryStatus`.
     *
     * @param  Builder<NotificationDelivery>  $requete
     * @return Builder<NotificationDelivery>
     */
    public function scopeEnEchec(Builder $requete): Builder
    {
        return $requete->where('status', DeliveryStatus::FAILED->value);
    }

    /**
     * Les messages confiés au répartiteur et jamais refermés.
     *
     * Un envoi qui reste `QUEUED` n'est ni un succès ni un échec : personne ne
     * lui a répondu. C'est la signature d'un `worker` arrêté — le seul cas que
     * ni `SENT` ni `FAILED` ne peut signaler, puisqu'aucun des deux ne sera
     * jamais écrit tant que rien ne dépile la file.
     *
     * @param  Builder<NotificationDelivery>  $requete
     * @return Builder<NotificationDelivery>
     */
    public function scopeEnAttenteDepuis(Builder $requete, CarbonInterface $limite): Builder
    {
        return $requete
            ->where('status', DeliveryStatus::QUEUED->value)
            ->where('created_at', '<', $limite);
    }

    /**
     * Referme une trace une fois son issue connue, depuis le processus qui a
     * réellement tenté l'envoi.
     *
     * **Idempotente, et volontairement.** Trois signaux peuvent arriver pour un
     * même envoi : l'événement `NotificationSent`, le `failed()` du message, et
     * l'exception attrapée par `SendNotification` quand la file est synchrone.
     * Sur une file `sync`, les deux derniers se produisent pour le même échec.
     * Le premier arrivé fixe l'issue ; les suivants ne la contredisent pas.
     *
     * L'identifiant peut être nul : un message construit hors de
     * `SendNotification` — dans un test, ou par un futur appelant distrait — n'a
     * pas de trace à refermer, et cela ne doit pas faire échouer son envoi.
     */
    public static function refermer(?int $identifiant, DeliveryStatus $issue, ?string $detail = null): void
    {
        if ($identifiant === null) {
            return;
        }

        static::query()
            ->whereKey($identifiant)
            ->where('status', DeliveryStatus::QUEUED->value)
            ->update([
                'status' => $issue->value,
                'settled_at' => now(),
                'detail' => $detail,
            ]);
    }
}
