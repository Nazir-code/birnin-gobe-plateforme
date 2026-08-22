<?php

namespace App\Domain\Reference;

/**
 * Régions du Niger, référentiel ISO 3166-2:NE.
 *
 * Côté serveur parce que c'est une donnée de validation : une réponse « région »
 * hors de cette liste doit être refusée, et une liste qui ne vivrait que dans le
 * composant React ne refuserait rien. Les mêmes codes servent déjà à la carte
 * du portail (`resources/js/Components/NigerRegionsMap.tsx`).
 */
enum NigerRegion: string
{
    case AGADEZ = 'NE-1';
    case DIFFA = 'NE-2';
    case DOSSO = 'NE-3';
    case MARADI = 'NE-4';
    case TAHOUA = 'NE-5';
    case TILLABERI = 'NE-6';
    case ZINDER = 'NE-7';
    case NIAMEY = 'NE-8';

    public function label(): string
    {
        return match ($this) {
            self::AGADEZ => 'Agadez',
            self::DIFFA => 'Diffa',
            self::DOSSO => 'Dosso',
            self::MARADI => 'Maradi',
            self::TAHOUA => 'Tahoua',
            self::TILLABERI => 'Tillabéri',
            self::ZINDER => 'Zinder',
            self::NIAMEY => 'Niamey',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $region): array => ['value' => $region->value, 'label' => $region->label()],
            self::cases(),
        );
    }
}
