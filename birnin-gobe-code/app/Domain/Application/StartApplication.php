<?php

namespace App\Domain\Application;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ouvre le brouillon de candidature d'un candidat pour une campagne.
 *
 * Idempotent par construction. Le candidat, la campagne et le statut sont
 * décidés ici, à partir de la session et de la campagne active : aucune de ces
 * valeurs ne vient de la requête, donc aucune n'est falsifiable.
 *
 * Deux barrières contre les brouillons en double, parce que le double-clic et
 * la requête rejouée sont la norme sur un réseau mobile :
 *  - la lecture préalable, qui couvre le cas courant ;
 *  - l'unique `(campaign_id, candidate_id)` en base, qui couvre la course entre
 *    deux requêtes simultanées — là où une simple lecture laisse passer.
 */
final readonly class StartApplication
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(User $candidate, Campaign $campaign): Application
    {
        $existante = $this->existante($candidate, $campaign);

        if ($existante !== null) {
            return $existante;
        }

        try {
            return DB::transaction(function () use ($candidate, $campaign): Application {
                $application = new Application;
                $application->forceFill([
                    'campaign_id' => $campaign->getKey(),
                    'candidate_id' => $candidate->getKey(),
                    'status' => ApplicationStatus::DRAFT->value,
                    'current_step' => ApplicationSection::firstImplemented()->value,
                    'completion_percent' => 0,
                ])->save();

                $this->audit->write(
                    actorId: $candidate->getKey(),
                    action: 'APPLICATION_CREATED',
                    targetType: Application::class,
                    targetId: (string) $application->getKey(),
                    oldValue: null,
                    newValue: $application->only(['campaign_id', 'candidate_id', 'status', 'current_step']),
                    reason: null,
                );

                return $application;
            });
        } catch (UniqueConstraintViolationException $violation) {
            // Une requête concurrente a gagné la course : son brouillon fait foi.
            return $this->existante($candidate, $campaign)
                ?? throw new RuntimeException('APPLICATION_DRAFT_UNRESOLVED', previous: $violation);
        }
    }

    private function existante(User $candidate, Campaign $campaign): ?Application
    {
        return Application::query()
            ->where('candidate_id', $candidate->getKey())
            ->where('campaign_id', $campaign->getKey())
            ->first();
    }
}
