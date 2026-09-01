<?php

namespace App\Domain\Alerting;

/**
 * Une alerte de pilotage — §9.3, « alertes sur retards et anomalies ».
 *
 * **Une alerte n'est pas une ligne de base.** Elle est recalculée à chaque
 * ouverture de l'écran, à partir de l'état réel des dossiers. La persister
 * obligerait à la lever quand la situation se résout, donc à écrire un
 * mécanisme d'extinction — et une alerte qui survit à sa cause est pire que pas
 * d'alerte du tout : elle apprend à ignorer l'écran.
 *
 * Chaque alerte porte **où aller** (`url`) et **combien** (`count`). Une alerte
 * sans destination laisse le gestionnaire chercher lui-même les dossiers
 * concernés, et il finit par ne plus la lire. L'URL pointe une liste déjà
 * filtrée sur exactement le périmètre que l'alerte a compté.
 */
final readonly class Alert
{
    public function __construct(
        public string $key,
        public AlertSeverity $severity,
        public string $label,
        /** Ce que l'alerte a mesuré, dit en une phrase. */
        public string $detail,
        /** Ce qu'il faut faire. Jamais un constat déguisé en consigne. */
        public string $action,
        public int $count,
        public ?string $url = null,
    ) {}

    /**
     * @return array{key: string, severity: string, severityLabel: string, label: string, detail: string, action: string, count: int, url: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'severity' => $this->severity->value,
            'severityLabel' => $this->severity->label(),
            'label' => $this->label,
            'detail' => $this->detail,
            'action' => $this->action,
            'count' => $this->count,
            'url' => $this->url,
        ];
    }
}
