<?php

namespace App\Models;

use App\Domain\Evaluation\AssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'affectation d'un dossier à un évaluateur — §11.1.
 *
 * La table porte à la fois les affectations en vigueur et celles qui ont été
 * levées : `released_at` les sépare, `status` dit pourquoi. Rien n'est
 * supprimé — un dossier retiré à quelqu'un doit rester lisible comme tel, et un
 * conflit déclaré doit continuer d'empêcher la réaffectation.
 */
class EvaluationAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'assigned_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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

    /**
     * Les affectations qui occupent encore leur évaluateur.
     *
     * Exprimé sur `released_at` et non sur `status` : c'est la colonne que
     * l'index unique partiel utilise, donc la seule dont la définition de
     * « en vigueur » ne peut pas diverger de celle de la base.
     *
     * @param  Builder<EvaluationAssignment>  $requete
     * @return Builder<EvaluationAssignment>
     */
    public function scopeEnVigueur(Builder $requete): Builder
    {
        return $requete->whereNull('released_at');
    }
}
