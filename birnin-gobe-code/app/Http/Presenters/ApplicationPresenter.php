<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationSection;
use App\Models\Application;
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
 */
final class ApplicationPresenter
{
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
     *     continueUrl: string|null
     * }
     */
    public function summary(Application $application): array
    {
        $courante = $application->current_step;

        return [
            'id' => $application->getKey(),
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'completionPercent' => (int) $application->completion_percent,
            'currentStep' => $courante === null ? null : [
                'key' => $courante->value,
                'label' => $courante->label(),
                'position' => $courante->position(),
            ],
            'updatedAt' => $application->updated_at?->toIso8601String(),
            'continueUrl' => $this->sectionUrl($application, $courante),
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
            ApplicationSection::CHALLENGE => route('candidate.application.challenge', $application),
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
