<?php

namespace App\Models;

use App\Domain\Evaluation\DivergenceReviewOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une revue d'écart entre évaluateurs — §11.3.
 *
 * En ajout seul : `updated_at` n'existe pas, parce qu'une revue ne se réécrit
 * pas. Revenir sur un arbitrage, c'est en écrire un second, daté.
 */
class EvaluationReview extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'outcome' => DivergenceReviewOutcome::class,
            'covered_evaluations' => 'integer',
            'observed_gap' => 'float',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
