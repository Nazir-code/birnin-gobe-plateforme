<?php

namespace App\Domain\Evaluation;

/**
 * L'état d'une évaluation — §11.3.
 *
 * Deux états, et c'est volontaire. Le §11.3 pose que « les évaluations restent
 * indépendantes jusqu'au verrouillage » : avant le verrou une évaluation est un
 * travail privé, après le verrou elle est une pièce du dossier. Il n'y a pas
 * d'état intermédiaire à inventer entre les deux, et pas d'état « annulée » non
 * plus — une évaluation verrouillée ne se déverrouille pas, sinon la
 * comparaison des notes du §11.3 porterait sur des chiffres qui peuvent encore
 * bouger.
 *
 * Ce que le verrou change concrètement :
 *  - le brouillon cesse d'être modifiable, par son auteur comme par quiconque ;
 *  - le score pondéré devient lisible par l'administration ;
 *  - l'évaluation compte dans la couverture du dossier.
 */
enum EvaluationStatus: string
{
    case DRAFT = 'DRAFT';
    case LOCKED = 'LOCKED';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Brouillon',
            self::LOCKED => 'Verrouillée',
        };
    }

    public function estModifiable(): bool
    {
        return $this === self::DRAFT;
    }
}
