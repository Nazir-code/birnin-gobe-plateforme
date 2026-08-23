<?php

namespace App\Domain\Candidate;

/**
 * Niveau d'études le plus élevé atteint.
 *
 * Le cahier des charges demande la donnée (§6.1, groupe Profil : « Occupation,
 * niveau d'études, spécialité… ») et précise « choix normalisés et zone
 * descriptive », sans fournir la liste.
 *
 * Distinction importante avec ADR-007 : ce n'est **pas** un seuil d'éligibilité.
 * Un niveau d'études n'exclut personne — il décrit. Refuser de proposer une
 * échelle reviendrait à ne pas collecter la donnée du tout, là où une tranche
 * d'âge inventée aurait, elle, écarté des candidats sur un critère que personne
 * n'a arrêté. L'échelle ci-dessous suit le cursus nigérien courant ; le §9.2
 * range les options de formulaire parmi les paramètres administrables, elle
 * pourra donc être ajustée sans redéploiement le jour où cet écran existera.
 */
enum EducationLevel: string
{
    case NONE = 'NONE';
    case PRIMARY = 'PRIMARY';
    case SECONDARY = 'SECONDARY';
    case BACCALAUREATE = 'BACCALAUREATE';
    case BACHELOR = 'BACHELOR';
    case MASTER = 'MASTER';
    case DOCTORATE = 'DOCTORATE';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sans scolarité formelle',
            self::PRIMARY => 'Primaire',
            self::SECONDARY => 'Secondaire',
            self::BACCALAUREATE => 'Baccalauréat',
            self::BACHELOR => 'Licence',
            self::MASTER => 'Master',
            self::DOCTORATE => 'Doctorat',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $niveau): array => ['value' => $niveau->value, 'label' => $niveau->label()],
            self::cases(),
        );
    }
}
