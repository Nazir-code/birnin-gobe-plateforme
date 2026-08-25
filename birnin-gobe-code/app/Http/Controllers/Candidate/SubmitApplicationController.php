<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\SubmissionReadiness;
use App\Domain\Application\SubmitApplication;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Models\Application;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dépôt d'une candidature par son propriétaire.
 *
 * **Il n'y a pas encore d'écran d'envoi**, et cette phase n'en construit pas :
 * les étapes 5 à 8 n'existent pas, donc aucun dossier n'est déposable et un
 * bouton n'aurait rien à faire. La route existe parce qu'un contrat de dépôt
 * qu'on ne peut pas appeler n'est pas un contrat vérifié — celle-ci l'est, par
 * les tests, dans les deux sens : elle dépose quand tout est réuni, elle refuse
 * quand ce n'est pas le cas.
 *
 * Le contrôleur ne décide de rien. L'appartenance et le verrou après dépôt
 * viennent de `ApplicationPolicy` via `can:update,application` ; la recevabilité
 * vient de `SubmissionReadiness`, rejouée sous verrou par `SubmitApplication`.
 * Ici on ne fait que traduire un refus du domaine en réponse HTTP.
 *
 * Un refus rend **422 avec ses motifs**, pas un 403 muet : le candidat doit
 * pouvoir lire ce qui manque, et la future page « Relecture / envoi » affichera
 * cette liste telle quelle.
 */
final class SubmitApplicationController
{
    public function __invoke(
        Request $request,
        Application $application,
        SubmitApplication $deposer,
        EvaluateEligibility $eligibilite,
    ): JsonResponse|RedirectResponse {
        try {
            $application = $deposer->handle($application, $request->user());
        } catch (DomainException) {
            $verdict = SubmissionReadiness::for($application->fresh(), $eligibilite);

            if ($this->attendDuJson($request)) {
                return response()->json(['submission' => $verdict->toArray()], 422);
            }

            // Retour à la relecture, qui recalcule la recevabilité et affichera
            // donc le motif réel — pas celui d'il y a dix minutes.
            return back()->with('submissionRefusee', $verdict->toArray());
        }

        if ($this->attendDuJson($request)) {
            return response()->json([
                'status' => $application->status->value,
                'statusLabel' => $application->status->label(),
                'submissionNumber' => $application->submission_number,
                'submittedAt' => $application->submitted_at?->toIso8601String(),
            ]);
        }

        // L'accusé de dépôt, et non le tableau de bord : un candidat qui vient
        // de déposer attend son numéro, tout de suite et à l'écran. La route
        // reste consultable ensuite, ce qui rend le geste rejouable — un
        // rafraîchissement ou un second envoi aboutit au même accusé, puisque
        // `SubmitApplication` est idempotent.
        return redirect()->route('candidate.application.submitted', $application);
    }

    /**
     * Même convention que les sections : une visite Inertia repart sur un cycle
     * de navigation, un appel XHR simple attend un état à afficher.
     */
    private function attendDuJson(Request $request): bool
    {
        return $request->expectsJson() && ! $request->hasHeader('X-Inertia');
    }
}
