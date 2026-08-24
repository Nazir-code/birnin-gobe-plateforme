<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Http\Presenters\AdminApplicationPresenter;
use App\Http\Presenters\ApplicationPresenter;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Étape 9 — relecture du dossier avant dépôt.
 *
 * **Cet écran ne stocke rien.** Il ne demande aucune réponse, n'en enregistre
 * aucune, et « Relecture / envoi » n'est pas une section persistée : c'est une
 * projection en lecture de ce que les étapes 1 à 8 ont déjà écrit. Marquer
 * `REVIEW` comme achevée parce que le candidat a ouvert la page serait un
 * mensonge doublé d'un piège — la section entrerait dans le parcours ouvert et
 * la progression exigerait à jamais une ligne que personne n'écrit.
 *
 * La copie officielle du dossier, elle, n'est pas faite ici non plus :
 * `SubmitApplication` la fige au moment du dépôt, dans la même transaction que
 * le numéro et l'horodatage.
 *
 * Trois sources, aucune règle réinventée :
 *
 *   `AdminApplicationPresenter::sections()`  les réponses en couples lisibles,
 *       section par section. Le même rendu que celui du back-office : deux mises
 *       en forme des mêmes réponses finiraient par diverger, et le candidat doit
 *       relire exactement ce que le vérificateur lira.
 *   `SubmissionReadiness`                    la recevabilité et ses motifs. Le
 *       navigateur n'en déduit rien : il affiche ce que le domaine a décidé.
 *   `ApplicationPresenter::sectionUrl()`     le lien « Modifier » de chaque
 *       étape, qui rend `null` tant qu'une section n'a pas d'écran.
 *
 * Ce dernier point est le raccord avec l'étape 8, développée en parallèle : le
 * jour où « Pièces / déclarations » aura sa route, son lien « Modifier »
 * apparaîtra ici sans qu'une ligne de ce fichier ne change, et ses libellés de
 * champs suivront le même chemin par `champs()`. Rien n'est deviné de son
 * modèle de documents.
 */
final class ReviewController
{
    public function __invoke(
        Application $application,
        ApplicationPresenter $presenter,
        AdminApplicationPresenter $lecture,
        EvaluateEligibility $eligibilite,
    ): Response|RedirectResponse {
        // Un dossier déposé n'a plus rien à relire avant envoi : son écran, c'est
        // la confirmation. La redirection évite d'afficher des boutons
        // « Modifier » que le serveur refuserait de toute façon.
        if (! $application->isDraft()) {
            return redirect()->route('candidate.application.submitted', $application);
        }

        $application->load(['candidate', 'campaign', 'sections']);

        return Inertia::render('Candidate/Application/Review', [
            'application' => $presenter->summary($application),
            'campaign' => $application->campaign === null ? null : $presenter->campaign($application->campaign),
            'steps' => $presenter->steps($application),
            'sections' => $this->sections($application, $presenter, $lecture),
            // Recalculée à chaque affichage, jamais mémorisée : une campagne peut
            // s'être close depuis le dernier passage du candidat.
            'submission' => SubmissionReadiness::for($application, $eligibilite)->toArray(),
            'submitUrl' => route('candidate.application.submit', $application),
            'dashboardUrl' => route('candidate.dashboard'),
        ]);
    }

    /**
     * Les neuf sections telles que le dossier les porte, avec leur lien de
     * correction.
     *
     * `editUrl` vaut `null` pour une section sans écran — l'affichage montre
     * alors son état sans proposer un lien mort.
     *
     * Le test `isImplemented()` n'est pas redondant avec `sectionUrl()`, il le
     * corrige pour cet usage : le présentateur, lui, retombe volontairement sur
     * la première étape quand la section demandée n'a pas d'écran, parce qu'une
     * *reprise* doit atterrir quelque part. Un bouton « Modifier » n'a pas cette
     * excuse — envoyer à l'étape 1 quelqu'un qui a cliqué sur « Pièces » serait
     * pire que ne rien proposer.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(
        Application $application,
        ApplicationPresenter $presenter,
        AdminApplicationPresenter $lecture,
    ): array {
        return array_map(
            static function (array $section) use ($application, $presenter): array {
                $cas = ApplicationSection::from($section['key']);

                return [
                    ...$section,
                    'editUrl' => $cas->isImplemented() ? $presenter->sectionUrl($application, $cas) : null,
                ];
            },
            $lecture->sections($application),
        );
    }
}
