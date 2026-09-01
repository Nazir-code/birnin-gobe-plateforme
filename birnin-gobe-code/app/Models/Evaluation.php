<?php

namespace App\Models;

use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\EvaluationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La notation d'un dossier par un évaluateur — §11.2, §11.3.
 *
 * Une évaluation par affectation. Tant qu'elle est en brouillon elle
 * n'appartient qu'à son auteur ; verrouillée, elle devient une pièce du dossier
 * et ne bouge plus.
 */
class Evaluation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'recommendation' => EvaluationRecommendation::class,
            'total_score' => 'float',
            'locked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EvaluationAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EvaluationAssignment::class, 'evaluation_assignment_id');
    }

    /** @return BelongsTo<Application, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** @return BelongsTo<User, $this> */
    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    /** @return HasMany<EvaluationScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class);
    }

    /**
     * Les évaluations arrêtées.
     *
     * C'est la seule portée que l'administration a le droit de lire en détail :
     * avant le verrou, le §11.3 ne lui accorde que l'avancement.
     *
     * @param  Builder<Evaluation>  $requete
     * @return Builder<Evaluation>
     */
    public function scopeVerrouillees(Builder $requete): Builder
    {
        return $requete->where('status', EvaluationStatus::LOCKED->value);
    }

    public function estVerrouillee(): bool
    {
        return $this->status === EvaluationStatus::LOCKED;
    }
}
