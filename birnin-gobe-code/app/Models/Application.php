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

    /**
     * Les pieces jointes du dossier (etape 8).
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * La grille d'admissibilite du §10.2 — un verdict par controle.
     *
     * @return HasMany<VerificationCheck, $this>
     */
    public function verificationChecks(): HasMany
    {
        return $this->hasMany(VerificationCheck::class);
    }

    /**
     * L'historique des decisions d'admissibilite, en ajout seul (§10.3).
     *
     * @return HasMany<VerificationDecision, $this>
     */
    public function verificationDecisions(): HasMany
    {
        return $this->hasMany(VerificationDecision::class);
    }

    public function sectionAnswers(ApplicationSection $section): ?ApplicationSectionAnswers
    {
        return $this->sections()->where('section', $section->value)->first();
    }

    public function isDraft(): bool
    {
        return $this->status === ApplicationStatus::DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === ApplicationStatus::SUBMITTED;
    }

    /*
     * Deux méthodes ont disparu d'ici : `isCompleteForActiveCampaign()`, qui
     * rendait `true` sans rien vérifier, et `buildSubmissionSnapshot()`, qui
     * rendait un identifiant et un numéro de version. C'étaient des ébauches, et
     * la première aurait autorisé le dépôt d'un dossier vide le jour où la route
     * de soumission serait branchée.
     *
     * Leur remplacement n'appartient pas au modèle : décider qu'un dossier est
     * déposable met en jeu la campagne, l'éligibilité et les sections exigées —
     * voir `SubmissionReadiness`. Copier ce qui a été déposé met en jeu les trois
     * mêmes plus le verdict du moment — voir `SubmissionSnapshot`.
     */
}
