<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Campaign\CampaignStatus;
use App\Http\Presenters\CampaignPresenter;
use App\Models\Campaign;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord de l'administration.
 *
 * L'écran affichait « aucune campagne » en dur, faute de gestion des campagnes.
 * Il lit désormais la base : c'est la seule information que cette phase peut
 * afficher honnêtement.
 *
 * Les indicateurs de candidatures — dossiers soumis, files de vérification,
 * charge des évaluateurs, répartition géographique — restent en état d'attente.
 * Ils dépendent d'Admin Phase 3 ; les brancher sur des requêtes improvisées
 * donnerait l'illusion d'un pilotage qui n'existe pas encore.
 */
final class DashboardController
{
    public function __invoke(ActiveCampaign $campagnes, CampaignPresenter $presenter): Response
    {
        $active = $campagnes->resolve();

        // Distinguer « aucune campagne ouverte » de « une campagne ouverte mais
        // hors de sa fenêtre » : le second cas est une configuration à corriger,
        // pas un état d'attente normal, et l'administrateur doit le voir.
        $ouverte = $active ?? Campaign::query()
            ->where('status', CampaignStatus::OPEN->value)
            ->orderByDesc('id')
            ->first();

        return Inertia::render('Admin/Dashboard', [
            'campaign' => $ouverte === null ? null : $presenter->row($ouverte, $active !== null && $active->is($ouverte)),
            'campaignsCount' => Campaign::query()->count(),
            'campaignsUrl' => route('admin.campaigns.index'),
        ]);
    }
}
