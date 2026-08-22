<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Campaign\ActiveCampaign;
use App\Http\Presenters\ApplicationPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord du candidat.
 *
 * Deux états, décidés par la base et non par le navigateur : aucune candidature
 * pour la campagne en cours, ou un dossier à reprendre.
 */
final class DashboardController
{
    public function __invoke(Request $request, ActiveCampaign $campaigns, ApplicationPresenter $presenter): Response
    {
        $candidate = $request->user();
        $campaign = $campaigns->resolve();

        // Hors campagne ouverte, on montre tout de même le dernier dossier :
        // une candidature déposée reste consultable après la clôture.
        $application = $campaign !== null
            ? $candidate->applications()->where('campaign_id', $campaign->getKey())->first()
            : $candidate->applications()->latest('id')->first();

        return Inertia::render('Candidate/Dashboard', [
            'campaign' => $campaign === null ? null : $presenter->campaign($campaign),
            'application' => $application === null ? null : $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'startUrl' => route('candidate.application.store'),
        ]);
    }
}
