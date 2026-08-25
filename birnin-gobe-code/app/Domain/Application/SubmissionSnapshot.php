<?php

namespace App\Domain\Application;

use App\Domain\Eligibility\EligibilityAssessment;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Attachment;

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
 *
 * Elle ne contient pas davantage le **contenu** des pièces jointes. Recopier
 * des mégaoctets de PDF dans une colonne `jsonb` rendrait chaque lecture de
 * dossier — et chaque sauvegarde de la base — proportionnelle au poids des
 * fichiers, pour reproduire ce que le disque des pièces conserve déjà. Ce que
 * la copie retient d'une pièce, c'est de quoi l'**identifier** : sa nature, son
 * nom d'origine, son poids et son empreinte. L'empreinte est ce qui compte le
 * jour d'une contestation — elle seule dit si le fichier lu aujourd'hui est
 * bien celui qui a été déposé, ce qu'aucun nom de fichier ne prouve.
 *
 * `storage_key` en est volontairement absent : l'emplacement d'un fichier est
 * une donnée d'exploitation qui peut changer — un déménagement vers S3 la
 * réécrirait — et une copie figée ne doit rien contenir qui puisse devenir faux.
 */
final readonly class SubmissionSnapshot
{
    /**
     * Version du format.
     *
     * Une copie destinée à être relue dans deux ans doit dire de quel format
     * elle est : le jour où le contenu changera, un lecteur devra pouvoir
     * distinguer un champ absent d'un champ jamais écrit.
     *
     * Version 2 : ajout de `documents`, à l'ouverture de l'étape 8. Une copie en
     * version 1 n'a pas de pièces parce que la plateforme n'en recevait pas
     * encore — ce qui n'est pas la même chose qu'un dépôt sans pièce jointe, et
     * c'est exactement la distinction que ce numéro permet de faire.
     */
    public const SCHEMA_VERSION = 2;

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
            'documents' => self::documents($application),
            'eligibility' => $eligibility->toArray(),
        ];
    }

    /**
     * Les pièces officielles du dossier, telles qu'elles étaient au dépôt.
     *
     * Même principe que les sections : toutes les pièces présentes sont
     * copiées, y compris celles que le dépôt n'exigeait pas. Ce que le candidat
     * a joint fait partie de ce qu'il a déposé.
     *
     * L'ordre est celui du §7.2, et non celui des téléversements : une copie se
     * relit à côté du tableau du cahier des charges, pas à côté du journal
     * d'activité du candidat.
     *
     * @return list<array<string, mixed>>
     */
    private static function documents(Application $application): array
    {
        $deposees = $application->attachments()
            ->whereNotNull('type')
            ->get()
            ->keyBy(static fn (Attachment $piece): string => $piece->type->value);

        $copiees = [];

        foreach (DocumentType::cases() as $type) {
            $piece = $deposees->get($type->value);

            if ($piece === null) {
                continue;
            }

            $copiees[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'filename' => $piece->original_filename,
                'mime_type' => $piece->mime_type,
                'size' => (int) $piece->size,
                // Ce qui permet, plus tard, d'affirmer que le fichier servi est
                // bien celui qui a été déposé.
                'checksum' => $piece->checksum,
                'uploaded_at' => $piece->created_at?->toIso8601String(),
            ];
        }

        return $copiees;
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
