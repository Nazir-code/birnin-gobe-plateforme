<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Administration\SettingsDomain;
use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Evaluation\SaveEvaluationSettings;
use App\Http\Requests\Admin\SaveEvaluationSettingsRequest;
use App\Models\Campaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Paramètres administrables — §9.2.
 *
 * **Cet écran est d'abord un inventaire honnête.** Le §9.2 énumère neuf
 * domaines paramétrables ; deux le sont vraiment (campagne, éligibilité), un
 * l'est partiellement (évaluation), six ne le sont pas. Les neuf sont affichés,
 * chacun avec son périmètre exact et la raison de son état.
 *
 * Le choix mérite d'être défendu, parce que la tentation inverse est forte : on
 * pourrait n'afficher que les trois domaines outillés et paraître complet. Un
 * comité de pilotage qui ouvre cet écran doit pouvoir répondre à « qu'est-ce
 * que je peux régler avant d'ouvrir la campagne, et qu'est-ce qui restera figé
 * dans le code ». Masquer les six manquants lui ferait découvrir la réponse en
 * production.
 *
 * L'écran porte en outre le seul réglage que cette phase ajoute réellement :
 * le nombre minimal d'évaluations et le seuil d'écart (§9.2, ligne
 * « Évaluation »). Ils sont ici parce que l'affectation du §11.1 en dépend —
 * sans minimum arrêté, la couverture d'un dossier reste inconnue.
 *
 * Les deux domaines déjà outillés ne sont **pas** réimplémentés : l'écran
 * renvoie vers `admin.campaigns.edit` et `admin.campaigns.eligibility.edit`.
 * Un second formulaire écrivant les mêmes colonnes finirait par diverger du
 * premier.
 */
final class SettingsController
{
    public function index(Request $request, ActiveCampaign $campagneActive): Response
    {
        $campagne = $this->campagne($request, $campagneActive);

        return Inertia::render('Admin/Settings/Index', [
            'domains' => array_map(
                static fn (SettingsDomain $domaine): array => $domaine->toArray(),
                SettingsDomain::cases(),
            ),
            'campaign' => $campagne === null ? null : [
                'id' => $campagne->getKey(),
                'name' => $campagne->name,
                'code' => $campagne->code,
                'campaignUrl' => route('admin.campaigns.edit', $campagne),
                'eligibilityUrl' => route('admin.campaigns.eligibility.edit', $campagne),
            ],
            'evaluation' => EvaluationSettings::fromCampaign($campagne)->toArray(),
            'limits' => [
                'maxEvaluations' => EvaluationSettings::MAX_EVALUATIONS,
                'maxScoreGap' => EvaluationSettings::MAX_SCORE_GAP,
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
            'campaignsUrl' => route('admin.campaigns.index'),
        ]);
    }

    /**
     * Enregistre les paramètres d'évaluation d'une campagne.
     *
     * La campagne est dans l'URL, jamais dans le corps : un réglage se rattache
     * à une édition, et laisser le formulaire désigner sa cible permettrait
     * d'écrire sur une campagne qu'on ne regardait pas.
     */
    public function updateEvaluation(
        SaveEvaluationSettingsRequest $request,
        Campaign $campaign,
        SaveEvaluationSettings $sauvegarde,
    ): RedirectResponse {
        $sauvegarde->handle($request->user(), $campaign, $request->reglages());

        // Retour sur le même écran : ces réglages se posent par ajustements
        // successifs, et l'administrateur doit relire l'état après chaque
        // enregistrement.
        return redirect()
            ->route('admin.settings.index', ['campaign' => $campaign->getKey()])
            ->with('status', 'Paramètres d’évaluation enregistrés.');
    }

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
