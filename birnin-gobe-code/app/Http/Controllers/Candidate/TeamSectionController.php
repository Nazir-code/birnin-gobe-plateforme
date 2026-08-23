<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Application\TeamSection;
use App\Domain\Application\TeamSectionAssessment;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveTeamSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Structure / équipe » — étape 3 du formulaire.
 *
 * Première section dont le contenu dépend d'une autre : le type de candidature
 * décidé à l'étape 1 détermine ce qui est demandé (§6.2). Le contrôleur ne
 * décide rien de tout cela — il transmet le verdict de `TeamSectionAssessment`,
 * qui est aussi ce qui fixe `completed_at`. Un seul calcul, trois usages.
 *
 * Le porteur principal n'est pas un membre de la liste : son identité vit dans
 * le compte et dans la section « Profil ». Il est présenté à part, comme
 * représentant de l'équipe (§6.2, « un seul représentant actif »).
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route, et
 * la barrière d'éligibilité par le middleware `eligible`.
 */
final class TeamSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::TEAM;
        $reponses = $application->sectionAnswers($section);
        $verdict = TeamSectionAssessment::forApplication($application);
        $answers = $reponses?->answers ?? [];

        return Inertia::render('Candidate/Application/Team', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'structure' => $this->structure($answers),
            'members' => $this->membres($answers),
            'assessment' => $verdict->toArray(),
            'representative' => [
                'name' => $application->candidate?->name,
                'email' => $application->candidate?->email,
            ],
            'eligibilityUrl' => route('candidate.application.eligibility', $application),
            'limits' => [
                'shortTextMax' => TeamSection::SHORT_TEXT_MAX,
                'longTextMax' => TeamSection::LONG_TEXT_MAX,
                'membersCeiling' => TeamSection::MEMBERS_CEILING,
                'foundedYearFloor' => TeamSection::FOUNDED_YEAR_FLOOR,
                'foundedYearCeiling' => (int) date('Y'),
            ],
            'saveUrl' => route('candidate.application.team.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveTeamSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();

        // La complétude se juge sur ce qui vient d'être validé, croisé avec
        // l'étape 1 : le type et l'effectif annoncé n'appartiennent pas à cette
        // section et ne sont donc pas dans `$answers`.
        $eligibilite = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];
        $verdict = TeamSectionAssessment::evaluer($answers, $eligibilite);

        $application = $save->handle($application, ApplicationSection::TEAM, $answers, $verdict->complete);

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json([
                ...$presenter->savedPayload($application, $verdict->complete),
                // Recalculé sur l'état réel : l'écran dit ce qui manque encore
                // sans avoir à le déduire lui-même.
                'assessment' => $verdict->toArray(),
            ]);
        }

        return back();
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, string>
     */
    private function structure(array $answers): array
    {
        $structure = [];

        foreach (TeamSection::structureFields() as $champ) {
            $valeur = $answers[$champ] ?? null;
            $structure[$champ] = $valeur === null ? '' : (string) $valeur;
        }

        return $structure;
    }

    /**
     * Membres mis en forme pour un formulaire React contrôlé : chaînes partout
     * sauf les deux booléens, pour que chaque champ ait une valeur dès le
     * premier rendu.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function membres(array $answers): array
    {
        $bruts = is_array($answers[TeamSection::MEMBERS] ?? null) ? $answers[TeamSection::MEMBERS] : [];
        $membres = [];

        foreach ($bruts as $brut) {
            if (! is_array($brut)) {
                continue;
            }

            $membre = [];

            foreach (TeamSection::memberFields() as $champ) {
                $valeur = $brut[$champ] ?? null;

                $membre[$champ] = match (true) {
                    in_array($champ, [TeamSection::MEMBER_IS_FOUNDER, TeamSection::MEMBER_CONSENT], strict: true) => (bool) $valeur,
                    $valeur === null => '',
                    default => (string) $valeur,
                };
            }

            $membres[] = $membre;
        }

        return $membres;
    }
}
