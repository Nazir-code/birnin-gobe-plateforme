<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\Gender;
use App\Domain\Candidate\PreferredChannel;
use App\Domain\Eligibility\CampaignEligibilityRules;
use App\Domain\Eligibility\EligibilityAssessment;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use DateTimeImmutable;

/**
 * Met une candidature en forme pour l'administration.
 *
 * Distinct d'`ApplicationPresenter`, qui sert le candidat, parce que les deux
 * publics ne regardent pas la même chose : le candidat suit *son* avancement et
 * a besoin de liens de reprise ; l'administration compare *des* dossiers et a
 * besoin d'identité, de campagne et de verdict. Mêler les deux produirait un
 * objet qui ment à moitié à chacun.
 *
 * Ce qui n'est **pas** dupliqué, et ne doit jamais l'être :
 *
 *   la progression   → `ApplicationProgress`, la règle du candidat (ADR-009) ;
 *   l'éligibilité    → `EvaluateEligibility`, le moteur du candidat (ADR-007).
 *
 * Cette classe ne calcule ni l'une ni l'autre. Elle les demande, et les met en
 * mots.
 *
 * Deux précautions de fond :
 *
 * - **Aucun JSON brut n'est renvoyé à l'écran.** Les réponses connues sont
 *   traduites en couples libellé/valeur, les enums rendus par leur `label()`.
 *   Une section qu'une phase ultérieure ajoutera n'a pas de libellés ici : elle
 *   est alors annoncée par son état et son nombre de réponses, jamais par ses
 *   clés techniques. Deviner ses champs à l'avance produirait des libellés faux.
 *
 * - **Le dossier est jugé par sa propre campagne.** `$application->campaign`,
 *   jamais `ActiveCampaign` : un dossier de l'édition 2026 reste jugé sur les
 *   critères de 2026, même après l'ouverture de 2027.
 */
final class AdminApplicationPresenter
{
    /**
     * Règles d'éligibilité déjà construites, par campagne.
     *
     * Une page affiche vingt-cinq dossiers pour deux ou trois campagnes : sans
     * cela, les mêmes `settings` seraient relus et normalisés vingt-cinq fois.
     *
     * @var array<int, CampaignEligibilityRules>
     */
    private array $reglesParCampagne = [];

    public function __construct(private readonly EvaluateEligibility $moteur) {}

    /**
     * Une ligne de la liste.
     *
     * @return array{
     *     id: int, candidateName: string, candidateEmail: string,
     *     campaignName: string, campaignCode: string,
     *     status: string, statusLabel: string,
     *     completionPercent: int, completedSections: int, totalSections: int,
     *     currentStep: string|null, currentStepLabel: string|null,
     *     candidateType: string|null, candidateTypeLabel: string|null,
     *     region: string|null, regionLabel: string|null,
     *     eligibility: array{outcome: string, label: string},
     *     submissionNumber: string|null, updatedAt: string|null, showUrl: string
     * }
     */
    public function row(Application $application): array
    {
        $verdict = $this->verdict($application);
        $reponses = $this->reponsesEligibilite($application);

        $type = CandidateType::tryFrom((string) ($reponses[EligibilitySection::CANDIDATE_TYPE] ?? ''));
        $zone = NigerRegion::tryFrom((string) ($reponses[EligibilitySection::INTERVENTION_REGION] ?? ''));

        // Le compte vient du `withCount` de la requête, donc de PostgreSQL, et
        // non de la colonne `completion_percent` — qui est un cache écrit à la
        // dernière sauvegarde du candidat et vieillit dès que le parcours ouvert
        // change. Un écran de pilotage lit l'état, pas le souvenir de l'état.
        $achevees = (int) ($application->completed_sections_count ?? 0);

        return [
            'id' => $application->getKey(),
            'candidateName' => $application->candidate?->name ?? '—',
            'candidateEmail' => $application->candidate?->email ?? '—',
            'campaignName' => $application->campaign?->name ?? '—',
            'campaignCode' => $application->campaign?->code ?? '—',
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'completionPercent' => ApplicationProgress::percentFromCompleted($achevees),
            'completedSections' => $achevees,
            'totalSections' => ApplicationSection::total(),
            'currentStep' => $application->current_step?->value,
            'currentStepLabel' => $application->current_step?->label(),
            'candidateType' => $type?->value,
            'candidateTypeLabel' => $type?->label(),
            'region' => $zone?->value,
            'regionLabel' => $zone?->label(),
            'eligibility' => ['outcome' => $verdict->outcome->value, 'label' => $verdict->outcome->label()],
            'submissionNumber' => $application->submission_number,
            'updatedAt' => $application->updated_at?->toIso8601String(),
            'showUrl' => route('admin.applications.show', $application),
        ];
    }

