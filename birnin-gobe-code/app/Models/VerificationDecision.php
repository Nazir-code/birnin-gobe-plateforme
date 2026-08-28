<?php

namespace App\Models;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\VerificationControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une décision d'admissibilité, telle qu'elle a été prise — §10.3.
 *
 * **En ajout seul.** Rien ne met à jour ni ne supprime une ligne de cette
 * table : le §10.3 veut qu'une modification ultérieure « crée une nouvelle
 * version, identifie l'auteur ». Réécrire la décision précédente ferait
 * disparaître la version qu'on est censé pouvoir comparer.
 *
 * D'où l'absence de `updated_at` : la colonne existerait pour ne jamais
 * changer, et sa seule présence suggérerait qu'une mise à jour est prévue.
 */
class VerificationDecision extends Model
{
    protected $guarded = [];

    /** Table en ajout seul : `created_at` est écrit explicitement, `updated_at` n'existe pas. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'decision' => AdmissibilityDecision::class,
            'primary_reason' => VerificationControl::class,
            'secondary_reason' => VerificationControl::class,
            'previous_status' => ApplicationStatus::class,
            'new_status' => ApplicationStatus::class,
            'respond_by' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
