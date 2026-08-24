<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ImpactSection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Application\SolutionSection;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveImpactSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Impact / viabilité » — étape 6 du formulaire.
 *
 * Cette étape recueille ce que le candidat **déclare** de son projet : qui en
 * bénéficiera, ce qu'il en attend, comment il compte le mesurer et le faire
 * durer. Rien n'y est apprécié, comparé ni chiffré par la plateforme — pas de
 * score, pas de note, pas de pondération, pas de classement. L'évaluation est un
 * autre contexte borné, avec ses propres écrans et ses propres droits.
 *
 * L'écran rappelle le nom de la solution décrite à l'étape 5, en lecture seule.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route, et
 * la barrière d'éligibilité par le middleware `eligible`.
 */
final class ImpactSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::IMPACT;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Impact', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $this->reponses($reponses?->answers ?? []),
            'known' => $this->solutionRappelee($application),
            'requiredFields' => ImpactSection::REQUIRED_FIELDS,
            'longTextMax' => ImpactSection::LONG_TEXT_MAX,
            'saveUrl' => route('candidate.application.impact.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveImpactSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();
        $complete = ImpactSection::isComplete($answers);

        $application = $save->handle($application, ApplicationSection::IMPACT, $answers, $complete);

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json($presenter->savedPayload($application, $complete));
        }

        return back();
    }

    /**
     * La solution de l'étape 5, rappelée sans être redemandée.
     *
     * @return array{solutionName: string|null, solutionUrl: string}
     */
    private function solutionRappelee(Application $application): array
    {
        $solution = $application->sectionAnswers(ApplicationSection::SOLUTION)?->answers ?? [];
        $nom = $solution[SolutionSection::SOLUTION_NAME] ?? null;

        return [
            'solutionName' => is_string($nom) && trim($nom) !== '' ? $nom : null,
            'solutionUrl' => route('candidate.application.solution', $application),
        ];
    }

    /**
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, string>
     */
    private function reponses(array $enregistrees): array
    {
        $answers = [];

        foreach (ImpactSection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;
            $answers[$field] = $valeur === null ? '' : (string) $valeur;
        }

        return $answers;
    }
}
