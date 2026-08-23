<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Campaign\SaveCampaign;
use App\Http\Presenters\CampaignPresenter;
use App\Http\Requests\Admin\SaveCampaignRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration des campagnes (ADR-008).
 *
 * Contrôleur mince : il lit, met en forme et redirige. Les règles — transitions
 * de statut, invariant de campagne ouverte, audit — sont dans `SaveCampaign`,
 * la validation dans `SaveCampaignRequest`.
 *
 * Pas de `destroy` : `applications.campaign_id` est déclaré `cascadeOnDelete`.
 * Supprimer une campagne emporterait silencieusement toutes ses candidatures,
 * c'est-à-dire des dossiers déposés par des personnes réelles. L'archivage joue
 * ce rôle sans rien détruire — voir ADR-008.
 */
final class CampaignController
{
    public function index(ActiveCampaign $campagnes, CampaignPresenter $presenter): Response
    {
        $active = $campagnes->resolve();

        // Les plus récentes d'abord : une administration ouvre cet écran pour
        // l'édition en cours, pas pour l'historique.
        $toutes = Campaign::query()
            ->orderByRaw('opens_at IS NULL')
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Campaigns/Index', [
            'campaigns' => $toutes
                ->map(fn (Campaign $campagne): array => $presenter->row(
                    $campagne,
                    $active !== null && $active->is($campagne),
                ))
                ->all(),
            'activeId' => $active?->getKey(),
            'createUrl' => route('admin.campaigns.create'),
        ]);
    }

    public function create(CampaignPresenter $presenter): Response
    {
        return Inertia::render('Admin/Campaigns/Form', [
            'campaign' => $presenter->form(null),
            'submitUrl' => route('admin.campaigns.store'),
            'method' => 'post',
        ]);
    }

    public function store(SaveCampaignRequest $request, SaveCampaign $sauvegarde): RedirectResponse
    {
        $campagne = $sauvegarde->create($request->user(), $request->payload());

        return redirect()
            ->route('admin.campaigns.index')
            ->with('status', __('Campagne « :nom » créée.', ['nom' => $campagne->name]));
    }

    public function edit(Campaign $campaign, CampaignPresenter $presenter): Response
    {
        return Inertia::render('Admin/Campaigns/Form', [
            'campaign' => $presenter->form($campaign),
            'submitUrl' => route('admin.campaigns.update', $campaign),
            'method' => 'put',
        ]);
    }

    public function update(SaveCampaignRequest $request, Campaign $campaign, SaveCampaign $sauvegarde): RedirectResponse
    {
        $sauvegarde->update($request->user(), $campaign, $request->payload());

        return redirect()
            ->route('admin.campaigns.index')
            ->with('status', __('Campagne « :nom » enregistrée.', ['nom' => $campaign->name]));
    }
}
