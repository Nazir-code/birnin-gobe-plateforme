<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;

/**
 * Met la candidature en forme pour les props Inertia.
 *
 * Partagé par le tableau de bord et l'écran de section pour que les deux
 * racontent la même chose : une progression calculée deux fois est une
 * progression qui finit par diverger.
 *
 * Les dates partent en ISO 8601 et les libellés de statut viennent des enums :
 * le formatage local est fait par le navigateur, dans la langue du candidat.
 *
 * Y vivent aussi les trois briques que les écrans de section partagent mot pour
 * mot — `section()`, `navigation()` et `savedPayload()`. Elles ont été remontées
 * ici à l'arrivée de la troisième section, et les sept écrans les réutilisent
 * telles quelles : ce sont les seules parties réellement identiques d'un écran à
 * l'autre. Les champs, la validation et les
 * règles métier restent dans leur section. Voir ADR-009.
 */
final class ApplicationPresenter
{
    public function __construct(private readonly ApplicationProgress $progress) {}

    /**
     * Les neuf étapes, avec l'état de chacune pour ce candidat.
     *
     * @return list<array{key: string, label: string, position: int, state: string, implemented: bool}>
     */
    public function steps(?Application $application): array
    {
        $achevees = $application === null
            ? []
            : $application->sections()
                ->whereNotNull('completed_at')
                ->pluck('section')
                ->map(static fn (ApplicationSection $section): string => $section->value)
                ->all();

        $courante = $application?->current_step;

        return array_map(
            static fn (ApplicationSection $section): array => [
                'key' => $section->value,
                'label' => $section->label(),
                'position' => $section->position(),
                'state' => match (true) {
                    in_array($section->value, $achevees, strict: true) => 'done',
                    $section === $courante => 'active',
                    default => 'pending',
                },
                'implemented' => $section->isImplemented(),
                // Distingue « développée » de « atteignable sans sauter
                // d'étape » : l'écran doit pouvoir le signaler au candidat.
                'onOpenPath' => $section->isOnOpenPath(),
            ],
            ApplicationSection::cases(),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     statusLabel: string,
     *     completionPercent: int,
     *     currentStep: array{key: string, label: string, position: int}|null,
     *     updatedAt: string|null,
     *     submissionNumber: string|null,
     *     submittedAt: string|null,
     *     continueUrl: string|null,
     *     reviewUrl: string,
     *     submittedUrl: string|null
     * }
     */
    public function summary(Application $application): array
    {
        $courante = $application->current_step;
        $depose = ! $application->isDraft();

        return [
            'id' => $application->getKey(),
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            // Recalculé, jamais lu depuis la colonne : ouvrir une étape change
            // la règle du parcours pour tous les dossiers, y compris ceux que
            // personne n'a rouverts depuis. Voir ApplicationProgress.
            'completionPercent' => $this->progress->percent($application),
            'currentStep' => $courante === null ? null : [
                'key' => $courante->value,
                'label' => $courante->label(),
                'position' => $courante->position(),
            ],
            'updatedAt' => $application->updated_at?->toIso8601String(),
            'submissionNumber' => $application->submission_number,
            'submittedAt' => $application->submitted_at?->toIso8601String(),
            // Un dossier déposé ne se reprend pas : la policy refuserait
            // l'écriture, et proposer « Continuer » mènerait à un formulaire en
            // lecture seule sans le dire. L'écran offre l'accusé à la place.
            'continueUrl' => $depose ? null : $this->sectionUrl($application, $courante),
            'reviewUrl' => route('candidate.application.review', $application),
            'submittedUrl' => $depose ? route('candidate.application.submitted', $application) : null,
        ];
    }

    /**
     * Entête d'un écran de section : ce que tous les écrans affichent à
     * l'identique, au-dessus du formulaire.
     *
     * @return array{key: string, label: string, position: int, total: int, completedAt: string|null}
     */
    public function section(ApplicationSection $section, ?ApplicationSectionAnswers $reponses): array
    {
        return [
            'key' => $section->value,
            'label' => $section->label(),
            'position' => $section->position(),
            'total' => ApplicationSection::total(),
            'completedAt' => $reponses?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Boutons « Précédent » et « Suivant » d'une section.
     *
     * Calculés au même endroit pour tous les écrans : c'est la seule façon
     * d'être sûr que le parcours annoncé au candidat est le même partout.
     * `nextUrl` vaut `null` quand le parcours s'arrête — l'écran le dit alors,
     * plutôt que de proposer un lien vers une étape qui n'existe pas.
     *
     * @return array{previousUrl: string|null, nextUrl: string|null}
     */
    public function navigation(Application $application, ApplicationSection $section): array
    {
        $precedente = $section->previousImplemented();
        $suivante = $section->nextOnOpenPath();

        return [
            'previousUrl' => $precedente === null ? null : $this->sectionUrl($application, $precedente),
            'nextUrl' => $suivante === null ? null : $this->sectionUrl($application, $suivante),
        ];
    }

    /**
     * Corps de réponse d'une sauvegarde automatique.
     *
     * Identique pour toutes les sections : l'horodatage confirmé par le serveur,
     * l'état du dossier et l'avancement recalculé. Une section qui a davantage
     * à dire — l'éligibilité y joint son verdict — complète ce tableau.
     *
     * @return array{savedAt: string|null, application: array<string, mixed>, steps: array<int, mixed>, completed: bool}
     */
    public function savedPayload(Application $application, bool $completed): array
    {
        return [
            'savedAt' => $application->updated_at?->toIso8601String(),
            'application' => $this->summary($application),
            'steps' => $this->steps($application),
            'completed' => $completed,
        ];
    }

    /**
     * @return array{name: string, code: string, closesAt: string|null, daysLeft: int|null}
     */
    public function campaign(Campaign $campaign): array
    {
        return [
            'name' => $campaign->name,
            'code' => $campaign->code,
            'closesAt' => $campaign->closes_at?->toIso8601String(),
            'daysLeft' => $this->joursRestants($campaign),
        ];
    }

    /**
     * URL de reprise.
     *
     * Retombe sur la première section ouverte quand `current_step` désigne une
     * section pas encore développée : le candidat doit atterrir sur un écran qui
     * existe, pas sur un 404.
     */
    public function sectionUrl(Application $application, ?ApplicationSection $section): ?string
    {
        $cible = $section?->isImplemented() === true ? $section : ApplicationSection::firstImplemented();

        return match ($cible) {
            ApplicationSection::ELIGIBILITY => route('candidate.application.eligibility', $application),
            ApplicationSection::PROFILE => route('candidate.application.profile', $application),
            ApplicationSection::TEAM => route('candidate.application.team', $application),
            ApplicationSection::CHALLENGE => route('candidate.application.challenge', $application),
            ApplicationSection::SOLUTION => route('candidate.application.solution', $application),
            ApplicationSection::IMPACT => route('candidate.application.impact', $application),
            ApplicationSection::IMPLEMENTATION => route('candidate.application.implementation', $application),
            default => null,
        };
    }

    /**
     * Jours restants, calculés à partir d'horodatages plutôt que d'une
     * différence de calendrier : la valeur affichée ne doit pas dépendre du
     * fuseau du serveur.
     */
    private function joursRestants(Campaign $campaign): ?int
    {
        if ($campaign->closes_at === null) {
            return null;
        }

        $secondes = $campaign->closes_at->getTimestamp() - now()->getTimestamp();

        return $secondes > 0 ? (int) ceil($secondes / 86400) : 0;
    }
}
