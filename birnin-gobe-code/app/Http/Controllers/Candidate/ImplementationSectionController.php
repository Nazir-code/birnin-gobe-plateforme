<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ImplementationSection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Application\TeamSection;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveImplementationSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Plan de mise en œuvre » — étape 7 du formulaire.
 *
 * Dernière étape du parcours ouvert à ce jour : « Pièces / déclarations »
 * (étape 8) n'est pas développée, et `nextOnOpenPath()` renvoie donc `null` —
 * l'écran le dit au candidat plutôt que de proposer un lien vers une étape qui
 * n'existe pas.
 *
 * L'écran rappelle l'effectif décrit à l'étape 3 : le plan s'appuie sur cette
 * équipe, et le §7.1 la range parmi les moyens. Elle n'est pas redemandée.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route, et
 * la barrière d'éligibilité par le middleware `eligible`.
 */
final class ImplementationSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::IMPLEMENTATION;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Implementation', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $this->reponses($reponses?->answers ?? []),
            'known' => $this->equipeRappelee($application),
            'requiredFields' => ImplementationSection::REQUIRED_FIELDS,
            'numericFields' => ImplementationSection::NUMERIC_FIELDS,
            'longTextMax' => ImplementationSection::LONG_TEXT_MAX,
            'durationMin' => ImplementationSection::DURATION_MIN,
            'durationMax' => ImplementationSection::DURATION_MAX,
            'budgetCeiling' => ImplementationSection::BUDGET_CEILING,
            'saveUrl' => route('candidate.application.implementation.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveImplementationSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();
        $complete = ImplementationSection::isComplete($answers);

        $application = $save->handle($application, ApplicationSection::IMPLEMENTATION, $answers, $complete);

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json($presenter->savedPayload($application, $complete));
        }

        return back();
    }

    /**
     * L'équipe de l'étape 3, rappelée sans être redemandée.
     *
     * L'effectif inclut le porteur principal, qui ne figure pas dans la liste
     * des membres : une candidature individuelle compte donc une personne, pas
     * zéro. C'est la même règle qu'à l'étape 3, appelée et non recopiée.
     *
     * @return array{teamSize: int, teamUrl: string}
     */
    private function equipeRappelee(Application $application): array
    {
        $equipe = $application->sectionAnswers(ApplicationSection::TEAM)?->answers ?? [];
        $membres = $equipe[TeamSection::MEMBERS] ?? [];

        return [
            'teamSize' => TeamSection::effectifDecrit(is_array($membres) ? $membres : []),
            'teamUrl' => route('candidate.application.team', $application),
        ];
    }

    /**
     * Réponses mises en forme pour un formulaire React contrôlé.
     *
     * Les deux champs numériques repartent eux aussi en chaîne : un `<input>`
     * contrôlé n'accepte rien d'autre. Ils sont stockés comme entiers, et c'est
     * la seule forme qui compte — celle-ci n'est qu'un affichage.
     *
     * @param  array<string, mixed>  $enregistrees
     * @return array<string, string>
     */
    private function reponses(array $enregistrees): array
    {
        $answers = [];

        foreach (ImplementationSection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;
            $answers[$field] = $valeur === null ? '' : (string) $valeur;
        }

        return $answers;
    }
}
