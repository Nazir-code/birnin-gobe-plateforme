<?php

namespace App\Domain\Candidate;

/**
 * Forme sous laquelle on candidate.
 *
 * Les trois cas sont ceux du cahier des charges (§4.1 « cas individu/équipe/
 * startup »), repris tels quels par le portail public. Aucun n'est exclu par
 * défaut : une campagne peut restreindre la liste, elle ne l'invente pas.
 *
 * Valeurs stables en anglais, libellés jamais persistés — même contrat que
 * `ApplicationStatus` et `UserRole`.
 */
enum CandidateType: string
{
    case INDIVIDUAL = 'INDIVIDUAL';
    case TEAM = 'TEAM';
    case STARTUP = 'STARTUP';

    public function label(): string
    {
        return match ($this) {
            self::INDIVIDUAL => 'Candidature individuelle',
            self::TEAM => 'Équipe',
            self::STARTUP => 'Startup',
        };
    }

    /**
     * Une candidature collective se décrit par un effectif ; une candidature
     * individuelle, non. C'est ce qui rend la question « combien êtes-vous ? »
     * conditionnelle, à l'écran comme à la validation.
     */
    public function isCollective(): bool
    {
        return $this !== self::INDIVIDUAL;
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
