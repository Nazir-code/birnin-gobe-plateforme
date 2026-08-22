<?php

namespace App\Domain\Application;

enum ApplicationStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case PENDING_REVIEW = 'PENDING_REVIEW';
    case CLARIFICATION_REQUESTED = 'CLARIFICATION_REQUESTED';
    case CLARIFICATION_RECEIVED = 'CLARIFICATION_RECEIVED';
    case ADMISSIBLE = 'ADMISSIBLE';
    case INADMISSIBLE = 'INADMISSIBLE';
    case IN_EVALUATION = 'IN_EVALUATION';
    case EVALUATED = 'EVALUATED';
    case SHORTLISTED = 'SHORTLISTED';
    case NOT_SHORTLISTED = 'NOT_SHORTLISTED';
    case FINALIST = 'FINALIST';
    case SELECTED = 'SELECTED';
    case WAITLISTED = 'WAITLISTED';
    case NOT_SELECTED = 'NOT_SELECTED';

    /**
     * Libellé d'affichage.
     *
     * Ne sert qu'à l'écran. Le statut persisté et comparé reste la valeur de
     * l'enum : « Brouillon » n'existe nulle part en base, conformément au
     * contrat « aucun statut métier stocké comme libellé français ».
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::SUBMITTED => 'Soumise',
            self::PENDING_REVIEW => 'En cours d’examen',
            self::CLARIFICATION_REQUESTED => 'Complément demandé',
            self::CLARIFICATION_RECEIVED => 'Complément reçu',
            self::ADMISSIBLE => 'Recevable',
            self::INADMISSIBLE => 'Irrecevable',
            self::IN_EVALUATION => 'En évaluation',
            self::EVALUATED => 'Évaluée',
            self::SHORTLISTED => 'Présélectionnée',
            self::NOT_SHORTLISTED => 'Non présélectionnée',
            self::FINALIST => 'Finaliste',
            self::SELECTED => 'Lauréate',
            self::WAITLISTED => 'Liste d’attente',
            self::NOT_SELECTED => 'Non retenue',
        };
    }
}
