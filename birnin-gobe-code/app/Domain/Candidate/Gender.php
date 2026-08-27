<?php

namespace App\Domain\Candidate;

/**
 * Sexe déclaré, à finalité statistique.
 *
 * Le cahier des charges le demande sous condition — §6.1 « sexe (si requis pour
 * statistiques) » — et en énonce la finalité ailleurs : le tableau de bord de
 * campagne suit les « candidatures féminines » (§9.1) et le reporting agrège
 * par « sexe » (§11). C'est cette finalité déclarée qui justifie de poser la
 * question, conformément au §6 (« tout champ sensible devra être justifié par
 * une finalité précise »).
 *
 * Deux valeurs, et aucun troisième cas pour refuser de répondre : le champ est
 * obligatoire, et la section « Profil » n'est complète qu'une fois renseigné
 * (voir ProfileSection::REQUIRED_FIELDS). Ce que cela ne change pas : le §11
 * rappelle que ces données ne servent qu'agrégées, et le §6.2 qu'« aucune
 * décision automatisée défavorable » ne peut s'y appuyer.
 */
enum Gender: string
{
    case FEMALE = 'FEMALE';
    case MALE = 'MALE';

    public function label(): string
    {
        return match ($this) {
            self::FEMALE => 'Femme',
            self::MALE => 'Homme',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $sexe): array => ['value' => $sexe->value, 'label' => $sexe->label()],
            self::cases(),
        );
    }
}
