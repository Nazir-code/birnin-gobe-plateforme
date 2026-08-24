<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\MaturityStage;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Application\SolutionSection;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveSolutionSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Solution » — étape 5 du formulaire.
 *
 * Dédiée à sa section, comme les quatre précédentes : la validation, les champs
 * et la complétude lui appartiennent, et ce qui se répétait réellement d'un
 * écran à l'autre vit dans `ApplicationPresenter` (ADR-009).
 *
 * L'écran rappelle le défi décrit à l'étape 4, en lecture seule : la solution
 * répond à ce défi, et le candidat doit l'avoir sous les yeux pour l'écrire.
 * Rien n'est recopié — une même information n'a qu'une source.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route, et
 * la barrière d'éligibilité par le middleware `eligible`.
 */
final class SolutionSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::SOLUTION;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Solution', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $this->reponses($reponses?->answers ?? []),
            'known' => $this->defiRappele($application),
            'maturityStages' => MaturityStage::options(),
            'requiredFields' => SolutionSection::REQUIRED_FIELDS,
            'shortTextMax' => SolutionSection::SHORT_TEXT_MAX,
            'longTextMax' => SolutionSection::LONG_TEXT_MAX,
            'saveUrl' => route('candidate.application.solution.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveSolutionSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();
        $complete = SolutionSection::isComplete($answers);

        $application = $save->handle($application, ApplicationSection::SOLUTION, $answers, $complete);

        // La sauvegarde automatique appelle en XHR simple et attend un état à
        // afficher. Une visite Inertia (bouton « Enregistrer », rechargement)
        // repart, elle, sur un cycle de navigation.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json($presenter->savedPayload($application, $complete));
        }

        return back();
    }

    /**
     * Le défi de l'étape 4, rappelé sans être redemandé.
     *
     * @return array{mainChallenge: string|null, challengeUrl: string}
     */
    private function defiRappele(Application $application): array
    {
        $defi = $application->sectionAnswers(ApplicationSection::CHALLENGE)?->answers ?? [];
        $principal = $defi[ChallengeSection::TEXT_FIELDS[0]] ?? null;

        return [
            'mainChallenge' => is_string($principal) && trim($principal) !== '' ? $principal : null,
            'challengeUrl' => route('candidate.application.challenge', $application),
        ];
    }

    /**
     * Réponses mises en forme pour un formulaire React contrôlé : tout part en
     * chaîne, `null` compris, pour que chaque champ ait une valeur dès le
     * premier rendu.
     *
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, string>
     */
    private function reponses(array $enregistrees): array
    {
        $answers = [];

        foreach (SolutionSection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;
            $answers[$field] = $valeur === null ? '' : (string) $valeur;
        }

        return $answers;
    }
}
