<?php

namespace App\Domain\Eligibility;

/**
 * Les règles d'éligibilité évaluées par le serveur.
 *
 * La liste vient du cahier des charges, pas d'une intuition :
 * §4.1 « conditions paramétrées, auto-test indicatif, cas individu/équipe/
 * startup, zones, âge, nationalité/résidence, exclusions et pièces », §9.2
 * « Âge et date de référence, nationalité/résidence, zones, types de candidats,
 * taille d'équipe, restrictions et motifs d'exclusion », et §10.2 qui reprend
 * les mêmes axes côté contrôle administratif.
 *
 * Les motifs d'exclusion (§9.2) ne figurent pas ici : le cahier des charges les
 * annonce comme paramétrables sans en énoncer aucun, et une règle sans contenu
 * ne s'implémente pas — elle s'attend.
 *
 * Valeurs stables en anglais : elles finiront dans des motifs codifiés et des
 * exports, jamais dans un libellé traduit.
 */
enum EligibilityRule: string
{
    case AGE = 'AGE';
    case NATIONALITY_RESIDENCE = 'NATIONALITY_RESIDENCE';
    case ZONE = 'ZONE';
    case CANDIDATE_TYPE = 'CANDIDATE_TYPE';
    case TEAM_SIZE = 'TEAM_SIZE';

    public function label(): string
    {
        return match ($this) {
            self::AGE => 'Âge',
            self::NATIONALITY_RESIDENCE => 'Nationalité ou résidence',
            self::ZONE => 'Zone d’intervention',
            self::CANDIDATE_TYPE => 'Type de candidature',
            self::TEAM_SIZE => 'Taille de l’équipe',
        };
    }
}
