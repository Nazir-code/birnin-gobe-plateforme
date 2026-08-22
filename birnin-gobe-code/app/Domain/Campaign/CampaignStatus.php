<?php

namespace App\Domain\Campaign;

/**
 * Cycle de vie d'une campagne.
 *
 * `campaigns.status` existait déjà comme colonne `string` avec `DRAFT` par
 * défaut, sans type PHP en face. L'enum le fournit, sur le même contrat que
 * `ApplicationStatus` : valeurs stables en anglais, libellés jamais persistés.
 */
enum CampaignStatus: string
{
    case DRAFT = 'DRAFT';
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case ARCHIVED = 'ARCHIVED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Préparation',
            self::OPEN => 'Candidatures ouvertes',
            self::CLOSED => 'Candidatures closes',
            self::ARCHIVED => 'Archivée',
        };
    }
}
