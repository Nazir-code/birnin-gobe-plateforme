<?php

namespace App\Domain\Verification;

use App\Domain\Application\ApplicationStatus;

/**
 * Les décisions qu'un vérificateur peut prendre au terme du contrôle — §10.3.
 *
 * Trois, et pas davantage à ce stade. Le §10.3 énumère huit statuts possibles ;
 * deux d'entre eux — « en arbitrage » et « retiré » — n'ont volontairement pas
 * de décision ici :
 *
 *  - **l'arbitrage** suppose la « seconde validation » du §10.1, c'est-à-dire
 *    un second rôle habilité et une file qui lui soit propre. Tant que ce rôle
 *    n'existe pas, une décision « mettre en arbitrage » ne mènerait nulle part
 *    et laisserait des dossiers dans un statut que personne ne peut lever ;
 *  - **le retrait** appartient au candidat, pas au vérificateur, et il n'a pas
 *    d'écran côté candidat.
 *
 * Les inventer maintenant reviendrait à arbitrer par le code deux questions que
 * le cahier des charges laisse à l'organisation.
 *
 * `CLARIFICATION` ouvre l'attente d'une réponse ; la réception de cette réponse
 * (`CLARIFICATION_REQUESTED → CLARIFICATION_RECEIVED`) est un geste du candidat
 * et n'est pas dans cet incrément — la machine à états l'autorise déjà, aucun
 * écran ne la déclenche encore.
 */
enum AdmissibilityDecision: string
{
    case ADMISSIBLE = 'ADMISSIBLE';
    case CLARIFICATION = 'CLARIFICATION';
    case INADMISSIBLE = 'INADMISSIBLE';

    public function label(): string
    {
        return match ($this) {
            self::ADMISSIBLE => 'Déclarer recevable',
            self::CLARIFICATION => 'Demander une clarification',
            self::INADMISSIBLE => 'Déclarer irrecevable',
        };
    }

    /** Le statut que la décision installe. La machine à états reste seule juge du droit d'y aller. */
    public function targetStatus(): ApplicationStatus
    {
        return match ($this) {
            self::ADMISSIBLE => ApplicationStatus::ADMISSIBLE,
            self::CLARIFICATION => ApplicationStatus::CLARIFICATION_REQUESTED,
            self::INADMISSIBLE => ApplicationStatus::INADMISSIBLE,
        };
    }

    /**
     * Le rejet exige un motif principal codifié — §10.3, sans exception.
     *
     * Ni la recevabilité ni la clarification n'en demandent : on ne motive pas
     * un accord, et ce qu'on attend d'une clarification se dit dans le message
     * au candidat, précisément parce qu'il doit être lisible par lui.
     */
    public function requiresPrimaryReason(): bool
    {
        return $this === self::INADMISSIBLE;
    }

    /** Une demande de clarification « fixe une date limite » — §10.3. */
    public function requiresRespondBy(): bool
    {
        return $this === self::CLARIFICATION;
    }

    /**
     * La décision doit-elle être écrite au candidat ?
     *
     * Un rejet et une demande, oui : ce sont les deux cas où le candidat doit
     * agir ou comprendre. Le §10.3 impose que ce texte soit **distinct** de
     * l'observation interne, « afin d'éviter la divulgation d'informations
     * sensibles » — d'où deux champs, jamais un seul recopié.
     */
    public function requiresCandidateMessage(): bool
    {
        return $this !== self::ADMISSIBLE;
    }

    /** @return list<array{value: string, label: string, requiresPrimaryReason: bool, requiresRespondBy: bool, requiresCandidateMessage: bool}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $decision): array => [
                'value' => $decision->value,
                'label' => $decision->label(),
                'requiresPrimaryReason' => $decision->requiresPrimaryReason(),
                'requiresRespondBy' => $decision->requiresRespondBy(),
                'requiresCandidateMessage' => $decision->requiresCandidateMessage(),
            ],
            self::cases(),
        );
    }
}
