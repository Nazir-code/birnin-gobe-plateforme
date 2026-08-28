<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Evaluation\DivergenceQuery;
use App\Domain\Evaluation\EvaluationDivergence;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Evaluation\RecordDivergenceReview;
use App\Http\Presenters\AdminDivergencePresenter;
use App\Http\Requests\Admin\RecordDivergenceReviewRequest;
use App\Models\Application;
use App\Models\Campaign;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La revue d'écart entre évaluateurs — §11.3.
 *
 * **Trois routes, dont une seule écrit, et elle n'écrit pas de note.** C'est la
 * propriété que ce contrôleur existe pour tenir : le §11.3 accorde au
 * gestionnaire « l'avancement, pas une modification silencieuse des notes ».
 * Aucune méthode d'ici ne touche `evaluation_scores` ni `evaluations` — la
 * seule écriture est une ligne de revue, en ajout seul.
 *
 * **Sans seuil arrêté, l'écran ne se tait pas : il dit pourquoi il ne peut rien
 * dire**, et renvoie vers les paramètres. Afficher une file vide laisserait
 * croire qu'aucun dossier ne diverge, alors que rien n'a été comparé — c'est
 * exactement le malentendu qu'ADR-014 refuse pour la couverture.
 *
 * **La consultation n'est pas journalisée**, comme partout ailleurs dans
 * l'administration : le journal sert à retrouver des décisions, et y verser
 * chaque ouverture d'écran les noierait.
 */
final class DivergenceController
{
    public function index(Request $request, AdminDivergencePresenter $presenter, ActiveCampaign $campagnes): Response
    {
        $campagne = $this->campagne($request, $campagnes);
        $scope = $this->scope($request);

        $divergences = (new DivergenceQuery($campagne, $scope))->get();

        return Inertia::render('Admin/Divergences/Index', [
            'divergences' => array_map(
                static fn (EvaluationDivergence $divergence): array => $presenter->row($divergence),
                $divergences,
            ),
            'campaign' => $campagne === null ? null : [
                'id' => $campagne->getKey(),
                'name' => $campagne->name,
                'code' => $campagne->code,
            ],
            // Le seuil est le préalable de tout cet écran : il voyage avec lui,
            // et vaut `null` tant que personne ne l'a arrêté.
            'threshold' => EvaluationSettings::fromCampaign($campagne)->scoreGapThreshold,
            'totalDue' => DivergenceQuery::totalARevoir($campagne),
            'filters' => ['scope' => $scope, 'campaign' => (string) $request->query('campaign', '')],
            'options' => [
                'scopes' => DivergenceQuery::scopeOptions(),
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
            'urls' => [
                'settings' => route('admin.settings.index'),
                'reset' => route('admin.divergences.index'),
            ],
        ]);
    }

    public function show(Application $application, AdminDivergencePresenter $presenter): Response
    {
        $application->load([
            'campaign',
            'evaluations' => fn ($q) => $q->verrouillees()->with(['scores', 'evaluator:id,name']),
        ]);

        $divergence = EvaluationDivergence::pour(
            $application,
            EvaluationSettings::fromCampaign($application->campaign)->scoreGapThreshold,
            $application->evaluationReviews()->orderByDesc('created_at')->orderByDesc('id')->first(),
        );

        // Un dossier qui n'a qu'un avis arrêté n'a pas d'écart : l'écran de
        // comparaison n'aurait rien à comparer, et une page vide se lit comme
        // une panne.
        abort_unless($divergence->comparable(), 404);

        $historique = $application->evaluationReviews()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('Admin/Divergences/Show', $presenter->dossier($divergence, $historique));
    }

    public function store(
        RecordDivergenceReviewRequest $request,
        Application $application,
        RecordDivergenceReview $enregistrer,
    ): RedirectResponse {
        try {
            $enregistrer->handle(
                dossier: $application,
                issue: $request->issue(),
                motif: (string) $request->input('reason'),
                actor: $request->user(),
            );
        } catch (DomainException $refus) {
            return back()->withErrors(['review' => $this->message($refus)]);
        }

        return redirect()
            ->route('admin.divergences.index')
            ->with('status', 'Revue enregistrée.');
    }

    private function campagne(Request $request, ActiveCampaign $campagnes): ?Campaign
    {
        $demandee = $request->query('campaign');

        // Réduction et non refus, comme partout en lecture : un paramètre
        // illisible est ignoré, il ne produit pas une page d'erreur.
        if (is_string($demandee) && ctype_digit($demandee)) {
            $campagne = Campaign::query()->find((int) $demandee);

            if ($campagne !== null) {
                return $campagne;
            }
        }

        return $campagnes->resolve();
    }

    private function scope(Request $request): string
    {
        $scope = (string) $request->query('scope', 'a_revoir');

        return in_array($scope, DivergenceQuery::SCOPES, strict: true) ? $scope : 'a_revoir';
    }

    private function message(DomainException $refus): string
    {
        return match (explode(':', $refus->getMessage(), 2)[0]) {
            'REVIEW_REASON_REQUIRED' => 'Une revue doit être motivée : c’est cette phrase qui défendra l’arbitrage.',
            'REVIEW_NO_THRESHOLD' => 'Aucun seuil d’écart n’est arrêté pour cette campagne : réglez-le avant d’arbitrer (§9.2).',
            'REVIEW_NOT_COMPARABLE' => 'Ce dossier ne porte pas deux évaluations verrouillées : il n’y a pas encore d’écart à revoir.',
            default => 'Cette opération a été refusée : le dossier n’est plus dans l’état attendu.',
        };
    }
}
