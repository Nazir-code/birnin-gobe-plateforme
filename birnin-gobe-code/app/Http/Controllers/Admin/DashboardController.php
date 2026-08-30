<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Alerting\ComputeAlerts;
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
 * **Les cinq compteurs sont désormais tous comptés, et tous cliquables.**
 * « Admissibles » et « Alertes actives » gardaient un tiret et la mention « à
 * venir » : c'était vrai quand l'écran a été écrit, et faux depuis qu'ADR-013 a
 * ouvert le contrôle d'admissibilité et ADR-014 les alertes de pilotage. Un
 * tableau de bord qui annonce « à venir » ce qui existe est pire qu'un tableau
 * de bord incomplet — il détourne de fonctionnalités livrées.
 *
 * **Chaque compteur mène à la liste de ce qu'il a compté**, filtrée sur
 * exactement le même périmètre. C'est la règle déjà posée pour les alertes du
 * §9.3 : un chiffre qui ouvre une liste plus large fait douter du chiffre. D'où
 * le choix de compter « admissibles » sur le seul statut `ADMISSIBLE` — les
 * dossiers déjà passés en évaluation ne figureraient pas dans la liste que le
 * lien ouvre, et le compteur mentirait sur sa destination.
 */
final class DashboardController
{
    public function __invoke(ActiveCampaign $campagnes, CampaignPresenter $presenter, ComputeAlerts $alertes): Response
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
            // Les cinq compteurs, chacun avec la destination qui montre
            // exactement ce qu'il a compté. Tous sont de vrais comptages : plus
            // aucun tiret, parce que plus aucun de ces workflows ne manque.
            'applications' => [
                'total' => Application::query()->count(),
                'drafts' => Application::query()->where('status', ApplicationStatus::DRAFT->value)->count(),
                'submitted' => Application::query()->where('status', ApplicationStatus::SUBMITTED->value)->count(),
                // Recevables et en attente d'affectation. Le comptage suit le
                // statut courant, pas l'historique : c'est ce que la liste
                // filtrée montrera.
                'admissible' => Application::query()->where('status', ApplicationStatus::ADMISSIBLE->value)->count(),
                'url' => route('admin.applications.index'),
                'draftsUrl' => route('admin.applications.index', ['status' => ApplicationStatus::DRAFT->value]),
                'submittedUrl' => route('admin.applications.index', ['status' => ApplicationStatus::SUBMITTED->value]),
                'admissibleUrl' => route('admin.applications.index', ['status' => ApplicationStatus::ADMISSIBLE->value]),
            ],
            // Les alertes du §9.3, recalculées comme sur leur propre écran :
            // c'est le même appel, donc jamais deux chiffres différents pour la
            // même chose.
            'alerts' => [
                'count' => count($alertes->pour($active)),
                'url' => route('admin.alerts.index'),
            ],
        ]);
    }
}
