<?php

namespace App\Domain\Verification;

/**
 * Un signalement automatique — « anomalies automatiques » du §10.1.
 *
 * Ce que la machine sait voir seule, rattaché au contrôle qu'il éclaire. Ce
 * n'est **pas** un verdict : le verdict est coché par une personne, dans
 * `verification_checks`. Le §10.3 le dit en toutes lettres — « un signalement
 * automatique n'entraîne jamais à lui seul l'exclusion d'un candidat » — et
 * c'est pourquoi ces objets ne sont jamais persistés et n'ont pas de gravité
 * bloquante : ils informent le vérificateur, ils ne décident pas à sa place.
 *
 * Recalculés à chaque ouverture de l'écran, comme le verdict d'éligibilité :
 * figés en base, ils deviendraient faux le jour où la campagne change ses
 * critères, sans que rien ne le signale.
 */
final readonly class AutomaticFinding
{
    public function __construct(
        public VerificationControl $control,
        public string $label,
        public string $detail,
    ) {}

    /** @return array{control: string, controlLabel: string, label: string, detail: string} */
    public function toArray(): array
    {
        return [
            'control' => $this->control->value,
            'controlLabel' => $this->control->label(),
            'label' => $this->label,
            'detail' => $this->detail,
        ];
    }
}
