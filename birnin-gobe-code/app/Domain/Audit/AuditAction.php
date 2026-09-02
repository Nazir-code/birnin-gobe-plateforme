<?php

namespace App\Domain\Audit;

/**
 * Les actions que le journal d'audit sait nommer.
 *
 * `audit_events.action` est une colonne de texte libre, et le restera : le
 * journal doit pouvoir enregistrer une action que cet enum ne connaît pas
 * encore. Un événement écrit hier ne doit pas devenir illisible parce qu'une
 * classe a changé aujourd'hui — c'est la première qualité qu'on attend d'un
 * journal.
 *
 * Cet enum ne contraint donc pas l'écriture. Il sert à la **lecture** : donner
 * un libellé français à ce qui est stocké en clé stable, et proposer un filtre
 * qui ne soit pas une saisie libre. Une action inconnue s'affiche telle quelle,
 * en clair — voir `AdminAuditPresenter`.
 *
 * Les valeurs sont exactement celles que les cas d'usage écrivent aujourd'hui :
 * `StartApplication`, `StoreApplicationDocument`, `SubmitApplication`,
 * `SaveCampaign`, `SaveEligibilitySettings`, `SaveVerificationChecks`,
 * `DecideAdmissibility`, `AssignApplications`, `ReleaseAssignment`,
 * `AcceptEvaluationCharter`, `LockEvaluation` et `RecordDivergenceReview`.
 * Ajouter une action ailleurs sans
 * l'ajouter ici ne casse rien ; ça la prive seulement de son libellé.
 */
enum AuditAction: string
{
    case APPLICATION_CREATED = 'APPLICATION_CREATED';
    case APPLICATION_DOCUMENT_UPLOADED = 'APPLICATION_DOCUMENT_UPLOADED';
    case APPLICATION_DOCUMENT_REPLACED = 'APPLICATION_DOCUMENT_REPLACED';
    case APPLICATION_DOCUMENT_DELETED = 'APPLICATION_DOCUMENT_DELETED';
    case APPLICATION_DOCUMENT_SERVED_UNSCANNED = 'APPLICATION_DOCUMENT_SERVED_UNSCANNED';
    case APPLICATION_SUBMITTED = 'APPLICATION_SUBMITTED';
    case VERIFICATION_CHECKS_RECORDED = 'VERIFICATION_CHECKS_RECORDED';
    case ADMISSIBILITY_DECIDED = 'ADMISSIBILITY_DECIDED';
    case EVALUATION_ASSIGNED = 'EVALUATION_ASSIGNED';
    case EVALUATION_ASSIGNMENT_RELEASED = 'EVALUATION_ASSIGNMENT_RELEASED';
    case EVALUATION_CHARTER_ACCEPTED = 'EVALUATION_CHARTER_ACCEPTED';
    case EVALUATION_LOCKED = 'EVALUATION_LOCKED';
    case EVALUATION_DIVERGENCE_REVIEWED = 'EVALUATION_DIVERGENCE_REVIEWED';
    case INTERNAL_USER_CREATED = 'INTERNAL_USER_CREATED';
    case CAMPAIGN_CREATED = 'CAMPAIGN_CREATED';
    case CAMPAIGN_UPDATED = 'CAMPAIGN_UPDATED';
    case CAMPAIGN_STATUS_CHANGED = 'CAMPAIGN_STATUS_CHANGED';
    case CAMPAIGN_ELIGIBILITY_UPDATED = 'CAMPAIGN_ELIGIBILITY_UPDATED';
    case CAMPAIGN_EVALUATION_SETTINGS_UPDATED = 'CAMPAIGN_EVALUATION_SETTINGS_UPDATED';

    /** Libellé d'affichage. Jamais persisté, jamais comparé. */
    public function label(): string
    {
        return match ($this) {
            self::APPLICATION_CREATED => 'Candidature ouverte',
            self::APPLICATION_DOCUMENT_UPLOADED => 'Pièce déposée',
            self::APPLICATION_DOCUMENT_REPLACED => 'Pièce remplacée',
            self::APPLICATION_DOCUMENT_DELETED => 'Pièce retirée',
            self::APPLICATION_DOCUMENT_SERVED_UNSCANNED => 'Pièce non analysée ouverte par dérogation',
            self::APPLICATION_SUBMITTED => 'Candidature déposée',
            self::VERIFICATION_CHECKS_RECORDED => 'Grille d’admissibilité enregistrée',
            self::ADMISSIBILITY_DECIDED => 'Décision d’admissibilité',
            self::EVALUATION_ASSIGNED => 'Dossier affecté à un évaluateur',
            self::EVALUATION_ASSIGNMENT_RELEASED => 'Affectation levée',
            self::EVALUATION_CHARTER_ACCEPTED => 'Charte d’évaluation acceptée',
            self::EVALUATION_LOCKED => 'Évaluation verrouillée',
            self::EVALUATION_DIVERGENCE_REVIEWED => 'Écart de notation revu',
            self::INTERNAL_USER_CREATED => 'Compte interne créé',
            self::CAMPAIGN_CREATED => 'Campagne créée',
            self::CAMPAIGN_UPDATED => 'Campagne modifiée',
            self::CAMPAIGN_STATUS_CHANGED => 'Statut de campagne changé',
            self::CAMPAIGN_ELIGIBILITY_UPDATED => 'Critères d’éligibilité modifiés',
            self::CAMPAIGN_EVALUATION_SETTINGS_UPDATED => 'Paramètres d’évaluation modifiés',
        };
    }

    /**
     * Poids de l'événement, pour l'accent que l'écran lui donne.
     *
     * Trois niveaux et pas davantage : un journal où tout est signalé ne
     * signale rien. « Décisif » est réservé aux événements irréversibles ou
     * visibles des candidats — un dépôt, l'ouverture d'une campagne, un
     * changement de critères.
     */
    public function weight(): AuditWeight
    {
        return match ($this) {
            self::APPLICATION_SUBMITTED,
            self::ADMISSIBILITY_DECIDED,
            self::CAMPAIGN_STATUS_CHANGED,
            self::CAMPAIGN_ELIGIBILITY_UPDATED,
            self::CAMPAIGN_EVALUATION_SETTINGS_UPDATED,
            // Habiliter quelqu'un à noter des candidatures est décisif : le
            // geste crée un pouvoir, il ne fait pas qu'enregistrer un fait.
            self::INTERNAL_USER_CREATED,
            self::EVALUATION_LOCKED => AuditWeight::DECISIVE,

            self::APPLICATION_DOCUMENT_DELETED,
            self::APPLICATION_DOCUMENT_SERVED_UNSCANNED,
            self::VERIFICATION_CHECKS_RECORDED,
            self::EVALUATION_ASSIGNED,
            self::EVALUATION_ASSIGNMENT_RELEASED,
            self::EVALUATION_CHARTER_ACCEPTED,
            self::EVALUATION_DIVERGENCE_REVIEWED,
            self::CAMPAIGN_UPDATED => AuditWeight::NOTABLE,

            default => AuditWeight::ROUTINE,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $action): array => ['value' => $action->value, 'label' => $action->label()],
            self::cases(),
        );
    }
}
