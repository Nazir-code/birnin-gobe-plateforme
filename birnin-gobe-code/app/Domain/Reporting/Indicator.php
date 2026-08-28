<?php

namespace App\Domain\Reporting;

/**
 * Un indicateur, avec sa fiche de gouvernance — §13.4.
 *
 * « Chaque indicateur possède une définition, une formule, une source, une
 * fréquence de rafraîchissement et un niveau d'accès. » Les cinq sont des
 * champs obligatoires de cet objet, et non une documentation à côté : un
 * indicateur dont la formule vit dans un document séparé finit par être lu
 * autrement qu'il n'est calculé. Le §9.1 le redit pour l'écran — les
 * indicateurs doivent être « accompagnés de leur définition ».
 *
 * `value` peut être `null`, et c'est un état à part entière : « pas encore
 * mesuré » n'est pas « zéro ». Afficher 0 pour un indicateur sans source
 * ferait conclure à l'absence de candidatures là où il n'y a qu'une absence de
 * mesure.
 *
 * `masked` porte la protection du §13.4 : « les petits effectifs sont masqués
 * afin de réduire le risque de réidentification ». Un effectif masqué n'est pas
 * un effectif nul, et l'écran doit le dire.
 */
final readonly class Indicator
{
    public function __construct(
        public string $key,
        public IndicatorFamily $family,
        public string $label,
        /** La définition, en une phrase lisible par un gestionnaire. */
        public string $definition,
        /** La formule, dite dans les termes des données réelles. */
        public string $formula,
        /** D'où vient le chiffre : table, colonne, moteur. */
        public string $source,
        public IndicatorRefresh $refresh,
        public IndicatorAccess $access,
        public int|float|null $value = null,
        public ?string $unit = null,
        public bool $masked = false,
    ) {}

    public function withValue(int|float|null $valeur, bool $masked = false): self
    {
        return new self(
            key: $this->key,
            family: $this->family,
            label: $this->label,
            definition: $this->definition,
            formula: $this->formula,
            source: $this->source,
            refresh: $this->refresh,
            access: $this->access,
            value: $masked ? null : $valeur,
            unit: $this->unit,
            masked: $masked,
        );
    }

    /**
     * @return array{
     *     key: string, family: string, label: string, definition: string,
     *     formula: string, source: string, refresh: string, refreshLabel: string,
     *     access: string, accessLabel: string, value: int|float|null,
     *     unit: string|null, masked: bool, measured: bool
     * }
     */
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
            'value' => $this->value,
            'unit' => $this->unit,
            'masked' => $this->masked,
            // « Mesuré » et « non nul » ne se confondent pas : un indicateur
            // mesuré peut valoir zéro, un indicateur non mesuré ne vaut rien.
            'measured' => $this->value !== null || $this->masked,
        ];
    }
}
