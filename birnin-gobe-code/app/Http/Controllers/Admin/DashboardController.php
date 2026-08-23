<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Campaign\CampaignStatus;
use App\Http\Presenters\CampaignPresenter;
use App\Models\Application;
use App\Models\Campaign;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tableau de bord de l'administration.
 *
 * L'écran affichait « aucune campagne » en dur, faute de gestion des campagnes.
 * Il lit désormais la base.
 *
 * Deux indicateurs de candidatures y sont désormais réellement comptés : le
 * nombre de dossiers et le nombre de brouillons. Les autres — dossiers soumis,
 * admissibles, alertes, files de vérification, charge des évaluateurs,
 * répartition géographique — gardent leur tiret, et ce n'est pas un oubli : les
 * workflows qui les produiraient n'existent pas encore. Un « 0 » se lirait
 * comme un comptage, un tiret se lit comme « pas encore mesurable ».
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
            // Les trois indicateurs que cette phase peut annoncer honnêtement :
            // le nombre de dossiers, combien sont encore des brouillons, et
            // combien ont été déposés. « Soumis » a rejoint les deux autres avec
            // le workflow de dépôt — son zéro est désormais un vrai comptage.
            // « Admissibles » et « alertes » gardent leur tiret : les workflows
            // qui les produiraient n'existent pas, et un « 0 » s'y lirait comme
            // un comptage, pas comme une absence de fonctionnalité.
            'applications' => [
                'total' => Application::query()->count(),
                'drafts' => Application::query()->where('status', ApplicationStatus::DRAFT->value)->count(),
                'submitted' => Application::query()->where('status', ApplicationStatus::SUBMITTED->value)->count(),
                'url' => route('admin.applications.index'),
            ],
        ]);
    }
}
