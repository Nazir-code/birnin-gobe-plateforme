<?php

namespace App\Domain\Application;

/**
 * Ce qui empêche une candidature d'être déposée, motif par motif.
 *
 * Un booléen aurait suffi au serveur ; il n'aurait rien dit au candidat. La
 * future page « Relecture / envoi » doit pouvoir écrire *pourquoi* le bouton
 * est fermé, et l'administration doit pouvoir le lire sans rejouer le calcul.
 * D'où une liste de motifs nommés plutôt qu'un refus opaque.
 *
 * Les valeurs sont des clés stables en anglais, comme `ApplicationStatus` :
 * elles voyagent jusqu'au front et peuvent finir dans un journal. Le libellé
 * français est d'affichage, jamais persisté ni comparé.
 */
enum SubmissionBlocker: string
{
    /** Le dossier porte déjà un numéro : il a été déposé. */
    case ALREADY_SUBMITTED = 'ALREADY_SUBMITTED';

    /** L'édition n'est pas en phase de dépôt (préparation, close, archivée). */
    case CAMPAIGN_NOT_OPEN = 'CAMPAIGN_NOT_OPEN';

    /** L'édition est déclarée ouverte mais sa date d'ouverture est à venir. */
    case CAMPAIGN_NOT_YET_OPEN = 'CAMPAIGN_NOT_YET_OPEN';

    /** La date limite de dépôt est passée. */
    case DEADLINE_PASSED = 'DEADLINE_PASSED';

    /** Au moins une règle d'éligibilité bloquante est déclenchée. */
    case ELIGIBILITY_BLOCKING = 'ELIGIBILITY_BLOCKING';

    /** L'auto-test d'éligibilité attend encore des réponses. */
    case ELIGIBILITY_INCOMPLETE = 'ELIGIBILITY_INCOMPLETE';

    /** Une ou plusieurs sections du dossier ne sont pas achevées. */
    case SECTIONS_INCOMPLETE = 'SECTIONS_INCOMPLETE';

    public function label(): string
    {
        return match ($this) {
            self::ALREADY_SUBMITTED => 'Cette candidature a déjà été déposée.',
            self::CAMPAIGN_NOT_OPEN => 'Cette édition ne reçoit pas de candidature.',
            self::CAMPAIGN_NOT_YET_OPEN => 'Le dépôt des candidatures n’a pas encore commencé.',
            self::DEADLINE_PASSED => 'La date limite de dépôt est passée.',
            self::ELIGIBILITY_BLOCKING => 'Vous ne remplissez pas les conditions d’éligibilité.',
            self::ELIGIBILITY_INCOMPLETE => 'L’auto-test d’éligibilité attend encore des réponses.',
            self::SECTIONS_INCOMPLETE => 'Toutes les étapes du dossier ne sont pas terminées.',
        };
    }
}
