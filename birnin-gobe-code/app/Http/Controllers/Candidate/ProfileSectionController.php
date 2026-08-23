<?php

namespace App\Http\Controllers\Candidate;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\SaveApplicationSection;
use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\Gender;
use App\Domain\Candidate\PreferredChannel;
use App\Domain\Reference\NigerRegion;
use App\Http\Presenters\ApplicationPresenter;
use App\Http\Requests\Candidate\SaveProfileSectionRequest;
use App\Models\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Section « Profil du candidat » — étape 2 du formulaire.
 *
 * Comme les deux autres sections, ce contrôleur reste dédié : ses champs, sa
 * validation et sa notion de complétude lui appartiennent. Ce qui était
 * réellement identique d'un écran à l'autre a été remonté dans
 * `ApplicationPresenter` (entête de section, navigation, corps de réponse de
 * sauvegarde) — voir ADR-009.
 *
 * L'écran affiche aussi des données qu'il ne détient pas : le nom et l'adresse
 * du compte, la date de naissance et la nationalité déclarées à l'étape 1.
 * Elles partent en lecture seule, avec le lien vers l'étape qui les détient.
 * Rien n'est recopié : une même information n'a qu'une source.
 *
 * L'accès à `$application` est autorisé par la policy déclarée sur la route, et
 * la barrière d'éligibilité par le middleware `eligible`.
 */
final class ProfileSectionController
{
    public function edit(Application $application, ApplicationPresenter $presenter): Response
    {
        $section = ApplicationSection::PROFILE;
        $reponses = $application->sectionAnswers($section);

        return Inertia::render('Candidate/Application/Profile', [
            'application' => $presenter->summary($application),
            'steps' => $presenter->steps($application),
            'section' => $presenter->section($section, $reponses),
            'answers' => $this->reponses($reponses?->answers ?? []),
            'known' => $this->donneesDejaConnues($application),
            'regions' => NigerRegion::options(),
            'genders' => Gender::options(),
            'channels' => PreferredChannel::options(),
            'educationLevels' => EducationLevel::options(),
            'requiredFields' => ProfileSection::REQUIRED_FIELDS,
            'shortTextMax' => ProfileSection::SHORT_TEXT_MAX,
            'longTextMax' => ProfileSection::LONG_TEXT_MAX,
            'saveUrl' => route('candidate.application.profile.update', $application),
            ...$presenter->navigation($application, $section),
        ]);
    }

    public function update(
        SaveProfileSectionRequest $request,
        Application $application,
        SaveApplicationSection $save,
        ApplicationPresenter $presenter,
    ): JsonResponse|RedirectResponse {
        $answers = $request->answers();

        $application = $save->handle(
            $application,
            ApplicationSection::PROFILE,
            $answers,
            ProfileSection::isComplete($answers),
        );

        // La sauvegarde automatique appelle en XHR simple et attend un état à
        // afficher. Une visite Inertia (bouton « Enregistrer », rechargement)
        // repart, elle, sur un cycle de navigation.
        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(
                $presenter->savedPayload($application, ProfileSection::isComplete($answers)),
            );
        }

        return back();
    }

    /**
     * Ce que le dossier sait déjà du candidat, et que cette section ne
     * redemande donc pas.
     *
     * Affiché pour que le candidat vérifie — et sache où corriger — plutôt que
     * de se demander pourquoi on ne lui demande pas sa date de naissance.
     *
     * @return array<string, mixed>
     */
    private function donneesDejaConnues(Application $application): array
    {
        $candidat = $application->candidate;
        $eligibilite = $application->sectionAnswers(ApplicationSection::ELIGIBILITY)?->answers ?? [];

        $nationalite = $eligibilite[EligibilitySection::NIGERIEN_NATIONAL] ?? null;

        return [
            'accountName' => $candidat?->name,
            'accountEmail' => $candidat?->email,
            'birthDate' => $eligibilite[EligibilitySection::BIRTH_DATE] ?? null,
            'nigerienNational' => is_bool($nationalite) ? $nationalite : null,
            'eligibilityUrl' => route('candidate.application.eligibility', $application),
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

        foreach (ProfileSection::fields() as $field) {
            $valeur = $enregistrees[$field] ?? null;
            $answers[$field] = $valeur === null ? '' : (string) $valeur;
        }

        return $answers;
    }
}
