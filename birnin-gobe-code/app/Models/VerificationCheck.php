<?php

namespace App\Models;

use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une case de la grille d'admissibilité — un contrôle du §10.2, son verdict et
 * l'observation qui l'accompagne.
 *
 * L'état courant, pas l'historique : la ligne est mise à jour tant que la
 * décision n'est pas prise. Ce qui doit être conservé version par version, ce
 * sont les décisions — voir `VerificationDecision`.
 *
 * `actor_id` n'a pas de clé étrangère, comme dans `audit_events` : supprimer un
 * compte interne ne doit pas effacer la trace de ce qu'il a contrôlé.
 */
class VerificationCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'control' => VerificationControl::class,
            'outcome' => VerificationOutcome::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
