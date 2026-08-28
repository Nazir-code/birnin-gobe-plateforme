<?php

namespace App\Domain\Reporting;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\Gender;
use App\Domain\Evaluation\AssignmentStatus;
use App\Domain\Reference\NigerRegion;
use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\VerificationControl;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\EvaluationAssignment;
use App\Models\VerificationDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Les indicateurs du §13.1, calculés à la lecture.
 *
 * **Rien n'est pré-agrégé.** Chaque chiffre est une requête sur les données
 * réelles, et sa formule est écrite à côté de lui (§13.4). Une table
 * d'agrégats aurait été plus rapide et aurait introduit le pire défaut d'un
 * tableau de bord : un chiffre juste hier, faux aujourd'hui, et que rien ne
 * signale. Le jour où le volume l'imposera, `IndicatorRefresh` dira que
 * l'indicateur a changé de fréquence.
 *
 * **Trois familles sur six seulement sont renseignées.** Mobilisation, Finale
 * et Qualité de service n'ont pas de source — voir `IndicatorFamily`. Elles
 * restent affichées, vides et expliquées : une famille retirée de l'écran se
 * lirait comme une famille qui n'existe pas.
 *
 * **Le périmètre est la campagne.** Sans campagne choisie, les chiffres portent
 * sur toutes les éditions confondues — ce qui est rarement ce qu'on veut, et
 * l'écran le dit. Un total inter-éditions n'est pas faux, il répond juste à une
 * autre question.
 */
