<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Eligibility\SaveEligibilitySettings;
use App\Http\Presenters\EligibilitySettingsPresenter;
use App\Http\Requests\Admin\SaveEligibilitySettingsRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres d'éligibilité d'une campagne (ADR-010).
 *
 * Écran distinct de `CampaignController` alors que les deux écrivent la même
 * ligne : le formulaire de campagne porte l'identité et le calendrier —
 * qui existent dès la création — tandis que ces critères se fixent plus tard,
 * souvent après arbitrage du comité de pilotage, et se modifient sans qu'on
 * veuille rouvrir les dates. Les mêler ferait d'une correction de libellé une
 * occasion de republier des critères.
 *
 * Contrôleur mince, comme le reste de l'administration : la validation est dans
 * `SaveEligibilitySettingsRequest`, la fusion avec les autres clés de
 * `settings` et l'audit dans `SaveEligibilitySettings`.
 */
final class CampaignEligibilityController
{
    public function edit(Campaign $campaign, EligibilitySettingsPresenter $presenter): Response
    {
        return Inertia::render('Admin/Campaigns/Eligibility', [
            ...$presenter->form($campaign),
            'submitUrl' => route('admin.campaigns.eligibility.update', $campaign),
        ]);
    }

    public function update(
        SaveEligibilitySettingsRequest $request,
        Campaign $campaign,
        SaveEligibilitySettings $sauvegarde,
    ): RedirectResponse {
        $sauvegarde->handle($request->user(), $campaign, $request->reglages());

        // Retour sur le même écran, pas sur la liste : publier des critères se
        // fait par ajustements successifs, et l'administrateur doit relire
        // l'état des cinq règles après chaque enregistrement.
        return redirect()
            ->route('admin.campaigns.eligibility.edit', $campaign)
            ->with('status', __('Critères d\'éligibilité de « :nom » enregistrés.', ['nom' => $campaign->name]));
    }
}
