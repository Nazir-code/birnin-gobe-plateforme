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
 * D'où `NOT_DISCLOSED` : le champ reste facultatif et le refus de répondre est
 * une réponse à part entière, pas un vide qu'on interpréterait. Le §11 rappelle
 * d'ailleurs que ces données ne servent qu'agrégées, et le §6.2 qu'« aucune
 * décision automatisée défavorable » ne peut s'y appuyer.
 */
enum Gender: string
{
    case FEMALE = 'FEMALE';
    case MALE = 'MALE';
    case NOT_DISCLOSED = 'NOT_DISCLOSED';

    public function label(): string
    {
        return match ($this) {
            self::FEMALE => 'Femme',
            self::MALE => 'Homme',
            self::NOT_DISCLOSED => 'Je préfère ne pas répondre',
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
