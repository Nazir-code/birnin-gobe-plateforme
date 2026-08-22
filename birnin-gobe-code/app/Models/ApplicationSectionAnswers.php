<?php

namespace App\Models;

use App\Domain\Application\ApplicationSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réponses enregistrées pour une section d'une candidature.
 *
 * Nommé `ApplicationSectionAnswers` et non `ApplicationSection` : ce dernier nom
 * appartient déjà à l'enum du domaine, qui désigne *quelle* section. Le modèle,
 * lui, porte *ce que le candidat y a répondu*.
 */
class ApplicationSectionAnswers extends Model
{
    protected $table = 'application_sections';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'section' => ApplicationSection::class,
            'answers' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
