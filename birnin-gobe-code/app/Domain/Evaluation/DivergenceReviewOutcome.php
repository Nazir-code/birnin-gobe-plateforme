<?php

namespace App\Domain\Evaluation;

/**
 * Ce qu'une revue d'écart conclut — §11.3.
 *
 * Deux issues, et il n'en faut pas trois. Le §11.3 dit qu'un écart « déclenche
 * une revue » sans dire ce qu'elle décide : ce que le responsable peut
 * réellement faire est soit **demander un avis de plus**, soit **acter que le
 * désaccord est légitime**. Tout le reste — modifier une note, écarter une
 * évaluation — lui est interdit, et doit le rester : une notation qu'un
 * gestionnaire peut retoucher n'est plus une notation indépendante.
 *
 * **Aucune des deux ne modifie une évaluation.** `ADDITIONAL_EVALUATION`
 * n'affecte personne non plus : elle enregistre une intention, et l'affectation
 * se fait sur l'écran du §11.1, qui est le seul à savoir la charge de chacun.
 * Faire les deux d'un clic ici aurait affecté au hasard.
 *
 * **Acter n'est pas faire taire.** Une divergence acceptée reste affichée, avec
 * son motif et la date ; elle cesse seulement d'appeler un geste. Si une
 * évaluation supplémentaire arrive, la revue redevient due — le désaccord n'est
 * plus le même.
 */
enum DivergenceReviewOutcome: string
{
    case ADDITIONAL_EVALUATION = 'ADDITIONAL_EVALUATION';
    case DIVERGENCE_ACCEPTED = 'DIVERGENCE_ACCEPTED';

    public function label(): string
    {
        return match ($this) {
            self::ADDITIONAL_EVALUATION => 'Demander une évaluation supplémentaire',
            self::DIVERGENCE_ACCEPTED => 'Acter le désaccord',
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::ADDITIONAL_EVALUATION => 'Le dossier sera à réaffecter depuis l’écran d’affectation. La revue redeviendra due quand le nouvel avis sera verrouillé.',
            self::DIVERGENCE_ACCEPTED => 'Le désaccord est jugé légitime et documenté. Les notes sont conservées telles quelles, aucune n’est modifiée.',
        };
    }

    /** @return list<array{value: string, label: string, help: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $issue): array => [
                'value' => $issue->value,
                'label' => $issue->label(),
                'help' => $issue->help(),
            ],
            self::cases(),
        );
    }
}