    /**
     * Le dossier complet, en lecture seule.
     *
     * @return array<string, mixed>
     */
    public function detail(Application $application): array
    {
        $verdict = $this->verdict($application);
        // Même règle que la liste et que le candidat, appliquée à la relation
        // déjà chargée : le détail n'ajoute pas de requête pour un compte.
        $comptables = ApplicationProgress::countableSections();
        $achevees = $application->sections
            ->filter(static fn (ApplicationSectionAnswers $ligne): bool => $ligne->completed_at !== null
                && in_array($ligne->section->value, $comptables, strict: true))
            ->count();

        return [
            'id' => $application->getKey(),
            'candidate' => [
                'name' => $application->candidate?->name ?? '—',
                'email' => $application->candidate?->email ?? '—',
            ],
            'campaign' => [
                'name' => $application->campaign?->name ?? '—',
                'code' => $application->campaign?->code ?? '—',
                'statusLabel' => $application->campaign?->status->label(),
                'opensAt' => $application->campaign?->opens_at?->toIso8601String(),
                'closesAt' => $application->campaign?->closes_at?->toIso8601String(),
                'timezone' => $application->campaign?->timezone,
            ],
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'completionPercent' => ApplicationProgress::percentFromCompleted($achevees),
            'completedSections' => $achevees,
            'totalSections' => ApplicationSection::total(),
            'currentStep' => $application->current_step?->value,
            'currentStepLabel' => $application->current_step?->label(),
            'submissionNumber' => $application->submission_number,
            'submittedAt' => $application->submitted_at?->toIso8601String(),
            'createdAt' => $application->created_at?->toIso8601String(),
            'updatedAt' => $application->updated_at?->toIso8601String(),
            'eligibility' => $verdict->toArray(),
            'sections' => $this->sections($application),
        ];
    }

    /**
     * Les neuf sections, dans l'ordre du concours, avec l'état de chacune.
     *
     * La boucle porte sur `ApplicationSection::cases()` : la section que
     * « Structure / équipe » ajoutera apparaîtra ici sans que rien ne change,
     * avec son état et son nombre de réponses. Ses libellés de champs, eux,
     * viendront avec elle — on ne les devine pas.
     *
     * @return list<array<string, mixed>>
     */
    private function sections(Application $application): array
    {
        return array_map(function (ApplicationSection $section) use ($application): array {
            $ligne = $application->sections->firstWhere('section', $section);
            $reponses = is_array($ligne?->answers) ? $ligne->answers : [];
            $renseignees = count(array_filter(
                $reponses,
                static fn (mixed $valeur): bool => $valeur !== null && $valeur !== '' && $valeur !== [],
            ));

            return [
                'key' => $section->value,
                'label' => $section->label(),
                'position' => $section->position(),
                'implemented' => $section->isImplemented(),
                'onOpenPath' => $section->isOnOpenPath(),
                'state' => $this->etat($section, $ligne, $renseignees),
                'completedAt' => $ligne?->completed_at?->toIso8601String(),
                'answeredCount' => $renseignees,
                'fields' => $this->champs($section, $reponses),
            ];
        }, ApplicationSection::cases());
    }

    /**
     * L'état d'une section, dans le vocabulaire de l'administrateur.
     *
     * Cinq états, et ils ne veulent pas dire la même chose : une section vide
     * parce que le candidat ne l'a pas encore ouverte n'est pas une section
     * vide parce que le produit ne la propose pas encore.
     */
    private function etat(ApplicationSection $section, ?ApplicationSectionAnswers $ligne, int $renseignees): string
    {
        return match (true) {
            ! $section->isImplemented() => 'non-implementee',
            $ligne?->completed_at !== null => 'complete',
            $renseignees > 0 => 'incomplete',
            ! $section->isOnOpenPath() => 'hors-parcours',
            default => 'non-commencee',
        };
    }

