<?php

namespace App\Domain\Notification;

/**
 * Les six événements notifiables du §8.3.
 *
 * C'est le tableau du cahier des charges rendu exécutable : chaque cas déclare
 * son destinataire, les canaux que le §8.3 réclame, et ce que le message doit
 * au minimum contenir. Un test compare cet enum au tableau, ligne par ligne —
 * sans quoi « on enverra un message » resterait une intention, et personne ne
 * verrait qu'il en manque un.
 *
 * **Les canaux déclarés ne sont pas les canaux servis.** Le §8.3 veut
 * « Email/SMS » sur quatre événements ; aucun fournisseur SMS n'est choisi, et
 * ce choix n'est pas technique — il engage un opérateur, une identité
 * d'expéditeur, un coût par message et quelqu'un pour lire les réponses. Deux
 * réponses auraient été mauvaises : prétendre que le SMS part, ou retirer le
 * SMS du modèle et perdre l'exigence. La troisième est celle retenue : le canal
 * est déclaré ici, la livraison est enregistrée comme non servie faute de
 * fournisseur, et l'écran de pilotage la compte. Fermé et visible, comme
 * l'analyse antivirus.
 *
 * **L'e-mail part toujours.** Un candidat qui a coché « SMS » dans son profil
 * doit quand même apprendre qu'il est déclaré irrecevable. Le §8.3 écrit
 * « Email/SMS » et non « Email ou SMS au choix » : l'e-mail est le canal de
 * référence, le SMS un doublage. Laisser une préférence supprimer le seul canal
 * qui fonctionne ferait disparaître des décisions qui engagent le candidat.
 */
enum NotificationEvent: string
{
    case ACCOUNT_CREATED = 'ACCOUNT_CREATED';
    case CLOSING_REMINDER = 'CLOSING_REMINDER';
    case SUBMISSION_RECEIVED = 'SUBMISSION_RECEIVED';
    case CLARIFICATION_REQUESTED = 'CLARIFICATION_REQUESTED';
    case STAGE_DECISION = 'STAGE_DECISION';
    case ASSIGNMENT = 'ASSIGNMENT';

    public function label(): string
    {
        return match ($this) {
            self::ACCOUNT_CREATED => 'Compte créé',
            self::CLOSING_REMINDER => 'Rappel de clôture',
            self::SUBMISSION_RECEIVED => 'Soumission reçue',
            self::CLARIFICATION_REQUESTED => 'Clarification demandée',
            self::STAGE_DECISION => 'Décision d’étape',
            self::ASSIGNMENT => 'Affectation',
        };
    }

    /** Le destinataire, tel que le §8.3 le nomme. */
    public function recipient(): NotificationRecipient
    {
        return match ($this) {
            self::ACCOUNT_CREATED,
            self::CLOSING_REMINDER,
            self::SUBMISSION_RECEIVED,
            self::CLARIFICATION_REQUESTED,
            self::STAGE_DECISION => NotificationRecipient::CANDIDATE,
            self::ASSIGNMENT => NotificationRecipient::EVALUATOR,
        };
    }

    /**
     * Les canaux que le §8.3 réclame pour cet événement.
     *
     * `SUBMISSION_RECEIVED` est le seul à ne pas prévoir de SMS : le cahier des
     * charges y demande « Email + reçu », c'est-à-dire un document, pas un
     * message court. L'affectation non plus — un évaluateur reçoit une liste de
     * dossiers, pas une ligne de texte.
     *
     * @return list<NotificationChannel>
     */
    public function channels(): array
    {
        return match ($this) {
            self::SUBMISSION_RECEIVED, self::ASSIGNMENT => [NotificationChannel::EMAIL],
            default => [NotificationChannel::EMAIL, NotificationChannel::SMS],
        };
    }

    /**
     * Ce que le message doit au minimum porter, mot pour mot du §8.3.
     *
     * Conservé dans le domaine et non dans le gabarit : c'est l'exigence, et
     * elle doit survivre à une réécriture du texte. Un test s'en sert pour
     * vérifier que le contenu généré la couvre.
     *
     * @return list<string>
     */
    public function requiredContent(): array
    {
        return match ($this) {
            self::ACCOUNT_CREATED => ['vérification', 'sécurité', 'lien de reprise'],
            self::CLOSING_REMINDER => ['temps restant', 'complétude', 'lien direct'],
            self::SUBMISSION_RECEIVED => ['numéro', 'date', 'campagne', 'résumé', 'contact'],
            self::CLARIFICATION_REQUESTED => ['point précis', 'délai', 'canal de réponse'],
            self::STAGE_DECISION => ['statut', 'suite'],
            self::ASSIGNMENT => ['nombre de dossiers', 'échéance', 'déclaration de conflit'],
        };
    }

    /**
     * L'événement porte-t-il une décision qui engage son destinataire ?
     *
     * Ceux-là ne se taisent jamais : ni une préférence de canal, ni un
     * désabonnement — quand il existera — ne doit pouvoir empêcher un candidat
     * d'apprendre qu'il est écarté. Les autres sont des services rendus, et
     * pourront un jour se couper.
     */
    public function estOpposable(): bool
    {
        return match ($this) {
            self::SUBMISSION_RECEIVED, self::CLARIFICATION_REQUESTED, self::STAGE_DECISION => true,
            self::ACCOUNT_CREATED, self::CLOSING_REMINDER, self::ASSIGNMENT => false,
        };
    }
}