final readonly class ComputeIndicators
{
    /**
     * @return list<Indicator>
     */
    public function indicateurs(?Campaign $campagne): array
    {
        return [
            ...$this->candidatures($campagne),
            ...$this->admissibilite($campagne),
            ...$this->evaluation($campagne),
        ];
    }

    /**
     * @return list<IndicatorBreakdown>
     */
    public function ventilations(?Campaign $campagne): array
    {
        return [
            IndicatorBreakdown::depuis(
                'candidatures.par_statut',
                IndicatorFamily::CANDIDATURES,
                'Candidatures par statut',
                'Répartition des dossiers de la campagne selon leur statut métier.',
                'COUNT(applications) GROUP BY status',
                'applications.status',
                IndicatorAccess::INTERNAL,
                $this->parStatut($campagne),
            ),
            IndicatorBreakdown::depuis(
                'candidatures.par_type',
                IndicatorFamily::CANDIDATURES,
                'Candidatures par forme de candidature',
                'Répartition selon la forme déclarée à l’étape 1 : individuel, équipe ou startup.',
                'COUNT(applications) GROUP BY answers->>candidate_type (section « éligibilité »)',
                'application_sections.answers',
                IndicatorAccess::INTERNAL,
                $this->parReponse($campagne, ApplicationSection::ELIGIBILITY, EligibilitySection::CANDIDATE_TYPE, CandidateType::class),
            ),
            IndicatorBreakdown::depuis(
                'candidatures.par_thematique',
                IndicatorFamily::CANDIDATURES,
                'Candidatures par thématique',
                'Répartition selon l’axe choisi à l’étape « Défi ».',
                'COUNT(applications) GROUP BY answers->>theme (section « défi »)',
                'application_sections.answers',
                IndicatorAccess::INTERNAL,
                $this->parReponse($campagne, ApplicationSection::CHALLENGE, ChallengeSection::THEME_FIELD, ProjectTheme::class),
            ),
            IndicatorBreakdown::depuis(
                'candidatures.par_region',
                IndicatorFamily::CANDIDATURES,
                'Candidatures par zone d’intervention',
                'Répartition selon la région d’intervention déclarée à l’étape 1.',
                'COUNT(applications) GROUP BY answers->>intervention_region',
                'application_sections.answers',
                // Localisation : le §13.4 la range parmi les données dont les
                // petits effectifs doivent être masqués.
                IndicatorAccess::SENSITIVE,
                $this->parReponse($campagne, ApplicationSection::ELIGIBILITY, EligibilitySection::INTERVENTION_REGION, NigerRegion::class),
            ),
            IndicatorBreakdown::depuis(
                'candidatures.par_sexe',
                IndicatorFamily::CANDIDATURES,
                'Candidatures par sexe déclaré',
                'Suivi de l’inclusion (§13.4). Le sexe est déclaratif et facultatif ; les dossiers sans réponse forment une modalité à part.',
                'COUNT(applications) GROUP BY answers->>gender (section « profil »)',
                'application_sections.answers',
                IndicatorAccess::SENSITIVE,
                $this->parReponse($campagne, ApplicationSection::PROFILE, ProfileSection::GENDER, Gender::class),
            ),
            IndicatorBreakdown::depuis(
                'admissibilite.motifs',
                IndicatorFamily::ADMISSIBILITE,
                'Motifs d’irrecevabilité',
                'Répartition des rejets par motif principal, tel qu’il a été codifié au moment de la décision (§10.3).',
                'COUNT(verification_decisions) WHERE decision = INADMISSIBLE GROUP BY primary_reason',
                'verification_decisions.primary_reason',
                IndicatorAccess::INTERNAL,
                $this->motifsDeRejet($campagne),
            ),
            IndicatorBreakdown::depuis(
                'evaluation.charge',
                IndicatorFamily::EVALUATION,
                'Charge par évaluateur',
                'Nombre de dossiers en vigueur confiés à chaque évaluateur.',
                'COUNT(evaluation_assignments) WHERE released_at IS NULL GROUP BY evaluator_id',
                'evaluation_assignments',
                IndicatorAccess::INTERNAL,
                $this->chargeParEvaluateur($campagne),
            ),
        ];
    }

    /**
     * @return list<Indicator>
     */
    private function candidatures(?Campaign $campagne): array
    {
        $brouillons = $this->compter($campagne, [ApplicationStatus::DRAFT]);
        $deposees = $this->dossiers($campagne)->where('status', '!=', ApplicationStatus::DRAFT->value)->count();

        return [
            (new Indicator(
                key: 'candidatures.brouillons',
                family: IndicatorFamily::CANDIDATURES,
                label: 'Brouillons',
                definition: 'Dossiers ouverts par un candidat et jamais déposés.',
                formula: 'COUNT(applications WHERE status = DRAFT)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($brouillons),

            (new Indicator(
                key: 'candidatures.deposees',
                family: IndicatorFamily::CANDIDATURES,
                label: 'Dossiers déposés',
                definition: 'Dossiers ayant franchi le dépôt, quel que soit leur sort depuis.',
                formula: 'COUNT(applications WHERE status <> DRAFT)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($deposees),

            (new Indicator(
                key: 'candidatures.completude_moyenne',
                family: IndicatorFamily::CANDIDATURES,
                label: 'Complétude moyenne des brouillons',
                definition: 'Part moyenne des sections achevées parmi les neuf, sur les seuls brouillons — un dossier déposé est complet par construction.',
                formula: 'AVG(sections achevées du parcours ouvert) / 9 × 100',
                source: 'application_sections.completed_at',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
                unit: '%',
            ))->withValue($this->completudeMoyenne($campagne)),
        ];
    }

    /**
     * @return list<Indicator>
     */
    private function admissibilite(?Campaign $campagne): array
    {
        return [
            (new Indicator(
                key: 'admissibilite.a_controler',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'À contrôler',
                definition: 'Dossiers déposés qu’aucun vérificateur n’a encore ouverts.',
                formula: 'COUNT(applications WHERE status = SUBMITTED)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($this->compter($campagne, [ApplicationStatus::SUBMITTED])),

            (new Indicator(
                key: 'admissibilite.en_controle',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'En contrôle',
                definition: 'Dossiers dont la grille du §10.2 est entamée mais non tranchée.',
                formula: 'COUNT(applications WHERE status = PENDING_REVIEW)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($this->compter($campagne, [ApplicationStatus::PENDING_REVIEW])),

            (new Indicator(
                key: 'admissibilite.clarifications',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'Clarifications en attente',
                definition: 'Dossiers pour lesquels une clarification a été demandée et dont la réponse n’est pas arrivée.',
                formula: 'COUNT(applications WHERE status = CLARIFICATION_REQUESTED)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($this->compter($campagne, [ApplicationStatus::CLARIFICATION_REQUESTED])),

            (new Indicator(
                key: 'admissibilite.recevables',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'Déclarés recevables',
                definition: 'Dossiers déclarés recevables, y compris ceux déjà entrés en évaluation.',
                formula: 'COUNT(applications WHERE status IN (ADMISSIBLE, IN_EVALUATION, EVALUATED, …))',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($this->compter($campagne, [
                ApplicationStatus::ADMISSIBLE,
                ApplicationStatus::IN_EVALUATION,
                ApplicationStatus::EVALUATED,
            ])),

            (new Indicator(
                key: 'admissibilite.irrecevables',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'Déclarés irrecevables',
                definition: 'Dossiers écartés au contrôle d’admissibilité.',
                formula: 'COUNT(applications WHERE status = INADMISSIBLE)',
                source: 'applications.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue($this->compter($campagne, [ApplicationStatus::INADMISSIBLE])),

            (new Indicator(
                key: 'admissibilite.delai_moyen',
                family: IndicatorFamily::ADMISSIBILITE,
                label: 'Délai moyen de décision',
                definition: 'Temps écoulé entre le dépôt d’un dossier et la première décision d’admissibilité le concernant.',
                formula: 'AVG(première verification_decisions.created_at − applications.submitted_at), en jours',
                source: 'verification_decisions, applications.submitted_at',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
                unit: 'jours',
            ))->withValue($this->delaiMoyenDeDecision($campagne)),
        ];
    }

    /**
     * @return list<Indicator>
     */
    private function evaluation(?Campaign $campagne): array
    {
        $affectations = $this->affectations($campagne);

        return [
            (new Indicator(
                key: 'evaluation.affectations',
                family: IndicatorFamily::EVALUATION,
                label: 'Affectations en vigueur',
                definition: 'Couples dossier-évaluateur actifs : ni retirés, ni frappés d’un conflit déclaré.',
                formula: 'COUNT(evaluation_assignments WHERE released_at IS NULL)',
                source: 'evaluation_assignments',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue((clone $affectations)->whereNull('released_at')->count()),

            (new Indicator(
                key: 'evaluation.dossiers_sans_evaluateur',
                family: IndicatorFamily::EVALUATION,
                label: 'Dossiers recevables sans évaluateur',
                definition: 'Dossiers déclarés recevables auxquels aucune affectation en vigueur n’est rattachée.',
                formula: 'COUNT(applications WHERE status = ADMISSIBLE AND aucune affectation en vigueur)',
                source: 'applications, evaluation_assignments',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue(
                $this->dossiers($campagne)
                    ->where('status', ApplicationStatus::ADMISSIBLE->value)
                    ->whereDoesntHave('assignments', fn (Builder $q) => $q->whereNull('released_at'))
                    ->count(),
            ),

            (new Indicator(
                key: 'evaluation.conflits',
                family: IndicatorFamily::EVALUATION,
                label: 'Conflits déclarés',
                definition: 'Récusations enregistrées, cumulées depuis le début de la campagne. Un conflit est définitif (§11.1).',
                formula: 'COUNT(evaluation_assignments WHERE status = CONFLICT)',
                source: 'evaluation_assignments.status',
                refresh: IndicatorRefresh::LIVE,
                access: IndicatorAccess::INTERNAL,
            ))->withValue((clone $affectations)->where('status', AssignmentStatus::CONFLICT->value)->count()),
        ];
    }

    // — Requêtes ————————————————————————————————————————————————

    /** @return Builder<Application> */
    private function dossiers(?Campaign $campagne): Builder
    {
        return Application::query()
            ->when($campagne !== null, fn (Builder $q) => $q->where('campaign_id', $campagne->getKey()));
    }

    /** @return Builder<EvaluationAssignment> */
    private function affectations(?Campaign $campagne): Builder
    {
        return EvaluationAssignment::query()
            ->when($campagne !== null, fn (Builder $q) => $q->whereHas(
                'application',
                fn (Builder $a) => $a->where('campaign_id', $campagne->getKey()),
            ));
    }

    /** @param list<ApplicationStatus> $statuts */
    private function compter(?Campaign $campagne, array $statuts): int
    {
        return $this->dossiers($campagne)
            ->whereIn('status', array_map(static fn (ApplicationStatus $s): string => $s->value, $statuts))
            ->count();
    }

    /**
     * Complétude moyenne des brouillons, calculée par la règle du domaine.
     *
     * Le dénominateur est le total des neuf sections, comme dans
     * `ApplicationProgress` : deux dénominateurs différents produiraient deux
     * pourcentages pour un même dossier, l'un sur l'écran candidat, l'autre
     * ici.
     */
    private function completudeMoyenne(?Campaign $campagne): ?float
    {
        $brouillons = $this->dossiers($campagne)->where('status', ApplicationStatus::DRAFT->value);

        if ((clone $brouillons)->count() === 0) {
            return null;
        }

        $achevees = (clone $brouillons)
            ->withCount(['sections as achevees' => fn (Builder $q) => $q
                ->whereNotNull('completed_at')
                ->whereIn('section', array_map(
                    static fn (ApplicationSection $s): string => $s->value,
                    ApplicationSection::openPath(),
                ))])
            ->get()
            ->avg('achevees');

        return round(((float) $achevees) / ApplicationSection::total() * 100, 1);
    }

    /**
     * Délai moyen entre dépôt et première décision d'admissibilité.
     *
     * « Première » et non « dernière » : le §10.3 permet des versions
     * successives, et ce qu'on mesure est le temps de réaction du contrôle, pas
     * la durée totale d'un éventuel contentieux.
     */
    private function delaiMoyenDeDecision(?Campaign $campagne): ?float
    {
        $premieres = VerificationDecision::query()
            ->selectRaw('application_id, MIN(created_at) as decidee_le')
            ->whereIn('decision', [
                AdmissibilityDecision::ADMISSIBLE->value,
                AdmissibilityDecision::INADMISSIBLE->value,
            ])
            ->groupBy('application_id');

        $moyenne = DB::query()
            ->fromSub($premieres, 'd')
            ->join('applications', 'applications.id', '=', 'd.application_id')
            ->when($campagne !== null, fn ($q) => $q->where('applications.campaign_id', $campagne->getKey()))
            ->whereNotNull('applications.submitted_at')
            ->avg(DB::raw('EXTRACT(EPOCH FROM (d.decidee_le - applications.submitted_at)) / 86400'));

        return $moyenne === null ? null : round((float) $moyenne, 1);
    }

    /** @return array<string, int> */
    private function parStatut(?Campaign $campagne): array
    {
        $comptes = $this->dossiers($campagne)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $ventilation = [];

        foreach (ApplicationStatus::cases() as $statut) {
            $ventilation[$statut->label()] = (int) ($comptes[$statut->value] ?? 0);
        }

        return $ventilation;
    }

    /**
     * Ventilation sur une réponse rangée dans le `jsonb` d'une section.
     *
     * Les modalités viennent de l'enum, pas des données : une modalité absente
     * des dossiers doit apparaître à zéro. Une ventilation qui ne montrerait que
     * ce qui existe cacherait précisément les régions ou les thématiques que
     * personne n'a choisies — l'information la plus utile au pilotage.
     *
     * @param  class-string  $enum
     * @return array<string, int>
     */
    private function parReponse(?Campaign $campagne, ApplicationSection $section, string $champ, string $enum): array
    {
        $comptes = $this->dossiers($campagne)
            ->join('application_sections', function ($jointure) use ($section): void {
                $jointure->on('application_sections.application_id', '=', 'applications.id')
                    ->where('application_sections.section', '=', $section->value);
            })
            ->selectRaw("application_sections.answers->>'{$champ}' as modalite, COUNT(*) as total")
            ->groupBy('modalite')
            ->pluck('total', 'modalite')
            ->all();

        $ventilation = [];

        foreach ($enum::cases() as $cas) {
            $ventilation[$cas->label()] = (int) ($comptes[$cas->value] ?? 0);
        }

        // Ce que personne n'a renseigné compte aussi : le §13.4 veut le suivi de
        // l'inclusion, et un champ facultatif laissé vide est une donnée.
        $connues = array_map(static fn ($cas): string => $cas->value, $enum::cases());
        $nonRenseigne = 0;

        foreach ($comptes as $modalite => $total) {
            if ($modalite === null || ! in_array((string) $modalite, $connues, true)) {
                $nonRenseigne += (int) $total;
            }
        }

        $ventilation['Non renseigné'] = $nonRenseigne;

        return $ventilation;
    }

    /** @return array<string, int> */
    private function motifsDeRejet(?Campaign $campagne): array
    {
        $comptes = VerificationDecision::query()
            ->where('decision', AdmissibilityDecision::INADMISSIBLE->value)
            ->when($campagne !== null, fn (Builder $q) => $q->whereHas(
                'application',
                fn (Builder $a) => $a->where('campaign_id', $campagne->getKey()),
            ))
            ->selectRaw('primary_reason, COUNT(*) as total')
            ->groupBy('primary_reason')
            ->pluck('total', 'primary_reason')
            ->all();

        $ventilation = [];

        foreach (VerificationControl::cases() as $controle) {
            $ventilation[$controle->label()] = (int) ($comptes[$controle->value] ?? 0);
        }

        return $ventilation;
    }

    /** @return array<string, int> */
    private function chargeParEvaluateur(?Campaign $campagne): array
    {
        $lignes = $this->affectations($campagne)
            ->whereNull('released_at')
            ->with('evaluator:id,name')
            ->get();

        $ventilation = [];

        foreach ($lignes as $ligne) {
            $nom = $ligne->evaluator?->name ?? 'Compte supprimé';
            $ventilation[$nom] = ($ventilation[$nom] ?? 0) + 1;
        }

        ksort($ventilation);

        return $ventilation;
    }
}
