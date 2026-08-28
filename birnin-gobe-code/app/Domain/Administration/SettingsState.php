<?php

namespace App\Domain\Administration;

/**
 * État d'outillage d'un domaine paramétrable du §9.2.
 *
 * Trois états, et `PARTIEL` est le plus important des trois : c'est celui qu'on
 * est tenté de ranger avec « administrable », et c'est celui qui trompe.
 * Croire qu'on a paramétré l'évaluation parce qu'on a fixé le nombre
 * d'évaluateurs — alors que les critères et leurs poids ne le sont pas — mène à
 * ouvrir une campagne en pensant qu'elle est réglée.
 */
enum SettingsState: string
{
    case ADMINISTRABLE = 'ADMINISTRABLE';
    case PARTIEL = 'PARTIEL';
    case ABSENT = 'ABSENT';

    public function label(): string
    {
        return match ($this) {
            self::ADMINISTRABLE => 'Administrable',
            self::PARTIEL => 'Partiellement administrable',
            self::ABSENT => 'Pas encore administrable',
        };
    }
}
