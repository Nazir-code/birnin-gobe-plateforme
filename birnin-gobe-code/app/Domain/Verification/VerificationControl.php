<?php

namespace App\Domain\Verification;

/**
 * Les sept contrôles de la matrice minimale d'admissibilité — §10.2.
 *
 * « Minimale » est le mot du cahier des charges : la grille peut s'enrichir,
 * elle ne peut pas se réduire. Les sept cas sont donc repris un pour un, dans
 * l'ordre du tableau, et `cases()` fait foi — un écran qui n'afficherait que
 * six lignes laisserait un contrôle non fait passer pour un contrôle passé.
 *
 * Chaque contrôle porte **sa** liste de verdicts. C'est ce qui empêche de
 * cocher « doublon confirmé » sur le contrôle des délais : `VerificationOutcome`
 * est un enum commun aux sept familles, et sans cette liste blanche, la colonne
 * accepterait n'importe quelle valeur des vingt-deux.
 *
 * Un contrôle est aussi un **motif d'irrecevabilité** : le §10.3 exige un motif
 * principal codifié, et le motif naturel d'un rejet est le contrôle qui a
 * bloqué. Aucun second référentiel de motifs n'est créé — il divergerait de la
 * grille dès la première campagne.
 */
enum VerificationControl: string
{
    case DEPOSIT_DEADLINE = 'DEPOSIT_DEADLINE';
    case PROFILE = 'PROFILE';
    case COMPLETENESS = 'COMPLETENESS';
    case DOCUMENTS = 'DOCUMENTS';
    case THEME = 'THEME';
    case UNIQUENESS = 'UNIQUENESS';
    case INTEGRITY = 'INTEGRITY';

    public function label(): string
    {
        return match ($this) {
            self::DEPOSIT_DEADLINE => 'Dépôt dans les délais',
            self::PROFILE => 'Profil éligible',
            self::COMPLETENESS => 'Dossier complet',
            self::DOCUMENTS => 'Pièces valides',
            self::THEME => 'Thématique recevable',
            self::UNIQUENESS => 'Unicité',
            self::INTEGRITY => 'Intégrité',
        };
    }

    /** Le « traitement attendu » du §10.2, dit au vérificateur. */
    public function help(): string
    {
        return match ($this) {
            self::DEPOSIT_DEADLINE => 'Calcul serveur. Toute dérogation exige un motif écrit.',
            self::PROFILE => 'Règles d’âge, de zone, de nationalité ou de résidence et de type de candidat.',
            self::COMPLETENESS => 'Lister exactement les éléments manquants ; le candidat doit savoir quoi fournir.',
            self::DOCUMENTS => 'Ouvrir chaque pièce, vérifier le format et la lisibilité, puis coder le motif.',
            self::THEME => 'Un changement de thématique ne se fait qu’avec justification et notification.',
            self::UNIQUENESS => 'Rapprochement par courriel, téléphone et identifiant. La décision reste humaine.',
            self::INTEGRITY => 'Déclaration, plagiat présumé, fraude documentaire. Accès limité, preuve conservée.',
        };
    }

    /**
     * Les verdicts que ce contrôle accepte, dans l'ordre du §10.2.
     *
     * @return list<VerificationOutcome>
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::DEPOSIT_DEADLINE => [
                VerificationOutcome::ON_TIME,
                VerificationOutcome::WAIVER,
                VerificationOutcome::LATE,
            ],
            self::PROFILE => [
                VerificationOutcome::PROFILE_ELIGIBLE,
                VerificationOutcome::PROFILE_TO_CONFIRM,
                VerificationOutcome::PROFILE_INELIGIBLE,
            ],
            self::COMPLETENESS => [
                VerificationOutcome::FILE_COMPLETE,
                VerificationOutcome::FILE_CLARIFICATION,
                VerificationOutcome::FILE_INCOMPLETE,
            ],
            self::DOCUMENTS => [
                VerificationOutcome::DOCUMENTS_VALID,
                VerificationOutcome::DOCUMENTS_UNREADABLE,
                VerificationOutcome::DOCUMENTS_EXPIRED,
                VerificationOutcome::DOCUMENTS_INCONSISTENT,
            ],
            self::THEME => [
                VerificationOutcome::THEME_ADMISSIBLE,
                VerificationOutcome::THEME_REDIRECT,
                VerificationOutcome::THEME_REJECTED,
            ],
            self::UNIQUENESS => [
                VerificationOutcome::UNIQUE,
                VerificationOutcome::DUPLICATE_SUSPECTED,
                VerificationOutcome::DUPLICATE_CONFIRMED,
            ],
            self::INTEGRITY => [
                VerificationOutcome::NO_ALERT,
                VerificationOutcome::ALERT,
                VerificationOutcome::EXCLUSION,
            ],
        };
    }

    /** Ce contrôle accepte-t-il ce verdict ? La seule porte, et elle est fermée par défaut. */
    public function accepts(VerificationOutcome $outcome): bool
    {
        return in_array($outcome, $this->outcomes(), true);
    }

    /**
     * La grille mise en forme pour l'écran.
     *
     * @return list<array{value: string, label: string, help: string, outcomes: list<array{value: string, label: string, severity: string, requiresObservation: bool}>}>
     */
    public static function matrix(): array
    {
        return array_map(
            static fn (self $controle): array => [
                'value' => $controle->value,
                'label' => $controle->label(),
                'help' => $controle->help(),
                'outcomes' => array_map(
                    static fn (VerificationOutcome $verdict): array => [
                        'value' => $verdict->value,
                        'label' => $verdict->label(),
                        'severity' => $verdict->severity()->value,
                        'requiresObservation' => $verdict->requiresObservation(),
                    ],
                    $controle->outcomes(),
                ),
            ],
            self::cases(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $controle): array => ['value' => $controle->value, 'label' => $controle->label()],
            self::cases(),
        );
    }
}
