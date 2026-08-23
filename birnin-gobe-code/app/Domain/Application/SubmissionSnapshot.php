<?php

namespace App\Domain\Application;

use App\Domain\Eligibility\EligibilityAssessment;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;

/**
 * Ce qui a été officiellement déposé, figé à l'instant du dépôt.
 *
 * Le dossier reste lisible par ses relations — candidat, campagne, sections —
 * mais ces relations, elles, continuent de vivre : un candidat corrige son nom,
 * le comité arrête enfin la tranche d'âge, une édition change de dates. Rejouer
 * plus tard la lecture ne rendrait donc pas le dossier tel qu'il a été déposé,
 * mais tel qu'il serait aujourd'hui. Pour un dépôt officiel, contestable devant
 * un comité, ce n'est pas la même chose.
 *
 * D'où cette copie : autonome, écrite une fois, jamais relue à travers les
 * tables d'origine. Elle doit permettre de reconstituer le dépôt sans dépendre
 * d'aucune donnée modifiable après coup.
 *
 * Le verdict d'éligibilité en fait partie **parce qu'il est dérivé** : il se
 * recalcule à partir des paramètres de la campagne, qui peuvent changer. Le
 * figer ici, c'est garder trace de ce qui a été annoncé au candidat au moment
 * où il a déposé — sans pour autant en faire une décision d'admissibilité, qui
 * reste humaine et postérieure (§10.2).
 *
 * Ce que la copie ne contient pas : aucun mot de passe, aucun jeton, aucun
 * identifiant de session, aucune adresse IP. L'identité du déposant se limite à
 * ce qu'un accusé de dépôt porte — un nom et une adresse de contact.
 */
final readonly class SubmissionSnapshot
{
    /**
     * Version du format.
     *
     * Une copie destinée à être relue dans deux ans doit dire de quel format
     * elle est : le jour où le contenu changera, un lecteur devra pouvoir
     * distinguer un champ absent d'un champ jamais écrit.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function build(
        Application $application,
        EligibilityAssessment $eligibility,
        string $submissionNumber,
        string $submittedAt,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'submission_number' => $submissionNumber,
            'submitted_at' => $submittedAt,
            'application' => [
                'id' => $application->getKey(),
                'status' => ApplicationStatus::SUBMITTED->value,
            ],
            'candidate' => [
                'id' => $application->candidate?->getKey(),
                'name' => $application->candidate?->name,
                'email' => $application->candidate?->email,
            ],
            'campaign' => [
                'id' => $application->campaign?->getKey(),
                'code' => $application->campaign?->code,
                'name' => $application->campaign?->name,
                'status' => $application->campaign?->status->value,
                'timezone' => $application->campaign?->timezone,
                'opens_at' => $application->campaign?->opens_at?->toIso8601String(),
                'closes_at' => $application->campaign?->closes_at?->toIso8601String(),
            ],
            'sections' => self::sections($application),
            'eligibility' => $eligibility->toArray(),
        ];
    }

    /**
     * Les réponses, section par section, dans l'ordre du concours.
     *
     * Toutes les sections qui portent une ligne sont copiées, y compris celles
     * que le dépôt n'exigeait pas : ce qui a été écrit fait partie du dossier
     * déposé, même si la règle ne le réclamait pas.
     *
     * @return list<array<string, mixed>>
     */
    private static function sections(Application $application): array
    {
        $lignes = $application->sections()->get()->keyBy(
            static fn (ApplicationSectionAnswers $ligne): string => $ligne->section->value,
        );

        $copiees = [];

        foreach (ApplicationSection::cases() as $section) {
            $ligne = $lignes->get($section->value);

            if ($ligne === null) {
                continue;
            }

            $copiees[] = [
                'key' => $section->value,
                'label' => $section->label(),
                'position' => $section->position(),
                'completed_at' => $ligne->completed_at?->toIso8601String(),
                'answers' => is_array($ligne->answers) ? $ligne->answers : [],
            ];
        }

        return $copiees;
    }
}
