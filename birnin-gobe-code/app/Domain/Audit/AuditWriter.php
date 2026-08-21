<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;

final class AuditWriter
{
    public function write(int $actorId, string $action, string $targetType, string $targetId, ?array $oldValue, ?array $newValue, ?string $reason): void
    {
        AuditEvent::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'technical_source' => request()?->ip(),
            'reason' => $reason,
        ]);
    }
}
