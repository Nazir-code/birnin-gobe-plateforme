<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\StartApplication;
use App\Domain\Campaign\ActiveCampaign;
use App\Http\Presenters\ApplicationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ouverture et reprise de la candidature.
 *
 * Contrôleur mince : il lit la session, résout la campagne, délègue à
 * `StartApplication` et redirige. Aucune règle métier ici.
 */
final class ApplicationController
{
    /**
     * « Commencer ma candidature ».
     *
     * Rejouable sans dommage : `StartApplication` renvoie le brouillon existant
     * plutôt que d'en créer un second. Un double-clic, un rafraîchissement après
     * envoi ou une requête rejouée aboutissent donc au même dossier.
     */
    public function store(
        Request $request,
        ActiveCampaign $campaigns,
        StartApplication $start,
        ApplicationPresenter $presenter,
    ): RedirectResponse {
        $campaign = $campaigns->resolve();

        // Le bouton n'est pas affiché hors période, mais l'URL, elle, reste
        // appelable : la fenêtre de dépôt se vérifie côté serveur.
        abort_if($campaign === null, 403, 'Aucune campagne n’est ouverte aux candidatures.');

        $application = $start->handle($request->user(), $campaign);

        return redirect()->to(
            $presenter->sectionUrl($application, $application->current_step)
                ?? route('candidate.dashboard'),
        );
    }

    /**
     * Point d'entrée « Ma candidature » de la navigation.
     *
     * Ne crée rien : renvoie vers la section en cours si un dossier existe,
     * vers le tableau de bord sinon — c'est là que se trouve l'appel à
     * commencer. Une navigation ne doit jamais écrire en base.
     */
    public function show(Request $request, ActiveCampaign $campaigns, ApplicationPresenter $presenter): RedirectResponse
    {
        $campaign = $campaigns->resolve();
        $candidate = $request->user();

        $application = $campaign !== null
            ? $candidate->applications()->where('campaign_id', $campaign->getKey())->first()
            : $candidate->applications()->latest('id')->first();

        if ($application === null) {
            return redirect()->route('candidate.dashboard');
        }

        return redirect()->to(
            $presenter->sectionUrl($application, $application->current_step)
                ?? route('candidate.dashboard'),
        );
    }
}
