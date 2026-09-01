<?php

namespace App\Domain\Reporting;

/**
 * Une ventilation d'indicateur — les « répartitions » du §9.1 et du §13.1.
 *
 * Même fiche de gouvernance qu'`Indicator` (§13.4) : définition, formule,
 * source, fréquence, niveau d'accès. Ce qui change est la forme du résultat —
 * des lignes plutôt qu'un scalaire — et surtout **le masquage**, qui n'a de sens
 * que sur une ventilation : c'est le croisement d'un petit effectif avec une
 * modalité (une région, un sexe) qui permet de remonter à une personne, pas un
 * total.
 *
 * Le seuil est appliqué à la construction, jamais à l'affichage. Une valeur
 * masquée ne quitte pas le serveur : l'écran reçoit `null` et le drapeau, pas
 * le chiffre accompagné d'une consigne de ne pas le montrer.
 */
final readonly class IndicatorBreakdown
{
    /** En deçà, un effectif croisé à une modalité peut ré-identifier (§13.4). */
    public const SEUIL_PETITS_EFFECTIFS = 5;

    /**
     * @param  list<array{label: string, value: int|null, masked: bool}>  $rows
     */
    public function __construct(
        public string $key,
        public IndicatorFamily $family,
        public string $label,
        public string $definition,
        public string $formula,
        public string $source,
        public IndicatorRefresh $refresh,
        public IndicatorAccess $access,
        public array $rows,
    ) {}

    /**
     * Construit une ventilation en appliquant le seuil quand l'accès l'exige.
     *
     * Un zéro n'est **pas** masqué : « personne » n'identifie personne, et
     * masquer les zéros ferait disparaître de la table les modalités qu'il est
     * précisément utile de voir vides — une région sans aucune candidature est
     * une information de pilotage.
     *
     * @param  array<string, int>  $comptes  Indexé par libellé de modalité.
     */
    public static function depuis(
        string $key,
        IndicatorFamily $family,
        string $label,
        string $definition,
        string $formula,
        string $source,
        IndicatorAccess $access,
        array $comptes,
    ): self {
        $lignes = [];

        foreach ($comptes as $modalite => $compte) {
            $masquer = $access === IndicatorAccess::SENSITIVE
                && $compte > 0
                && $compte < self::SEUIL_PETITS_EFFECTIFS;

            $lignes[] = [
                'label' => (string) $modalite,
                'value' => $masquer ? null : $compte,
                'masked' => $masquer,
            ];
        }

        return new self(
            key: $key,
            family: $family,
            label: $label,
            definition: $definition,
            formula: $formula,
            source: $source,
            refresh: IndicatorRefresh::LIVE,
            access: $access,
            rows: $lignes,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'family' => $this->family->value,
            'label' => $this->label,
            'definition' => $this->definition,
            'formula' => $this->formula,
            'source' => $this->source,
            'refresh' => $this->refresh->value,
            'refreshLabel' => $this->refresh->label(),
            'access' => $this->access->value,
            'accessLabel' => $this->access->label(),
            'threshold' => self::SEUIL_PETITS_EFFECTIFS,
            'rows' => $this->rows,
        ];
    }
}
