<?php

namespace App\Domain\Audit;

use App\Models\Application;
use App\Models\Campaign;

/**
 * Les objets sur lesquels le journal porte aujourd'hui.
 *
 * La valeur est le nom de classe complet, parce que c'est ce que
 * `AuditWriter::write()` reçoit et ce que `audit_events.target_type` stocke.
 * Écrire ici un code court obligerait à traduire dans les deux sens, et la
 * traduction finirait par diverger du contenu de la table.
 *
 * Comme `AuditAction`, cet enum ne contraint pas l'écriture : un type inconnu
 * reste lisible, il perd seulement son libellé et son lien.
 */
enum AuditTargetType: string
{
    case APPLICATION = Application::class;
    case CAMPAIGN = Campaign::class;

    public function label(): string
    {
        return match ($this) {
            self::APPLICATION => 'Candidature',
            self::CAMPAIGN => 'Campagne',
        };
    }

    /**
     * Route de consultation de l'objet visé, si l'administration en a une.
     *
     * Une campagne n'a pas d'écran de détail : son édition en tient lieu. Une
     * candidature en a un. Rendre `null` plutôt qu'une URL devinée — un lien
     * vers un écran absent est pire qu'un libellé sans lien.
     */
    public function url(string $targetId): ?string
    {
        if (! ctype_digit($targetId)) {
            return null;
        }

        return match ($this) {
            self::APPLICATION => route('admin.applications.show', $targetId),
            self::CAMPAIGN => route('admin.campaigns.edit', $targetId),
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $type): array => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
