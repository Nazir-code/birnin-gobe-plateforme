<?php

namespace App\Domain\Application;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class SubmitApplication
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private AuditWriter $audit,
    ) {}

    public function handle(Application $application, int $actorId): Application
    {
        return DB::transaction(function () use ($application, $actorId): Application {
            $application->refresh();
            $current = ApplicationStatus::from($application->status);
            $this->stateMachine->assertCanTransition($current, ApplicationStatus::SUBMITTED);

            if (! $application->isCompleteForActiveCampaign()) {
                throw new RuntimeException('APPLICATION_INCOMPLETE');
            }

            $old = $application->toArray();
            $application->forceFill([
                'status' => ApplicationStatus::SUBMITTED->value,
                'submission_number' => 'BG-'.now()->format('Y').'-'.Str::upper(Str::random(10)),
                'submitted_at' => now(),
                'submitted_snapshot' => $application->buildSubmissionSnapshot(),
            ])->save();

            $this->audit->write(
                actorId: $actorId,
                action: 'APPLICATION_SUBMITTED',
                targetType: Application::class,
                targetId: (string) $application->getKey(),
                oldValue: $old,
                newValue: $application->fresh()->toArray(),
                reason: null,
            );

            // Notification and PDF receipt must be queued after commit.
            return $application;
        });
    }
}
