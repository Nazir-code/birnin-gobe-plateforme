<?php

namespace App\Domain\Reporting;

/**
 * Les paliers de la carte de répartition — §9.1.
 *
 * **Relatifs au maximum observé, jamais des seuils en dur.** Huit dossiers à
 * Niamey ne veulent pas dire la même chose sur soixante-cinq candidatures que
 * sur six cents : une échelle fixe rendrait la carte illisible au début de la
 * collecte, puis uniformément foncée à la fin. Le palier répond donc à « par
 * rapport à la région la plus fournie », qui est la seule question qu'une carte
 * de densité sache poser.
 *
 * **Une région masquée n'a pas de palier.** La répartition régionale est classée
 * `SENSITIVE` (§13.4) : sous le seuil de petits effectifs, `IndicatorBreakdown`
 * rend `null` plutôt qu'un chiffre, parce que le croisement d'un effectif d'une
 * ou deux personnes avec une région permet de remonter à quelqu'un. Colorer
 * malgré tout rendrait au regard ce que le masquage vient de retirer : une
 * teinte pâle sur une seule région dirait « il y a une candidature ici », qui
 * est précisément l'information protégée. Ces régions restent donc grises,
 * comme celles qui n'ont aucun dossier.
 *
 * C'est un choix qui coûte : la carte ne distingue pas « aucun dossier » de
 * « trop peu pour le dire ». La table de l'écran des indicateurs, elle, fait la
 * différence — elle marque les valeurs masquées. La carte donne une forme, pas
 * un décompte.
 */
enum RegionIntensity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case ELEVATED = 'elevated';
    case HIGH = 'high';

    /**
     * Le palier d'un effectif, rapporté au maximum de la carte.
     *
     * Les bornes sont des quarts du maximum. Le plus fourni est toujours `HIGH`
     * — sans quoi aucune région ne serait jamais au palier haut, et la légende
     * annoncerait une teinte qui n'apparaît nulle part.
     */
    public static function pour(int $compte, int $maximum): ?self
    {
        if ($compte <= 0 || $maximum <= 0) {
            return null;
        }

        $part = $compte / $maximum;

        return match (true) {
            $part >= 0.75 => self::HIGH,
            $part >= 0.5 => self::ELEVATED,
            $part >= 0.25 => self::MEDIUM,
            default => self::LOW,
        };
    }

    /**
     * La carte, prête pour le composant : code de région → palier.
     *
     * `$comptes` vient d'`IndicatorBreakdown::rows` : les entrées masquées y
     * portent `null`, et sont écartées ici. Le maximum est calculé sur les
     * seules valeurs visibles — inclure une valeur masquée dans le calcul la
     * ferait transparaître par l'échelle.
     *
     * @param  array<string, int|null>  $comptes  Indexé par code de région.
     * @return array<string, string>
     */
    public static function carte(array $comptes): array
    {
        $visibles = array_filter($comptes, static fn (?int $compte): bool => $compte !== null && $compte > 0);

        if ($visibles === []) {
            return [];
        }

        $maximum = max($visibles);
        $paliers = [];

        foreach ($visibles as $region => $compte) {
            $palier = self::pour((int) $compte, $maximum);

            if ($palier !== null) {
                $paliers[(string) $region] = $palier->value;
            }
        }

        return $paliers;
    }
}
