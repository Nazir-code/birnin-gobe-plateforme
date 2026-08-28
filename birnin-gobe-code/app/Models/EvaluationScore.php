<?php

namespace App\Models;

use App\Domain\Evaluation\EvaluationCriterion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La note d'un critère — §11.2.
 *
 * `score` peut être `null` : le critère n'a pas encore été noté. Zéro est une
 * note réelle de l'échelle du §11.3 (« absent ou non recevable »), et les
 * confondre reviendrait à présenter comme jugé ce qui n'a pas été lu.
 */
class EvaluationScore extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'criterion' => EvaluationCriterion::class,
            'score' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Evaluation, $this> */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /** Le score pondéré de cette ligne, ou `null` tant que le critère n'est pas noté. */
    public function weightedScore(): ?float
    {
        return $this->score === null ? null : $this->criterion->weightedScore($this->score);
    }
}
