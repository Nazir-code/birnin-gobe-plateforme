<?php

namespace App\Domain\Evaluation;

/**
 * La recommandation portée par une évaluation — §11.3.
 *
 * Le §11.3 attend « une recommandation de rejet ou de short-list », et exige un
 * commentaire pour l'une comme pour l'autre. Il en manque une troisième pour
 * que l'évaluateur puisse rendre son avis sans forcer la main du comité : un
 * dossier honorable qui n'est ni à écarter ni à distinguer. Sans elle,
 * l'évaluateur devrait choisir entre deux avis qu'il ne pense pas, et la
 * recommandation cesserait de vouloir dire quelque chose.
 *
 * **Le commentaire n'est obligatoire que là où le §11.3 l'exige.** Le rendre
 * obligatoire partout paraîtrait plus rigoureux et le serait moins : une
 * exigence systématique produit des « RAS » qui n'apprennent rien, et noie les
 * justifications qui comptent.
 *
 * La recommandation n'est **pas** la décision. Le §11.3 précise que la
 * short-list est « générée comme proposition, puis validée par le comité
 * compétent » : rien ici ne fait passer un dossier en `SHORTLISTED`. Cette
 * étape relève du §12, qui n'existe pas encore.
 */
enum EvaluationRecommendation: string
{
    case SHORTLIST = 'SHORTLIST';
    case RESERVE = 'RESERVE';
    case REJECT = 'REJECT';

    public function label(): string
    {
        return match ($this) {
            self::SHORTLIST => 'Proposer pour la short-list',
            self::RESERVE => 'Avis réservé — ni short-list, ni rejet',
            self::REJECT => 'Recommander le rejet',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::SHORTLIST => 'Le dossier fait partie des meilleurs de sa thématique. Justification obligatoire.',
            self::RESERVE => 'Le dossier est recevable mais ne se distingue pas. Le comité tranchera sur les scores.',
            self::REJECT => 'Le dossier ne devrait pas poursuivre. Justification obligatoire.',
        };
    }

    /** §11.3 : « commentaire obligatoire […] pour toute recommandation de rejet ou de short-list ». */
    public function requiresComment(): bool
    {
        return match ($this) {
            self::SHORTLIST, self::REJECT => true,
            self::RESERVE => false,
        };
    }

    /** @return list<array{value: string, label: string, help: string, requiresComment: bool}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $recommandation): array => [
                'value' => $recommandation->value,
                'label' => $recommandation->label(),
                'help' => $recommandation->help(),
                'requiresComment' => $recommandation->requiresComment(),
            ],
            self::cases(),
        );
    }
}
