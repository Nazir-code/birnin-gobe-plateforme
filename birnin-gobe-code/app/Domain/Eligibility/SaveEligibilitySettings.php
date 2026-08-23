<?php

namespace App\Domain\Eligibility;

use App\Domain\Audit\AuditWriter;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les paramètres d'éligibilité d'une campagne (ADR-010).
 *
 * Cas d'usage plutôt que méthode de contrôleur, comme `SaveCampaign` : la
 * fusion avec les autres clés de `settings`, la transaction et l'écriture
 * d'audit tiennent ensemble ou pas du tout.
 *
 * Deux propriétés que cette classe garantit et qu'un `update()` direct sur le
 * modèle ne garantirait pas :
 *
 * 1. **Les autres clés de `settings` survivent.** Le cahier des charges (§9.2)
 *    prévoit d'y loger compte à rebours, période de grâce, contacts et textes
 *    légaux. Ce sont des écrans à venir, mais rien ne dit qu'ils seront écrits
 *    après celui-ci — et une phase qui n'expose pas une clé n'a pas à
 *    l'effacer. On remplace donc le sous-tableau `eligibility`, pas `settings`.
 *
 * 2. **Rien n'est écrit quand rien ne change.** Un enregistrement qui ne
 *    modifie aucun paramètre n'est pas une décision : l'inscrire au journal
 *    d'audit noierait les vraies décisions — celles qui, elles, changent le
 *    verdict annoncé aux candidats — sous des lignes sans contenu.
 *
 * La symétrie s'arrête au bloc `eligibility` lui-même, qui est remplacé en
 * entier : ses clés sont exactement les cinq règles d'`EligibilityRule`, toutes
 * exposées par l'écran. Le jour où le §9.2 fera entrer les motifs d'exclusion
 * dans ce bloc, ils y entreront avec leur champ — pas comme une clé inconnue
 * qu'il aurait fallu deviner et conserver à l'avance.
 */
final readonly class SaveEligibilitySettings
{
    public function __construct(private AuditWriter $audit) {}

    public function handle(User $administrateur, Campaign $campagne, EligibilitySettings $reglages): Campaign
    {
        $avant = EligibilitySettings::fromCampaign($campagne)->toStoredArray();
        $apres = $reglages->toStoredArray();

        if ($avant === $apres) {
            return $campagne;
        }

        return DB::transaction(function () use ($administrateur, $campagne, $avant, $apres): Campaign {
            $settings = is_array($campagne->settings) ? $campagne->settings : [];

            // Un bloc vide n'est pas stocké comme objet vide : la clé disparaît.
            // « Aucun critère publié » et « un objet de critères vide » se
            // relisent alors de la même façon, et le moteur n'a qu'un seul cas
            // à traiter.
            if ($apres === []) {
                unset($settings['eligibility']);
            } else {
                $settings['eligibility'] = $apres;
            }

            $campagne->forceFill(['settings' => $settings])->save();

            // Le journal conserve les deux états du bloc, pas la campagne
            // entière : c'est ce qui permet de répondre à « selon quels critères
            // ce dossier a-t-il été jugé, et depuis quand ? ».
            $this->audit->write(
                actorId: $administrateur->getKey(),
                action: 'CAMPAIGN_ELIGIBILITY_UPDATED',
                targetType: Campaign::class,
                targetId: (string) $campagne->getKey(),
                oldValue: ['eligibility' => $avant],
                newValue: ['eligibility' => $apres],
                reason: null,
            );

            return $campagne;
        });
    }
}
