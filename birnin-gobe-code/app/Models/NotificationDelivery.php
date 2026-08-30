<?php

namespace App\Models;

use App\Domain\Notification\DeliveryStatus;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationEvent;
use App\Domain\Notification\NotificationRecipient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La trace d'une tentative d'envoi — §8.3.
 *
 * En ajout seul : `UPDATED_AT` est nul parce qu'un envoi ne se réécrit pas. Une
 * seconde tentative est une seconde ligne, et c'est ce qui permet de voir qu'un
 * message a dû être renvoyé.
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
}
