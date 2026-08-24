<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\StartApplication;
use App\Domain\Campaign\ActiveCampaign;
use App\Http\Presenters\ApplicationPresenter;
use App\Models\Application;
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
     * Ne crée rien : renvoie vers la section en cours si un dossier modifiable
     * existe, vers le tableau de bord sinon — c'est là que se trouve l'appel à
     * commencer. Une navigation ne doit jamais écrire en base.
     */
    public function show(Request $request, ActiveCampaign $campaigns, ApplicationPresenter $presenter): RedirectResponse
    {
        return $this->versLaSection(
            $this->dossierDuCandidat($request, $campaigns),
            $presenter,
            fn (Application $application): ?string => $presenter->sectionUrl($application, $application->current_step),
        );
    }

    /**
     * Point d'entrée « Mon profil » de la navigation.
     *
     * Le profil du candidat n'est pas un module séparé : c'est l'étape 2 de sa
     * candidature, avec ses champs, sa validation et sa sauvegarde. Cette entrée
     * y mène, elle ne duplique rien.
     *
     * Conséquence assumée : sans dossier ouvert, il n'y a pas encore de profil à
     * remplir, et le candidat atterrit sur le tableau de bord — là où se trouve
     * le bouton qui ouvre le dossier. Créer un brouillon au passage serait une
     * écriture déclenchée par une simple navigation.
     */
    public function profile(Request $request, ActiveCampaign $campaigns, ApplicationPresenter $presenter): RedirectResponse
    {
        return $this->versLaSection(
            $this->dossierDuCandidat($request, $campaigns),
            $presenter,
            fn (Application $application): ?string => $presenter->sectionUrl($application, ApplicationSection::PROFILE),
        );
    }

    /**
     * Le dossier du candidat authentifié, et lui seul.
     *
     * La requête ne porte aucun identifiant : il est relu depuis la session.
     * C'est ce qui fait qu'aucune de ces deux entrées ne peut mener au dossier
     * d'un autre, quelle que soit l'URL tapée.
     */
    private function dossierDuCandidat(Request $request, ActiveCampaign $campaigns): ?Application
    {
        $campaign = $campaigns->resolve();
        $candidate = $request->user();

        return $campaign !== null
            ? $candidate->applications()->where('campaign_id', $campaign->getKey())->first()
            : $candidate->applications()->latest('id')->first();
    }

    /**
     * Redirige vers une section du dossier, ou vers le tableau de bord.
     *
     * Deux cas mènent au tableau de bord, pour deux raisons différentes :
     *
     *   **aucun dossier** — il n'y a rien à ouvrir, et c'est là que se trouve
     *     l'appel à commencer ;
     *   **dossier déposé** — les écrans de section sont des formulaires. Un
     *     dossier soumis n'est plus modifiable (`ApplicationPolicy::update`), et
     *     y renvoyer le candidat lui présenterait des champs qu'il croirait
     *     pouvoir corriger jusqu'à ce que l'enregistrement échoue. Le tableau de
     *     bord, lui, dit l'état réel du dossier — statut, complétude, étapes.
     *     C'est le meilleur écran de lecture que le produit possède aujourd'hui ;
     *     l'écran de relecture définitif appartient à l'étape 9.
     *
     * @param  callable(Application): (string|null)  $cible
     */
    private function versLaSection(?Application $application, ApplicationPresenter $presenter, callable $cible): RedirectResponse
    {
        if ($application === null || ! $application->isDraft()) {
            return redirect()->route('candidate.dashboard');
        }

        return redirect()->to($cible($application) ?? route('candidate.dashboard'));
    }
}
