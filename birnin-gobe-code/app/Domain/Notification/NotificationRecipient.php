<?php

namespace App\Domain\Notification;

/**
 * À qui s'adresse une notification du §8.3.
 *
 * `SECRETARIAT` mérite un mot : le §8.3 veut que la soumission reçue parte au
 * « candidat **et** secrétariat ». Aucune adresse de secrétariat n'est
 * configurée aujourd'hui, et l'inventer enverrait des dossiers déposés vers une
 * boîte qui n'existe pas. Le destinataire est donc déclaré, la livraison
 * enregistrée comme non servie, et l'écran de pilotage la compte — même
 * traitement que le SMS.
 */
enum NotificationRecipient: string
{
    case CANDIDATE = 'CANDIDATE';
    case EVALUATOR = 'EVALUATOR';
    case SECRETARIAT = 'SECRETARIAT';

    public function label(): string
    {
        return match ($this) {
            self::CANDIDATE => 'Candidat',
            self::EVALUATOR => 'Évaluateur',
            self::SECRETARIAT => 'Secrétariat',
        };
    }
}
