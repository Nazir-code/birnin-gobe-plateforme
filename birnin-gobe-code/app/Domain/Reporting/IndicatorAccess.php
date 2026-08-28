<?php

namespace App\Domain\Reporting;

/**
 * Niveau d'accès d'un indicateur — §13.4.
 *
 * Deux niveaux, et la frontière est celle du §13.4 : les données de genre,
 * d'âge, de handicap ou de localisation « sont utilisées de façon agrégée pour
 * le suivi de l'inclusion ; les petits effectifs sont masqués dans les tableaux
 * publics ».
 *
 * `SENSITIVE` désigne donc les ventilations démographiques, celles dont un
 * petit effectif permettrait de remonter à une personne. Elles sont visibles
 * dans l'administration, mais soumises au seuil de masquage — et elles ne
 * doivent jamais alimenter un écran public sans repasser par ce seuil.
 */
enum IndicatorAccess: string
{
    /** Agrégat non ré-identifiant : compte de dossiers, de statuts, de délais. */
    case INTERNAL = 'INTERNAL';

    /** Ventilation démographique : soumise au seuil de petits effectifs. */
    case SENSITIVE = 'SENSITIVE';

    public function label(): string
    {
        return match ($this) {
            self::INTERNAL => 'Interne',
            self::SENSITIVE => 'Sensible — petits effectifs masqués',
        };
    }
}
