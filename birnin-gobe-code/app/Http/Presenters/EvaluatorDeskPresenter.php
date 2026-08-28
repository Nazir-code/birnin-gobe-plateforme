<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\ScoreAnchor;
use App\Domain\Evaluation\ScoreSheet;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationScore;

/**
 * Met le plan de travail de l'évaluateur en forme — §11.1, §11.2, §11.3.
 *
 * **Le dossier n'est pas remis en forme ici** : `AdminApplicationPresenter`
 * rend déjà les sections, et le contrôle d'admissibilité l'a déjà délégué de
 * cette façon. Trois mises en forme du même dossier finiraient par diverger, et
 * la divergence porterait sur ce qui fonde une note.
 *
 * **Ce qui change, en revanche, c'est le périmètre.** L'évaluateur ne reçoit
 * pas les neuf sections : l'éligibilité (pièces d'identité, date de naissance,
 * coordonnées) et les déclarations sont retirées, ainsi que l'adresse
 * électronique du candidat. Ce n'est pas de l'anonymat — la section
 * « Structure / équipe » reste entière, et il le faut, puisque le §11.2 fait
 * noter l'équipe sur dix points. C'est le contrat « données sensibles masquées
 * selon le rôle » appliqué au cas réel : la recevabilité a déjà été tranchée au
 * §10, et rien dans la grille de notation ne se juge sur un numéro de
 * téléphone.
 *
 * **Rien de ce que rend cette classe ne concerne un autre évaluateur.** Ni note,
 * ni recommandation, ni même le nombre de personnes affectées au dossier : le
 * §11.3 veut les évaluations indépendantes jusqu'au verrouillage, et savoir que
 * deux collègues ont déjà verrouillé suffirait à faire hésiter sur une note
 * isolée.
 */
