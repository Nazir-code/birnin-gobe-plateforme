<?php

namespace App\Http\Controllers\Candidate;

use App\Http\Presenters\ApplicationPresenter;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accusé de dépôt — l'écran qui suit la soumission.
 *
 * Il ne dépose rien : il montre ce que `SubmitApplication` a écrit. Numéro,
 * horodatage et statut sont relus en base, jamais reconstitués côté navigateur.
 *
 * L'écran reste accessible après coup, et c'est le but : un candidat qui ferme
 * son onglet, se reconnecte trois jours plus tard ou change de téléphone doit
 * pouvoir retrouver son numéro de dépôt. C'est la seule preuve qu'il a entre les
 * mains, aucun courriel n'étant envoyé à ce stade.
 *
 * Ce que cet écran ne promet pas, parce que rien de tout cela n'existe : aucun
 * accusé par courriel ou SMS, aucune évaluation démarrée, aucune décision
 * d'admissibilité. Annoncer un message qui n'arrivera pas ferait attendre une
 * confirmation que le candidat n'aurait aucun moyen d'obtenir.
 */
final class SubmittedController
{
    public function __invoke(Application $application, ApplicationPresenter $presenter): Response|RedirectResponse
    {
        // Un brouillon n'a pas d'accusé : on renvoie à la relecture plutôt que
        // d'afficher un écran de confirmation vide, qui laisserait croire à un
        // dépôt qui n'a pas eu lieu.
        if ($application->isDraft()) {
            return redirect()->route('candidate.application.review', $application);
        }

        return Inertia::render('Candidate/Application/Submitted', [
            'application' => [
                ...$presenter->summary($application),
                'submissionNumber' => $application->submission_number,
                'submittedAt' => $application->submitted_at?->toIso8601String(),
            ],
            'campaign' => $application->campaign === null ? null : $presenter->campaign($application->campaign),
            'dashboardUrl' => route('candidate.dashboard'),
        ]);
    }
}