    /**
     * Réponses d'une section, en couples lisibles.
     *
     * @param  array<string, mixed>  $reponses
     * @return list<array{label: string, value: string}>
     */
    private function champs(ApplicationSection $section, array $reponses): array
    {
        if ($reponses === []) {
            return [];
        }

        $champs = match ($section) {
            ApplicationSection::ELIGIBILITY => $this->champsEligibilite($reponses),
            ApplicationSection::PROFILE => $this->champsProfil($reponses),
            ApplicationSection::CHALLENGE => $this->champsDefi($reponses),
            // Section ajoutée par une phase ultérieure : son état et son nombre
            // de réponses sont dits, ses champs attendent leurs libellés.
            default => [],
        };

        return array_values(array_filter(
            $champs,
            static fn (array $champ): bool => $champ['value'] !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsEligibilite(array $r): array
    {
        return [
            ['label' => 'Date de naissance', 'value' => $this->date($r[EligibilitySection::BIRTH_DATE] ?? null)],
            ['label' => 'Nationalité nigérienne', 'value' => $this->booleen($r[EligibilitySection::NIGERIEN_NATIONAL] ?? null)],
            ['label' => 'Réside au Niger', 'value' => $this->booleen($r[EligibilitySection::RESIDES_IN_NIGER] ?? null)],
            ['label' => 'Zone d’intervention', 'value' => $this->enum(NigerRegion::class, $r[EligibilitySection::INTERVENTION_REGION] ?? null)],
            ['label' => 'Forme de candidature', 'value' => $this->enum(CandidateType::class, $r[EligibilitySection::CANDIDATE_TYPE] ?? null)],
            ['label' => 'Effectif de l’équipe', 'value' => $this->texte($r[EligibilitySection::TEAM_SIZE] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsProfil(array $r): array
    {
        return [
            ['label' => 'Lieu de naissance', 'value' => $this->texte($r[ProfileSection::BIRTH_PLACE] ?? null)],
            ['label' => 'Sexe', 'value' => $this->enum(Gender::class, $r[ProfileSection::GENDER] ?? null)],
            ['label' => 'Téléphone principal', 'value' => $this->texte($r[ProfileSection::PHONE_PRIMARY] ?? null)],
            ['label' => 'Téléphone secondaire', 'value' => $this->texte($r[ProfileSection::PHONE_SECONDARY] ?? null)],
            ['label' => 'Canal préféré', 'value' => $this->enum(PreferredChannel::class, $r[ProfileSection::PREFERRED_CHANNEL] ?? null)],
            ['label' => 'Région de résidence', 'value' => $this->enum(NigerRegion::class, $r[ProfileSection::RESIDENCE_REGION] ?? null)],
            ['label' => 'Quartier ou village', 'value' => $this->texte($r[ProfileSection::RESIDENCE_LOCALITY] ?? null)],
            ['label' => 'Occupation principale', 'value' => $this->texte($r[ProfileSection::OCCUPATION] ?? null)],
            ['label' => 'Niveau d’études', 'value' => $this->enum(EducationLevel::class, $r[ProfileSection::EDUCATION_LEVEL] ?? null)],
            ['label' => 'Spécialité', 'value' => $this->texte($r[ProfileSection::SPECIALTY] ?? null)],
            ['label' => 'Besoin d’accessibilité', 'value' => $this->texte($r[ProfileSection::ACCESSIBILITY_NEED] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsDefi(array $r): array
    {
        return [
            ['label' => 'Problème principal', 'value' => $this->texte($r['main_challenge'] ?? null)],
            ['label' => 'Personnes touchées', 'value' => $this->texte($r['affected_people'] ?? null)],
            ['label' => 'Causes profondes', 'value' => $this->texte($r['root_causes'] ?? null)],
            ['label' => 'Localisation', 'value' => $this->enum(NigerRegion::class, $r[ChallengeSection::REGION_FIELD] ?? null)],
        ];
    }

    /**
     * Le verdict, rendu par le moteur du candidat.
     *
     * Les réponses viennent de la relation déjà chargée et les règles d'un cache
     * par campagne : évaluer une page de vingt-cinq dossiers n'ajoute aucune
     * requête.
     */
    private function verdict(Application $application): EligibilityAssessment
    {
        $campagne = $application->campaign;
        $cle = $campagne?->getKey() ?? 0;

        $this->reglesParCampagne[$cle] ??= CampaignEligibilityRules::forCampaign($campagne);

        return $this->moteur->handle($this->reponsesEligibilite($application), $this->reglesParCampagne[$cle]);
    }

    /** @return array<string, mixed> */
    private function reponsesEligibilite(Application $application): array
    {
        $ligne = $application->sections->firstWhere('section', ApplicationSection::ELIGIBILITY);

        return is_array($ligne?->answers) ? $ligne->answers : [];
    }

    private function texte(mixed $valeur): string
    {
        return match (true) {
            $valeur === null || $valeur === '' => '',
            is_bool($valeur) => $this->booleen($valeur),
            default => (string) $valeur,
        };
    }

    private function booleen(mixed $valeur): string
    {
        return is_bool($valeur) ? ($valeur ? 'Oui' : 'Non') : '';
    }

    private function date(mixed $valeur): string
    {
        if (! is_string($valeur) || $valeur === '') {
            return '';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return $date === false ? $valeur : $date->format('d/m/Y');
    }

    /**
     * Rend un enum par son libellé, jamais par sa valeur persistée.
     *
     * Une valeur devenue inconnue — référentiel modifié après coup — ressort
     * telle quelle plutôt que disparaître : l'administrateur doit voir qu'il y a
     * une réponse, même illisible.
     *
     * @param  class-string<\BackedEnum>  $enum
     */
    private function enum(string $enum, mixed $valeur): string
    {
        if (! is_string($valeur) || $valeur === '') {
            return '';
        }

        $cas = $enum::tryFrom($valeur);

        return $cas === null ? $valeur : $cas->label();
    }
}
