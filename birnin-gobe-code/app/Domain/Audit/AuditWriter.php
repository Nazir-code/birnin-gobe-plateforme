<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;

final class AuditWriter
{
    /**
     * `?int $actorId` : la colonne est nullable depuis l'origine, la signature
     * ne l'etait pas. Un compte interne cree en ligne de commande n'a aucun
     * acteur authentifie a nommer, et lui en attribuer un serait pire que de
     * n'en nommer aucun — le journal du §13.3 sert a savoir qui a decide.
     */
    public function write(?int $actorId, string $action, string $targetType, string $targetId, ?array $oldValue, ?array $newValue, ?string $reason): void
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
