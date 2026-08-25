<?php

namespace App\Domain\Application;

use App\Domain\Candidate\CandidateType;

/**
 * Les pièces jointes du concours — §7.2 « Pièces jointes paramétrables ».
 *
 * Un cas par ligne du tableau du cahier des charges, dans son ordre, et rien de
 * plus. Le caractère de chaque pièce est repris tel quel :
 *
 *   Présentation du projet        « Obligatoire »
 *   CV des membres clés           « Obligatoire selon type »
 *   Identité / existence légale   « Conditionnel »
 *   Budget et plan d'action       « Configurable »
 *   Prototype / démonstration     « Recommandé / obligatoire selon phase »
 *   Lettres et autorisations      « Conditionnel »
 *
 * Trois de ces mentions désignent une décision qui n'appartient pas au code :
 * « configurable » et « selon phase » attendent l'écran d'administration des
 * pièces (§9.2), et la condition des lettres dépend de cas — partenariat,
 * représentation, usage de données — qu'aucune donnée du dossier ne permet
 * aujourd'hui de trancher. Ces trois-là sont donc **acceptées sans être
 * exigées** : rendre obligatoire ce que la source laisse ouvert fermerait le
 * dépôt à des candidats que le règlement autorise.
 *
 * Les deux conditions réellement décidables le sont à partir du type de
 * candidature déclaré à l'étape 1, et par les règles qui existent déjà :
 * `TeamSection::attendDesMembres()` pour les CV, `attendUneStructure()` pour
 * l'existence légale. La règle est appelée, jamais recopiée — c'est ce qui
 * garantit qu'on n'exige pas de RCCM d'un porteur individuel.
 *
 * **Aucune analyse antivirus n'est faite à ce stade.** La colonne `scan_status`
 * des pièces existe pour l'accueillir, et rien dans cette classe ne prétend
 * qu'un fichier est sain.
 */
enum DocumentType: string
{
    case PROJECT_PRESENTATION = 'PROJECT_PRESENTATION';
    case KEY_MEMBER_CV = 'KEY_MEMBER_CV';
    case LEGAL_EXISTENCE = 'LEGAL_EXISTENCE';
    case BUDGET_PLAN = 'BUDGET_PLAN';
    case PROTOTYPE_DEMO = 'PROTOTYPE_DEMO';
    case LETTERS_AUTHORISATIONS = 'LETTERS_AUTHORISATIONS';

    public function label(): string
    {
        return match ($this) {
            self::PROJECT_PRESENTATION => 'Présentation du projet',
            self::KEY_MEMBER_CV => 'CV des membres clés',
            self::LEGAL_EXISTENCE => 'Identité / existence légale',
            self::BUDGET_PLAN => 'Budget et plan d’action',
            self::PROTOTYPE_DEMO => 'Prototype / démonstration',
            self::LETTERS_AUTHORISATIONS => 'Lettres et autorisations',
        };
    }

    /** Ce que la pièce doit contenir, dit au candidat dans ses mots. */
    public function help(): string
    {
        return match ($this) {
            self::PROJECT_PRESENTATION => 'Votre projet présenté en quelques pages : le défi, la solution, l’impact attendu.',
            self::KEY_MEMBER_CV => 'Un fichier unique regroupant les CV des personnes clés du projet.',
            self::LEGAL_EXISTENCE => 'RCCM, NIF ou tout justificatif d’existence légale de votre structure.',
            self::BUDGET_PLAN => 'Le budget détaillé et le plan d’action, si vous en avez déjà une version.',
            self::PROTOTYPE_DEMO => 'Captures d’écran ou document présentant votre prototype, schéma d’architecture compris.',
            self::LETTERS_AUTHORISATIONS => 'Lettres de partenariat, mandat de représentation ou autorisation d’usage de données.',
        };
    }

    /**
     * Extensions acceptées, reprises du §7.2 sans élargissement.
     *
     * Une seule restriction ajoutée, et elle est assumée : la vidéo courte que
     * le §7.2 mentionne pour le prototype n'est pas téléversable. Le §8.2
     * demande des pages légères et proscrit la vidéo en lecture automatique ;
     * faire monter un fichier vidéo depuis une connexion partagée nigérienne
     * coûterait au candidat plus que ce que la pièce apporte au jury. Captures
     * et document en tiennent lieu.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            // « PDF » au §7.2, sans alternative.
            self::PROJECT_PRESENTATION, self::KEY_MEMBER_CV => ['pdf'],
            // « PDF/JPG/PNG » au §7.2.
            self::LEGAL_EXISTENCE, self::LETTERS_AUTHORISATIONS, self::PROTOTYPE_DEMO => ['pdf', 'jpg', 'jpeg', 'png'],
            // « XLSX ou modèle web/PDF » au §7.2.
            self::BUDGET_PLAN => ['pdf', 'xlsx'],
        };
    }

    /**
     * Types MIME acceptés.
     *
     * Contrôlés **en plus** de l'extension, et à partir du contenu réel du
     * fichier : `mimes` de Laravel devine le type en lisant l'en-tête, là où
     * l'extension n'est qu'un morceau du nom que le navigateur a envoyé. Un
     * exécutable renommé `presentation.pdf` échoue donc ici.
     *
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        $par = [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ];

        $types = [];

        foreach ($this->extensions() as $extension) {
            foreach ($par[$extension] as $type) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    /**
     * Taille maximale, en kilo-octets.
     *
     * Le §7.2 dit « taille maximale configurable » sans donner de nombre, et
     * l'écran d'administration qui la portera n'existe pas encore. 5 Mo est
     * retenu comme valeur de lancement : c'est large pour une présentation de
     * quelques pages, et c'est déjà plusieurs minutes d'envoi sur une connexion
     * mobile partagée — le §8.2 fait de la faible connectivité une contrainte de
     * conception, pas une note de bas de page. Une limite plus haute
     * n'ajouterait rien au jury et coûterait au candidat.
     */
    public const MAX_KILOBYTES = 5 * 1024;

    /**
     * La pièce est-elle exigée pour ce type de candidature ?
     *
     * @see DocumentType Les trois « configurable / selon phase / selon cas »
     *      restent acceptées sans être exigées.
     */
    public function isRequiredFor(?CandidateType $type): bool
    {
        return match ($this) {
            // « Obligatoire », sans condition.
            self::PROJECT_PRESENTATION => true,
            // « Obligatoire selon type » : des CV supposent des membres.
            self::KEY_MEMBER_CV => TeamSection::attendDesMembres($type),
            // « Conditionnel » : une existence légale suppose une structure.
            self::LEGAL_EXISTENCE => TeamSection::attendUneStructure($type),
            default => false,
        };
    }

    /**
     * Les pièces exigées d'un dossier, dans l'ordre du §7.2.
     *
     * @return list<self>
     */
    public static function requiredFor(?CandidateType $type): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $piece): bool => $piece->isRequiredFor($type),
        ));
    }

    /**
     * Le catalogue mis en forme pour l'écran, contexte de candidature compris.
     *
     * @return list<array{value: string, label: string, help: string, required: bool, extensions: list<string>, maxKilobytes: int}>
     */
    public static function catalogueFor(?CandidateType $type): array
    {
        return array_map(
            static fn (self $piece): array => [
                'value' => $piece->value,
                'label' => $piece->label(),
                'help' => $piece->help(),
                'required' => $piece->isRequiredFor($type),
                'extensions' => $piece->extensions(),
                'maxKilobytes' => self::MAX_KILOBYTES,
            ],
            self::cases(),
        );
    }
}
