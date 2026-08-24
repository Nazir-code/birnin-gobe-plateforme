<?php

namespace App\Domain\Application;

/**
 * Stade de maturité de la solution proposée.
 *
 * Le cahier des charges demande la donnée à deux endroits — §5.2 étape 5
 * (« maturité ») et §7.1 rubrique Identification (« stade de maturité ») — sans
 * jamais fournir l'échelle. Comme pour `EducationLevel`, refuser d'en proposer
 * une reviendrait à ne pas collecter la donnée du tout.
 *
 * L'échelle retenue est celle, courante, d'un dispositif d'innovation : de
 * l'idée non encore construite jusqu'à la solution déjà en service et prête à
 * s'étendre. Elle **décrit** et n'exclut personne : ce n'est pas un critère
 * d'éligibilité (ADR-007) et ce n'est pas non plus une note — l'étape 5 recueille
 * ce que le candidat déclare, l'évaluation viendra plus tard et ailleurs.
 *
 * Le §9.2 range les options de formulaire parmi les paramètres administrables :
 * cette liste pourra être ajustée sans redéploiement le jour où cet écran
 * d'administration existera.
 */
enum MaturityStage: string
{
    case IDEA = 'IDEA';
    case PROTOTYPE = 'PROTOTYPE';
    case PILOT = 'PILOT';
    case DEPLOYED = 'DEPLOYED';
    case SCALING = 'SCALING';

    public function label(): string
    {
        return match ($this) {
            self::IDEA => 'Idée — rien n’est encore construit',
            self::PROTOTYPE => 'Prototype — une première version existe',
            self::PILOT => 'Pilote — testée avec de vrais utilisateurs',
            self::DEPLOYED => 'Déployée — déjà en service',
            self::SCALING => 'En extension — passage à l’échelle engagé',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            static fn (self $stade): array => ['value' => $stade->value, 'label' => $stade->label()],
            self::cases(),
        );
    }
}
