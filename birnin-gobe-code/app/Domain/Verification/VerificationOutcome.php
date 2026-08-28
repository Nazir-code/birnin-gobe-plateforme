<?php

namespace App\Domain\Verification;

/**
 * Les verdicts possibles d'un contrôle d'admissibilité — colonne « Valeur » du
 * tableau du §10.2.
 *
 * Un seul enum pour les sept contrôles, et non un enum par contrôle : la
 * colonne `verification_checks.outcome` est unique, et sept types distincts
 * l'obligeraient à accepter n'importe laquelle des sept familles sans pouvoir
 * dire laquelle est légitime pour la ligne lue. C'est `VerificationControl` qui
 * porte la liste blanche — un verdict de la mauvaise famille est refusé là.
 *
 * Trois niveaux de gravité, et un seul est décisif :
 *
 *  - `SATISFIED` — le contrôle est passé, il n'y a rien à faire ;
 *  - `ATTENTION` — quelque chose demande un examen humain, sans conclure. Le
 *    §10.3 est formel : « un signalement automatique — doublon, incohérence ou
 *    document suspect — n'entraîne jamais à lui seul l'exclusion d'un
 *    candidat ». Un doublon probable ou une alerte d'intégrité vivent donc ici,
 *    pas dans `BLOCKING` ;
 *  - `BLOCKING` — un motif d'irrecevabilité, constaté par une personne. C'est
 *    le seul niveau qui puisse fonder un rejet.
 *
 * Aucun de ces verdicts n'est calculé : ils sont cochés. Ce que la machine sait
 * voir seule est présenté à part, comme signalement — voir
 * `AutomaticFindings`.
 */
enum VerificationOutcome: string
{
    // Dépôt dans les délais.
    case ON_TIME = 'ON_TIME';
    case WAIVER = 'WAIVER';
    case LATE = 'LATE';

    // Profil éligible.
    case PROFILE_ELIGIBLE = 'PROFILE_ELIGIBLE';
    case PROFILE_TO_CONFIRM = 'PROFILE_TO_CONFIRM';
    case PROFILE_INELIGIBLE = 'PROFILE_INELIGIBLE';

    // Dossier complet.
    case FILE_COMPLETE = 'FILE_COMPLETE';
    case FILE_CLARIFICATION = 'FILE_CLARIFICATION';
    case FILE_INCOMPLETE = 'FILE_INCOMPLETE';

    // Pièces valides.
    case DOCUMENTS_VALID = 'DOCUMENTS_VALID';
    case DOCUMENTS_UNREADABLE = 'DOCUMENTS_UNREADABLE';
    case DOCUMENTS_EXPIRED = 'DOCUMENTS_EXPIRED';
    case DOCUMENTS_INCONSISTENT = 'DOCUMENTS_INCONSISTENT';

    // Thématique recevable.
    case THEME_ADMISSIBLE = 'THEME_ADMISSIBLE';
    case THEME_REDIRECT = 'THEME_REDIRECT';
    case THEME_REJECTED = 'THEME_REJECTED';

    // Unicité.
    case UNIQUE = 'UNIQUE';
    case DUPLICATE_SUSPECTED = 'DUPLICATE_SUSPECTED';
    case DUPLICATE_CONFIRMED = 'DUPLICATE_CONFIRMED';

    // Intégrité.
    case NO_ALERT = 'NO_ALERT';
    case ALERT = 'ALERT';
    case EXCLUSION = 'EXCLUSION';

    /** Libellé d'affichage. Jamais persisté, jamais comparé. */
    public function label(): string
    {
        return match ($this) {
            self::ON_TIME => 'Conforme',
            self::WAIVER => 'Dérogation',
            self::LATE => 'Hors délai',

            self::PROFILE_ELIGIBLE => 'Profil éligible',
            self::PROFILE_TO_CONFIRM => 'À vérifier',
            self::PROFILE_INELIGIBLE => 'Profil non éligible',

            self::FILE_COMPLETE => 'Dossier complet',
            self::FILE_CLARIFICATION => 'Clarification à demander',
            self::FILE_INCOMPLETE => 'Dossier incomplet',

            self::DOCUMENTS_VALID => 'Pièces valides',
            self::DOCUMENTS_UNREADABLE => 'Pièces illisibles',
            self::DOCUMENTS_EXPIRED => 'Pièces expirées',
            self::DOCUMENTS_INCONSISTENT => 'Pièces incohérentes',

            self::THEME_ADMISSIBLE => 'Thématique recevable',
            self::THEME_REDIRECT => 'Réorientation proposée',
            self::THEME_REJECTED => 'Thématique non recevable',

            self::UNIQUE => 'Dossier unique',
            self::DUPLICATE_SUSPECTED => 'Doublon probable',
            self::DUPLICATE_CONFIRMED => 'Doublon confirmé',

            self::NO_ALERT => 'Aucune alerte',
            self::ALERT => 'Alerte',
            self::EXCLUSION => 'Motif d’exclusion',
        };
    }

    public function severity(): VerificationSeverity
    {
        return match ($this) {
            self::ON_TIME,
            self::PROFILE_ELIGIBLE,
            self::FILE_COMPLETE,
            self::DOCUMENTS_VALID,
            self::THEME_ADMISSIBLE,
            self::UNIQUE,
            self::NO_ALERT => VerificationSeverity::SATISFIED,

            // Une dérogation n'est pas un manquement : c'est un dépôt hors
            // délai que quelqu'un a assumé. Elle reste en attention parce que
            // le §10.2 lui impose « un motif et un approbateur ».
            self::WAIVER,
            self::PROFILE_TO_CONFIRM,
            self::FILE_CLARIFICATION,
            self::THEME_REDIRECT,
            // Les deux signalements que le §10.3 protège explicitement.
            self::DUPLICATE_SUSPECTED,
            self::ALERT => VerificationSeverity::ATTENTION,

            self::LATE,
            self::PROFILE_INELIGIBLE,
            self::FILE_INCOMPLETE,
            self::DOCUMENTS_UNREADABLE,
            self::DOCUMENTS_EXPIRED,
            self::DOCUMENTS_INCONSISTENT,
            self::THEME_REJECTED,
            self::DUPLICATE_CONFIRMED,
            self::EXCLUSION => VerificationSeverity::BLOCKING,
        };
    }

    /**
     * Le verdict exige-t-il que le vérificateur écrive une observation ?
     *
     * Tout ce qui n'est pas « le contrôle est passé » doit laisser une phrase.
     * Un rejet sans motif écrit est incontestable au mauvais sens du terme :
     * le candidat ne peut pas le comprendre, et l'administration ne peut pas
     * le défendre.
     */
    public function requiresObservation(): bool
    {
        return $this->severity() !== VerificationSeverity::SATISFIED;
    }
}
