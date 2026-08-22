<?php

namespace App\Models;

use App\Domain\Campaign\CampaignStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Édition de la compétition à laquelle se rattachent les candidatures.
 *
 * La table existait depuis la migration initiale sans modèle en face. Le voici,
 * avec les casts qui rendent `status` et les dates exploitables : le calendrier
 * affiché au candidat vient d'ici, plus de valeurs de démonstration.
 */
class Campaign extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'opens_at' => 'immutable_datetime',
            'closes_at' => 'immutable_datetime',
            'settings' => 'array',
        ];
    }

    /** @return HasMany<Application, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
