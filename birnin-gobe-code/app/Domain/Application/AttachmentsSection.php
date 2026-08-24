<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;

/**
 * Définition de la section « Pièces / déclarations » — étape 8.
 *
 * Deux contenus distincts, réunis par le §5.2 étape 8 (« Téléversements,
 * conformité, propriété intellectuelle, exactitude, consentement et absence de
 * fraude ») :
 *
 *   les **pièces**, décrites par `DocumentType` d'après le §7.2. Elles ne
 *     vivent pas dans `application_sections` : un fichier a une taille, un type
 *     MIME et un emplacement de stockage, et n'a rien à faire dans un `jsonb` de
 *     réponses. Voir le modèle `Attachment` ;
 *   les **déclarations**, ci-dessous, qui sont des réponses comme les autres et
 *     sont donc persistées avec les autres.
 *
 * Les six déclarations sont les trois puces du §7.3, éclatées là où la source
 * elle-même les distingue :
 *
 *   « Exactitude des renseignements et acceptation d'un contrôle ; absence de
 *     contenu frauduleux, plagié ou illicite ; autorisation de représentation de
 *     l'équipe. »          → ACCURACY, NO_FRAUD, TEAM_REPRESENTATION
 *   « Reconnaissance du règlement, de la grille de sélection, des règles de
 *     propriété intellectuelle, de confidentialité et de publication des
 *     résultats. »          → RULES_ACKNOWLEDGEMENT
 *   « Consentement **distinct** pour les données nécessaires à la candidature
 *     et, **le cas échéant**, pour la communication publique ou les actualités
 *     futures. »            → DATA_PROCESSING_CONSENT, PUBLIC_COMMUNICATION_CONSENT
 *
 * Deux nuances de la source sont tenues, et elles ne sont pas décoratives :
 *
 *   le consentement à la communication publique est **facultatif**. Le §7.3 le
 *     sépare explicitement (« distinct », « le cas échéant ») du consentement
 *     nécessaire à la candidature. Un consentement que l'on doit donner pour
 *     pouvoir déposer n'est pas un consentement — l'exiger le viderait de sa
 *     valeur juridique en même temps que de son sens ;
 *   l'autorisation de représentation n'est demandée qu'aux candidatures
 *     collectives : il n'y a personne à représenter quand on candidate seul, et
 *     la règle qui le dit est déjà celle de l'étape 3.
 *
 * Une case cochée dans le navigateur ne prouve rien : ce qui fait foi est la
 * valeur booléenne validée par la `FormRequest` puis relue ici.
 */
final class AttachmentsSection
{
    public const SECTION = ApplicationSection::ATTACHMENTS;

    /** Exactitude des renseignements et acceptation d'un contrôle (§7.3). */
    public const ACCURACY = 'accuracy_and_control';

    /** Absence de contenu frauduleux, plagié ou illicite (§7.3). */
    public const NO_FRAUD = 'no_fraud_or_plagiarism';

    /** Autorisation de représentation de l'équipe (§7.3). Collectif seulement. */
    public const TEAM_REPRESENTATION = 'team_representation';

    /** Règlement, grille, propriété intellectuelle, confidentialité, publication (§7.3). */
    public const RULES_ACKNOWLEDGEMENT = 'rules_acknowledgement';

    /** Consentement au traitement des données nécessaires à la candidature (§7.3). */
    public const DATA_PROCESSING_CONSENT = 'data_processing_consent';

    /** Consentement à la communication publique — « le cas échéant » (§7.3). */
    public const PUBLIC_COMMUNICATION_CONSENT = 'public_communication_consent';

    /**
     * Toutes les déclarations, dans l'ordre du §7.3.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            self::ACCURACY,
            self::NO_FRAUD,
            self::TEAM_REPRESENTATION,
            self::RULES_ACKNOWLEDGEMENT,
            self::DATA_PROCESSING_CONSENT,
            self::PUBLIC_COMMUNICATION_CONSENT,
        ];
    }

    /**
     * Déclarations qui doivent valoir `true` pour ce type de candidature.
     *
     * @return list<string>
     */
    public static function requiredFor(?CandidateType $type): array
    {
        $exigees = [
            self::ACCURACY,
            self::NO_FRAUD,
            self::RULES_ACKNOWLEDGEMENT,
            self::DATA_PROCESSING_CONSENT,
        ];

        if (TeamSection::attendDesMembres($type)) {
            $exigees[] = self::TEAM_REPRESENTATION;
        }

        return $exigees;
    }

    public static function label(string $declaration): string
    {
        return match ($declaration) {
            self::ACCURACY => 'exactitude des renseignements',
            self::NO_FRAUD => 'absence de contenu frauduleux ou plagié',
            self::TEAM_REPRESENTATION => 'autorisation de représenter l’équipe',
            self::RULES_ACKNOWLEDGEMENT => 'reconnaissance du règlement',
            self::DATA_PROCESSING_CONSENT => 'consentement au traitement des données',
            self::PUBLIC_COMMUNICATION_CONSENT => 'consentement à la communication publique',
            default => $declaration,
        };
    }

    /**
     * Règles appliquées à une sauvegarde de brouillon.
     *
     * `nullable|boolean` partout, comme les sept sections précédentes : un
     * brouillon incomplet doit pouvoir être enregistré, et une déclaration pas
     * encore lue n'est pas une déclaration refusée. Le caractère obligatoire est
     * porté par `isComplete()`, qui décide de `completed_at` — et c'est cette
     * date que `SubmissionReadiness` exige pour autoriser le dépôt.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (self::fields() as $field) {
            $rules[$field] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * La section est faite quand les pièces exigées sont là **et** que les
     * déclarations exigées sont acceptées.
     *
     * Les deux conditions, pas l'une ou l'autre : un dossier sans présentation
     * de projet n'est pas un dossier, et un dossier dont le candidat n'atteste
     * rien n'est pas déposable. C'est cette méthode qui fait passer
     * `SubmissionReadiness` de « pas prêt » à « prêt », par le seul chemin
     * qu'elle connaisse — `completed_at`.
     *
     * @param  array<string, mixed>  $declarations
     * @param  list<DocumentType>  $piecesDeposees
     */
    public static function isComplete(array $declarations, array $piecesDeposees, ?CandidateType $type): bool
    {
        foreach (self::requiredFor($type) as $declaration) {
            if (($declarations[$declaration] ?? null) !== true) {
                return false;
            }
        }

        foreach (DocumentType::requiredFor($type) as $piece) {
            if (! in_array($piece, $piecesDeposees, strict: true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ce qu'il reste à faire, nommé — c'est le « résumé des erreurs par étape »
     * du §5.3, et le §8.1 en fait une exigence du contrôle avant soumission.
     *
     * @param  array<string, mixed>  $declarations
     * @param  list<DocumentType>  $piecesDeposees
     * @return array{documents: list<string>, declarations: list<string>}
     */
    public static function missing(array $declarations, array $piecesDeposees, ?CandidateType $type): array
    {
        $piecesManquantes = array_values(array_filter(
            DocumentType::requiredFor($type),
            static fn (DocumentType $piece): bool => ! in_array($piece, $piecesDeposees, strict: true),
        ));

        $declarationsManquantes = array_values(array_filter(
            self::requiredFor($type),
            static fn (string $declaration): bool => ($declarations[$declaration] ?? null) !== true,
        ));

        return [
            'documents' => array_map(
                static fn (DocumentType $piece): string => $piece->value,
                $piecesManquantes,
            ),
            'declarations' => $declarationsManquantes,
        ];
    }
}
