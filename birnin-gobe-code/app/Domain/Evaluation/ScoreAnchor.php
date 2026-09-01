<?php

namespace App\Domain\Evaluation;

/**
 * L'échelle de notation et ses ancres — §11.3.
 *
 * « Échelle 0 à 5 avec ancres explicites ». Les ancres sont la moitié utile de
 * la phrase : sans elles, « 3 » veut dire ce que chaque évaluateur décide, et
 * l'écart entre deux notations mesure surtout la sévérité de deux personnes.
 * Elles sont donc affichées **à côté du champ de saisie**, pas dans un guide
 * annexe — une ancre qu'il faut aller chercher n'est pas une ancre.
 *
 * `estExtreme()` porte à lui seul une règle du §11.3 : « commentaire
 * obligatoire pour les notes extrêmes ». 0 et 5 sont les deux notes qu'un
 * comité relira, l'une parce qu'elle exclut, l'autre parce qu'elle distingue.
 * Une note extrême sans justification n'est pas contestable, et une notation
 * incontestable n'est pas défendable.
 */
enum ScoreAnchor: int
{
    case ABSENT = 0;
    case VERY_WEAK = 1;
    case WEAK = 2;
    case SATISFACTORY = 3;
    case VERY_GOOD = 4;
    case EXCELLENT = 5;

    public function label(): string
    {
        return match ($this) {
            self::ABSENT => 'Absent ou non recevable',
            self::VERY_WEAK => 'Très faible',
            self::WEAK => 'Faible',
            self::SATISFACTORY => 'Satisfaisant',
            self::VERY_GOOD => 'Très bon',
            self::EXCELLENT => 'Excellent et démontré',
        };
    }

    /** Les deux notes que le §11.3 oblige à justifier. */
    public function estExtreme(): bool
    {
        return $this === self::ABSENT || $this === self::EXCELLENT;
    }

    /** @return list<array{value: int, label: string, extreme: bool}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $ancre): array => [
                'value' => $ancre->value,
                'label' => $ancre->label(),
                'extreme' => $ancre->estExtreme(),
            ],
            self::cases(),
        );
    }
}