final readonly class EvaluatorDeskPresenter
{
    /**
     * Les sections que l'évaluateur a besoin de lire.
     *
     * Ordre du parcours, moins l'éligibilité et la relecture. La liste est
     * explicite plutôt que soustractive : ajouter une section au parcours ne
     * doit pas la rendre visible ici par accident.
     */
    private const SECTIONS_VISIBLES = [
        ApplicationSection::PROFILE,
        ApplicationSection::TEAM,
        ApplicationSection::CHALLENGE,
        ApplicationSection::SOLUTION,
        ApplicationSection::IMPACT,
        ApplicationSection::IMPLEMENTATION,
        ApplicationSection::ATTACHMENTS,
    ];

    public function __construct(private AdminApplicationPresenter $dossierPresenter) {}

    /**
     * Une ligne du plan de travail.
     *
     * @return array<string, mixed>
     */
    public function deskRow(EvaluationAssignment $affectation): array
    {
        $dossier = $affectation->application;
        $evaluation = $affectation->evaluation;
        $feuille = $evaluation === null ? null : $this->feuille($evaluation);

        return [
            'id' => $affectation->getKey(),
            'submissionNumber' => $dossier?->submission_number,
            'campaignName' => $dossier?->campaign?->name ?? '—',
            'themeLabel' => $this->theme($dossier?->project_theme),
            'assignedAt' => $affectation->assigned_at?->toIso8601String(),
            'charterAccepted' => $affectation->charteAcceptee(),
            'evaluationStatus' => $evaluation?->status->value,
            'evaluationStatusLabel' => $evaluation?->status->label(),
            'lockedAt' => $evaluation?->locked_at?->toIso8601String(),
            // Sur 8 : c'est l'avancement de la grille, pas un pourcentage de
            // note. Un dossier noté 8/8 peut valoir 12 points sur 100.
            'scoredCriteria' => $feuille === null ? 0 : count(EvaluationCriterion::cases()) - count($feuille->manquants()),
            'totalCriteria' => count(EvaluationCriterion::cases()),
            'totalScore' => $evaluation?->estVerrouillee() ? $evaluation->total_score : null,
            'showUrl' => route('evaluator.assignments.show', $affectation),
        ];
    }

    /**
     * L'écran de notation d'un dossier.
     *
     * @return array<string, mixed>
     */
    public function dossier(EvaluationAssignment $affectation, Evaluation $evaluation): array
    {
        $dossier = $affectation->application;
        $feuille = $this->feuille($evaluation);
        $verrouillee = $evaluation->estVerrouillee();

        return [
            'assignment' => [
                'id' => $affectation->getKey(),
                'assignedAt' => $affectation->assigned_at?->toIso8601String(),
                'acceptedAt' => $affectation->accepted_at?->toIso8601String(),
            ],
            'application' => [
                'submissionNumber' => $dossier?->submission_number,
                'campaignName' => $dossier?->campaign?->name ?? '—',
                'themeLabel' => $this->theme($dossier?->project_theme),
                'submittedAt' => $dossier?->submitted_at?->toIso8601String(),
                // Le nom de la structure porteuse, pas l'adresse du compte : le
                // §11.2 fait noter l'équipe, pas joindre le candidat.
                'candidateName' => $dossier?->candidate?->name ?? '—',
            ],
            'sections' => $this->sectionsVisibles($affectation),
            'evaluation' => [
                'id' => $evaluation->getKey(),
                'status' => $evaluation->status->value,
                'statusLabel' => $evaluation->status->label(),
                'locked' => $verrouillee,
                'lockedAt' => $evaluation->locked_at?->toIso8601String(),
                'recommendation' => $evaluation->recommendation?->value,
                'comment' => $evaluation->comment,
                // Le total n'est arrêté qu'au verrouillage ; avant, l'écran
                // recalcule le même chiffre à chaque frappe.
                'totalScore' => $verrouillee ? $evaluation->total_score : $feuille->total(),
            ],
            'criteria' => array_map(
                static fn (EvaluationCriterion $critere): array => [
                    'value' => $critere->value,
                    'label' => $critere->label(),
                    'weight' => $critere->weight(),
                    'elements' => $critere->elements(),
                ],
                EvaluationCriterion::cases(),
            ),
            'scores' => array_map(
                static fn (EvaluationCriterion $critere): array => [
                    'criterion' => $critere->value,
                    'score' => $feuille->score($critere),
                    'comment' => $feuille->comment($critere),
                ],
                EvaluationCriterion::cases(),
            ),
            'anchors' => ScoreAnchor::options(),
            'recommendations' => EvaluationRecommendation::options(),
            'limits' => [
                'maxScore' => EvaluationCriterion::MAX_SCORE,
                'totalWeight' => EvaluationCriterion::TOTAL_WEIGHT,
            ],
            'urls' => [
                'save' => route('evaluator.assignments.save', $affectation),
                'lock' => route('evaluator.assignments.lock', $affectation),
                'conflict' => route('evaluator.assignments.conflict', $affectation),
                'back' => route('evaluator.assignments'),
            ],
        ];
    }

    /**
     * La charte, avant tout accès au dossier — §11.1.
     *
     * Le texte est ici et non en base : il n'y a pas de CMS, et publier une
     * charte administrable qu'aucun écran ne permet d'éditer donnerait à croire
     * qu'elle a été validée institutionnellement. Le jour où le §9.2
     * « Publication » existera, elle en viendra — le contrat de cette méthode ne
     * changera pas.
     *
     * @return array<string, mixed>
     */
    public function charte(EvaluationAssignment $affectation): array
    {
        $dossier = $affectation->application;

        return [
            'assignment' => [
                'id' => $affectation->getKey(),
                'assignedAt' => $affectation->assigned_at?->toIso8601String(),
            ],
            'application' => [
                'submissionNumber' => $dossier?->submission_number,
                'campaignName' => $dossier?->campaign?->name ?? '—',
                'themeLabel' => $this->theme($dossier?->project_theme),
            ],
            'engagements' => [
                [
                    'title' => 'Confidentialité',
                    'text' => 'Le contenu du dossier, les pièces jointes et l’identité des porteurs ne sont ni diffusés, ni cités, ni conservés hors de la plateforme, pendant comme après le concours.',
                ],
                [
                    'title' => 'Impartialité',
                    'text' => 'Je n’ai avec ce dossier, ses porteurs ou leur structure aucun lien personnel, familial, professionnel ou financier de nature à influencer mon jugement.',
                ],
                [
                    'title' => 'Récusation',
                    'text' => 'Si un tel lien existe ou apparaît en cours de lecture, je me récuse depuis cet écran plutôt que de noter le dossier. La récusation est un geste normal, pas un échec.',
                ],
                [
                    'title' => 'Indépendance',
                    'text' => 'Je note ce dossier sur la seule grille du concours, sans concertation avec les autres évaluateurs avant le verrouillage de mon évaluation.',
                ],
            ],
            'urls' => [
                'accept' => route('evaluator.assignments.charter', $affectation),
                'conflict' => route('evaluator.assignments.conflict', $affectation),
                'back' => route('evaluator.assignments'),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sectionsVisibles(EvaluationAssignment $affectation): array
    {
        $dossier = $affectation->application;

        if ($dossier === null) {
            return [];
        }

        $autorisees = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            self::SECTIONS_VISIBLES,
        );

        $sections = array_values(array_filter(
            $this->dossierPresenter->sections($dossier),
            static fn (array $section): bool => in_array($section['key'], $autorisees, strict: true),
        ));

        // Les liens de téléchargement sont réécrits vers la route de l'espace
        // évaluateur : ceux d'`AdminApplicationPresenter` visent une route
        // derrière `role:admin`, et les laisser tels quels donnerait des liens
        // qui échouent sur la seule section — les pièces — dont le §11.2 fait
        // dépendre la faisabilité technique et le prototype.
        return array_map(function (array $section) use ($affectation): array {
            // Les coordonnées des membres de l'équipe sont retirées. Le §11.2
            // fait noter « complémentarité, expérience, engagement, gouvernance
            // et disponibilité » : rien là-dedans ne se juge sur un numéro de
            // téléphone, et une adresse électronique dans un dossier confié à
            // une dizaine d'évaluateurs est une donnée qui circule sans motif.
            if (is_array($section['members'] ?? null)) {
                $section['members'] = array_map(
                    static function (array $membre): array {
                        unset($membre['email'], $membre['phone']);

                        return $membre;
                    },
                    $section['members'],
                );
            }

            if (! is_array($section['documents'] ?? null)) {
                return $section;
            }

            $section['documents'] = array_map(
                static fn (array $piece): array => [
                    ...$piece,
                    'downloadUrl' => route('evaluator.assignments.documents.download', [
                        $affectation,
                        $piece['type'],
                    ]),
                ],
                $section['documents'],
            );

            return $section;
        }, $sections);
    }

    private function feuille(Evaluation $evaluation): ScoreSheet
    {
        $lignes = [];

        foreach ($evaluation->scores as $ligne) {
            /** @var EvaluationScore $ligne */
            $lignes[$ligne->criterion->value] = [
                'score' => $ligne->score,
                'comment' => $ligne->comment,
            ];
        }

        return ScoreSheet::make($lignes);
    }

    /** La thématique, ou « — » : aucune n'est attribuée d'office. */
    private function theme(mixed $brut): string
    {
        $theme = is_string($brut) ? ProjectTheme::tryFrom($brut) : null;

        return $theme?->label() ?? '—';
    }
}
