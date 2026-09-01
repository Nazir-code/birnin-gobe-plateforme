<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Alerting\Alert;
use App\Domain\Alerting\ComputeAlerts;
use App\Domain\Campaign\ActiveCampaign;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alertes de pilotage — §9.3, « alertes sur retards et anomalies ».
 *
 * Une route, en lecture. Il n'y a **rien à écrire** : une alerte n'est pas un
 * enregistrement qu'on acquitte, c'est un calcul sur l'état réel des dossiers.
 * Elle disparaît quand sa cause disparaît, et pas avant.
 *
 * Ce choix a une conséquence assumée : aucun bouton « ignorer ». Un
 * acquittement laisserait une alerte éteinte alors que la situation persiste —
 * exactement ce qui apprend à ne plus lire l'écran. Le seul moyen de faire
 * taire une alerte est de traiter ce qu'elle signale, et chacune porte le lien
 * qui y mène.
 *
 * **La consultation n'est pas journalisée**, comme le reste de
 * l'administration.
 */
final class AlertController
{
    public function __invoke(Request $request, ComputeAlerts $moteur, ActiveCampaign $campagneActive): Response
    {
        $campagne = $this->campagne($request, $campagneActive);

        return Inertia::render('Admin/Alerts/Index', [
            'alerts' => array_map(
                static fn (Alert $alerte): array => $alerte->toArray(),
                $moteur->pour($campagne),
            ),
            'campaign' => $campagne === null ? null : [
                'id' => $campagne->getKey(),
                'name' => $campagne->name,
                'code' => $campagne->code,
            ],
            'filters' => ['campaign' => $campagne === null ? '' : (string) $campagne->getKey()],
            'options' => [
                'campaigns' => Campaign::query()
                    ->orderByRaw('opens_at IS NULL')
                    ->orderByDesc('opens_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(static fn (Campaign $c): array => [
                        'value' => (string) $c->getKey(),
                        'label' => $c->name.' ('.$c->code.')',
                    ])
                    ->all(),
            ],
            'thresholds' => [
                'controlDelayDays' => ComputeAlerts::JOURS_AVANT_RETARD_DE_CONTROLE,
                'closingHorizonDays' => ComputeAlerts::JOURS_AVANT_CLOTURE,
            ],
        ]);
    }

    /** Par défaut la campagne active : c'est celle qu'on pilote. */
    private function campagne(Request $request, ActiveCampaign $campagneActive): ?Campaign
    {
        $demande = $request->input('campaign');

        if ($demande === null || $demande === '') {
            return $campagneActive->resolve();
        }

        if (! is_numeric($demande) || (int) $demande <= 0) {
            return null;
        }

        return Campaign::query()->find((int) $demande);
    }
}
