<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveEligibilitySectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Éligibilité guidée » — étape 1 du formulaire.
 *
 * Même forme que `ChallengeSectionController`, et volontairement pas encore
 * factorisée avec lui : voir ADR-007, qui compare les deux et explique ce qui
 * diffère réellement.
 *
 * Le verdict d'éligibilité accompagne chaque réponse — au chargement comme
 * après chaque sauvegarde — parce qu'il change avec les réponses. Il est
 * recalculé, jamais lu depuis la requête.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route :
 * ce contrôleur ne compare aucun identifiant.
 */
final class EligibilitySectionController
{
    public function edit(
        Application $application,
        ApplicationPresenter $presenter,
        EvaluateEligibility $evaluer,
    ): Response {
        $section = ApplicationSection::ELIGIBILITY;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Eligibility', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $this->reponses($reponses?->answers ?? []),
            'regions' => NigerRegion::options(),
            'candidateTypes' => CandidateType::options(),
            'eligibility' => $evaluer->forApplication($application)->toArray(),
            'saveUrl' => route('candidate.application.eligibility.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveEligibilitySectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
        EvaluateEligibility $evaluer,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();

        $application = $save->handle(
            $application,
            ApplicationSection::ELIGIBILITY,
            $answers,
            EligibilitySection::isComplete($answers),
        );

        // La sauvegarde automatique appelle en XHR simple et attend un état à
        // afficher. Une visite Inertia (bouton « Enregistrer », rechargement)
        // repart, elle, sur un cycle de navigation.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json([
                ...$presenter->savedPayload($application, EligibilitySection::isComplete($answers)),
                // Recalculé à partir de ce qui vient d'être écrit : le verdict
                // affiché correspond toujours à l'état réel de la base.
                'eligibility' => $evaluer->forApplication($application)->toArray(),
            ]);
        }

        return back();
    }

    /**
     * Réponses mises en forme pour un formulaire React contrôlé.
     *
     * Tout part en chaîne, y compris les booléens (`'1'` / `'0'`) et
     * l'effectif : un champ de formulaire manipule des chaînes, et le retypage
     * a déjà lieu une fois pour toutes côté serveur, dans la `FormRequest`.
     *
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, string>
     */
    private function reponses(array $enregistrees): array
    {
        $answers = [];

        foreach (EligibilitySection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;

            $answers[$field] = match (true) {
                $valeur === null => '',
                is_bool($valeur) => $valeur ? '1' : '0',
                default => (string) $valeur,
            };
        }

        return $answers;
    }
}
