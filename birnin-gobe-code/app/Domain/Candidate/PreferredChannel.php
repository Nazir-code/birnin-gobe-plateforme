<?php

namespace App\Domain\Candidate;

/**
 * Canal par lequel le candidat souhaite être joint.
 *
 * Deux valeurs, et deux seulement : le cahier des charges ne connaît que le
 * courriel et le SMS — §12 (« Email/SMS » pour chaque événement de
 * notification), §9.2 (« Modèles email/SMS ») et §14 (« Passerelle email et
 * SMS »). WhatsApp n'est cité nulle part : l'ajouter reviendrait à promettre un
 * canal que l'infrastructure ne dessert pas.
 *
 * Valeurs stables en anglais, libellés jamais persistés — même contrat que
 * `ApplicationStatus` et `UserRole`.
 */
enum PreferredChannel: string
{
    case EMAIL = 'EMAIL';
    case SMS = 'SMS';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Courriel',
            self::SMS => 'SMS',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $canal): array => ['value' => $canal->value, 'label' => $canal->label()],
            self::cases(),
        );
    }
}
