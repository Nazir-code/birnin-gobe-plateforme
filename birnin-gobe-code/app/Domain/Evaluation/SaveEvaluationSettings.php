<?php

namespace App\Domain\Evaluation;

use App\Domain\Audit\AuditWriter;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les paramètres d'évaluation d'une campagne — §9.2.
 *
 * Copie fidèle de la forme de `SaveEligibilitySettings`, et pour les mêmes deux
 * raisons :
 *
 * 1. **Les autres clés de `settings` survivent.** Le bloc `evaluation` est
 *    remplacé, jamais `settings` entier : une phase qui n'expose pas une clé
 *    n'a pas à l'effacer, et `eligibility` vit dans le même document.
 *
 * 2. **Rien n'est écrit quand rien ne change.** Un enregistrement qui ne
 *    modifie aucun paramètre n'est pas une décision ; l'inscrire au journal
 *    noierait les vraies décisions.
 *
 * Un bloc vide fait disparaître la clé plutôt que d'écrire un objet vide :
 * « aucun paramètre arrêté » et « un objet de paramètres vide » se relisent
 * alors de la même façon, et `EvaluationSettings` n'a qu'un seul cas à traiter.
 */
final readonly class SaveEvaluationSettings
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(User $administrateur, Campaign $campagne, EvaluationSettings $reglages): Campaign
    {
        $avant = EvaluationSettings::fromCampaign($campagne)->toStoredArray();
        $apres = $reglages->toStoredArray();

        if ($avant === $apres) {
            return $campagne;
        }

        return DB::transaction(function () use ($administrateur, $campagne, $avant, $apres): Campaign {
            $settings = is_array($campagne->settings) ? $campagne->settings : [];

            if ($apres === []) {
                unset($settings[EvaluationSettings::KEY]);
            } else {
                $settings[EvaluationSettings::KEY] = $apres;
            }

            $campagne->forceFill(['settings' => $settings])->save();

            $this->audit->write(
                actorId: $administrateur->getKey(),
                action: 'CAMPAIGN_EVALUATION_SETTINGS_UPDATED',
                targetType: Campaign::class,
                targetId: (string) $campagne->getKey(),
                oldValue: [EvaluationSettings::KEY => $avant],
                newValue: [EvaluationSettings::KEY => $apres],
                reason: null,
            );

            return $campagne;
        });
    }
}
