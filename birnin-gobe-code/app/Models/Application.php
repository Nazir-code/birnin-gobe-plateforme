<?php

namespace App\Models;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    /**
     * `$guarded = []` est conservé mais n'est pas ce qui protège la candidature :
     * aucune écriture ne passe par une affectation de masse issue de la requête.
     * `StartApplication` et `SaveApplicationSection` fixent explicitement les
     * champs sensibles (`candidate_id`, `campaign_id`, `status`).
     */
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'current_step' => ApplicationSection::class,
            'submitted_at' => 'immutable_datetime',
            'submitted_snapshot' => 'array',
        ];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'candidate_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return HasMany<ApplicationSectionAnswers, $this> */
    public function sections(): HasMany
    {
        return $this->hasMany(ApplicationSectionAnswers::class);
    }

    public function sectionAnswers(ApplicationSection $section): ?ApplicationSectionAnswers
    {
        return $this->sections()->where('section', $section->value)->first();
    }

    public function isDraft(): bool
    {
        return $this->status === ApplicationStatus::DRAFT;
    }

    public function isCompleteForActiveCampaign(): bool
    {
        // Placeholder: replace with Campaign-configured validation engine.
        return true;
    }

    public function buildSubmissionSnapshot(): array
    {
        // Snapshot must include resolved form/rules/answers/version references at submission time.
        return ['application_id' => $this->getKey(), 'version' => 1];
    }
}
