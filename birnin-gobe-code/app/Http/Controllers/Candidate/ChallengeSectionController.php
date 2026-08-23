<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Reference\NigerRegion;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveChallengeSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Défi » — étape 4 du formulaire.
 *
 * Toujours dédiée à une section plutôt que générique. L'arrivée d'« Éligibilité »
 * a permis de comparer les deux : ce qui se répétait était l'ossature HTTP —
 * quatre lignes — là où la validation, les champs et le contenu des props
 * diffèrent entièrement. Ce qui se répétait vraiment a été extrait côté React.
 * Voir ADR-007.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route,
 * et la barrière d'éligibilité par le middleware `eligible` : ce contrôleur ne
 * compare aucun identifiant et n'évalue aucune règle.
 */
final class ChallengeSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::CHALLENGE;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Challenge', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => [
                'key' => $section->value,
                'label' => $section->label(),
                'position' => $section->position(),
                'total' => ApplicationSection::total(),
                'completedAt' => $reponses?->completed_at?->toIso8601String(),
            ],
            // Les réponses absentes partent explicitement à `null` : le
            // formulaire React est contrôlé, il lui faut une valeur pour chaque
            // champ dès le premier rendu.
            'answers' => $this->reponses($reponses?->answers ?? []),
            'regions' => NigerRegion::options(),
            'maxLength' => ChallengeSection::MAX_LENGTH,
            'saveUrl' => route('candidate.application.challenge.update', $application),
            // Navigation arriere sans perte : l'etape 1 reste joignable, et
            // ses reponses viennent de la base au retour.
            'previousUrl' => $presenter->sectionUrl($application, ApplicationSection::ELIGIBILITY),
        ]);
    }

    public function update(
        SaveChallengeSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();

        $application = $save->handle(
            $application,
            ApplicationSection::CHALLENGE,
            $answers,
            ChallengeSection::isComplete($answers),
        );

        // La sauvegarde automatique appelle en XHR simple et attend un état à
        // afficher. Une visite Inertia (bouton « Enregistrer » sans JavaScript
        // asynchrone, rechargement) repart, elle, sur un cycle de navigation.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json([
                'savedAt' => $application->updated_at?->toIso8601String(),
                'application' => $presenter->summary($application),
                'steps' => $presenter->steps($application),
                'completed' => ChallengeSection::isComplete($answers),
            ]);
        }

        return back();
    }

    /**
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, string|null>
     */
    private function reponses(array $enregistrees): array
    {
        $answers = [];

        foreach (ChallengeSection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;
            $answers[$field] = $valeur === null ? null : (string) $valeur;
        }

        return $answers;
    }
}
