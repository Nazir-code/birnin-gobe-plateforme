<?php

namespace App\Domain\Application;

use DomainException;

final class ApplicationStateMachine
{
    /** @var array<string, list<ApplicationStatus>> */
    private const TRANSITIONS = [
        'DRAFT' => [ApplicationStatus::SUBMITTED],
        'SUBMITTED' => [ApplicationStatus::PENDING_REVIEW],
        'PENDING_REVIEW' => [ApplicationStatus::CLARIFICATION_REQUESTED, ApplicationStatus::ADMISSIBLE, ApplicationStatus::INADMISSIBLE],
        'CLARIFICATION_REQUESTED' => [ApplicationStatus::CLARIFICATION_RECEIVED],
        'CLARIFICATION_RECEIVED' => [ApplicationStatus::ADMISSIBLE, ApplicationStatus::INADMISSIBLE],
        'ADMISSIBLE' => [ApplicationStatus::IN_EVALUATION],
        'IN_EVALUATION' => [ApplicationStatus::EVALUATED],
        'EVALUATED' => [ApplicationStatus::SHORTLISTED, ApplicationStatus::NOT_SHORTLISTED],
        'SHORTLISTED' => [ApplicationStatus::FINALIST],
        'FINALIST' => [ApplicationStatus::SELECTED, ApplicationStatus::WAITLISTED, ApplicationStatus::NOT_SELECTED],
    ];

    public function assertCanTransition(ApplicationStatus $from, ApplicationStatus $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw new DomainException("Transition {$from->value} -> {$to->value} interdite.");
        }
    }
}
