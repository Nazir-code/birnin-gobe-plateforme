<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\AttachmentsSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ImpactSection;
use App\Domain\Application\ImplementationSection;
use App\Domain\Application\MaturityStage;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\SolutionSection;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Application\TeamSection;
use App\Domain\Application\TeamSectionAssessment;
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
     *     theme: string|null, themeLabel: string|null,
     *     candidateType: string|null, candidateTypeLabel: string|null,
     *     region: string|null, regionLabel: string|null,
     *     eligibility: array{outcome: string, label: string},
     *     submissionNumber: string|null, submittedAt: string|null,
     *     updatedAt: string|null, showUrl: string
     * }
     */
    public function row(Application $application): array
    {
        $verdict = $this->verdict($application);
        $reponses = $this->reponsesEligibilite($application);

        $type = CandidateType::tryFrom((string) ($reponses[EligibilitySection::CANDIDATE_TYPE] ?? ''));
        $theme = ProjectTheme::tryFrom((string) ($application->project_theme ?? ''));
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
            // Extraite par une sous-requête de `ApplicationIndexQuery`, et non
            // de la section « Défi » chargée : la liste n'a pas à transporter
            // trois champs de cinq cents caractères pour afficher un intitulé.
            'theme' => $theme?->value,
            'themeLabel' => $theme?->label(),
            'candidateType' => $type?->value,
            'candidateTypeLabel' => $type?->label(),
            'region' => $zone?->value,
            'regionLabel' => $zone?->label(),
            'eligibility' => ['outcome' => $verdict->outcome->value, 'label' => $verdict->outcome->label()],
            // Numéro et date de dépôt restent `null` tant que le dossier est un
            // brouillon : l'écran les rend alors par un tiret. Un « — » se lit
            // comme « pas déposé » ; une date par défaut se lirait comme un
            // dépôt qui n'a pas eu lieu.
            'submissionNumber' => $application->submission_number,
            'submittedAt' => $application->submitted_at?->toIso8601String(),
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
     * La boucle porte sur `ApplicationSection::cases()` : « Structure / équipe »
     * y est apparue d'elle-même à l'ouverture de l'étape 3, avec son état et son
     * nombre de réponses. Seuls ses libellés de champs ont dû être ajoutés — ils
     * ne se devinent pas, ils viennent avec la section.
     *
     * @return list<array<string, mixed>>
     */
    /*
     * Publique, et non plus privée : l'écran de relecture du candidat
     * (étape 9) rend les mêmes réponses que le back-office. Deux mises en
     * forme des mêmes données finiraient par diverger, et le candidat doit
     * relire exactement ce que le vérificateur lira. Seule la visibilité
     * change ; le corps de la méthode est inchangé.
     */
    public function sections(Application $application): array
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
                // Deux clés propres à « Structure / équipe » : les membres se
                // présentent en fiches, pas en couples libellé/valeur, et la
                // synthèse vient du domaine — l'administration ne réévalue pas
                // la complétude de cette section.
                'members' => $section === ApplicationSection::TEAM ? $this->membres($reponses) : null,
                'team' => $section === ApplicationSection::TEAM ? $this->syntheseEquipe($application, $reponses) : null,
                // Clé propre à « Pièces / déclarations » : un fichier ne se
                // présente pas en couple libellé/valeur. Lecture seule, comme
                // tout ce que voit cet écran.
                'documents' => $section === ApplicationSection::ATTACHMENTS ? $this->pieces($application) : null,
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
            ApplicationSection::TEAM => $this->champsStructure($reponses),
            ApplicationSection::SOLUTION => $this->champsSolution($reponses),
            ApplicationSection::IMPACT => $this->champsImpact($reponses),
            ApplicationSection::IMPLEMENTATION => $this->champsPlan($reponses),
            ApplicationSection::ATTACHMENTS => $this->champsDeclarations($reponses),
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
     * Identité de la structure, pour une candidature « Startup ».
     *
     * Les clés sont celles de `TeamSection`, pas des noms reconstitués : les
     * inventer aurait produit des libellés qui ne correspondent à rien.
     * « Équipe » et « Candidature individuelle » n'ont pas de structure —
     * `champs()` rend alors une liste vide et l'écran ne montre pas de cadre
     * juridique fantôme.
     *
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsStructure(array $r): array
    {
        return [
            ['label' => 'Dénomination', 'value' => $this->texte($r[TeamSection::STRUCTURE_NAME] ?? null)],
            ['label' => 'Sigle', 'value' => $this->texte($r[TeamSection::STRUCTURE_ACRONYM] ?? null)],
            ['label' => 'Année de création', 'value' => $this->texte($r[TeamSection::STRUCTURE_FOUNDED_YEAR] ?? null)],
            ['label' => 'Secteur d’activité', 'value' => $this->texte($r[TeamSection::STRUCTURE_SECTOR] ?? null)],
            ['label' => 'Adresse', 'value' => $this->texte($r[TeamSection::STRUCTURE_ADDRESS] ?? null)],
            ['label' => 'RCCM', 'value' => $this->texte($r[TeamSection::STRUCTURE_RCCM] ?? null)],
            ['label' => 'NIF', 'value' => $this->texte($r[TeamSection::STRUCTURE_NIF] ?? null)],
            ['label' => 'Site web', 'value' => $this->texte($r[TeamSection::STRUCTURE_WEBSITE] ?? null)],
            ['label' => 'Réseaux sociaux', 'value' => $this->texte($r[TeamSection::STRUCTURE_SOCIAL] ?? null)],
        ];
    }

    /**
     * Les membres de l'équipe, en fiches plutôt qu'en couples.
     *
     * Une candidature individuelle n'en a aucun : la liste ressort vide, et
     * l'écran n'affiche pas d'équipe fictive. Le porteur principal n'y figure
     * pas non plus — son identité vit dans le compte et dans « Profil », et le
     * répéter ici en ferait un second exemplaire à maintenir.
     *
     * @param  array<string, mixed>  $r
     * @return list<array<string, mixed>>
     */
    private function membres(array $r): array
    {
        $membres = is_array($r[TeamSection::MEMBERS] ?? null) ? array_values($r[TeamSection::MEMBERS]) : [];

        return array_values(array_map(function (mixed $membre): array {
            $membre = is_array($membre) ? $membre : [];

            return [
                'name' => $this->texte($membre[TeamSection::MEMBER_NAME] ?? null),
                'role' => $this->texte($membre[TeamSection::MEMBER_ROLE] ?? null),
                'email' => $this->texte($membre[TeamSection::MEMBER_EMAIL] ?? null),
                'phone' => $this->texte($membre[TeamSection::MEMBER_PHONE] ?? null),
                'skills' => $this->texte($membre[TeamSection::MEMBER_SKILLS] ?? null),
                'availability' => $this->texte($membre[TeamSection::MEMBER_AVAILABILITY] ?? null),
                'founder' => ($membre[TeamSection::MEMBER_IS_FOUNDER] ?? false) === true,
                // Le consentement est une exigence du §6.2 : il est montré tel
                // qu'il a été donné, jamais supposé acquis.
                'consent' => ($membre[TeamSection::MEMBER_CONSENT] ?? false) === true,
            ];
        }, $membres));
    }

    /**
     * Synthèse de l'étape 3, rendue par le domaine.
     *
     * `TeamSectionAssessment` est la règle du candidat : l'administration lui
     * demande le verdict au lieu d'en réécrire une seconde version. Elle est
     * appelée sur les réponses **déjà chargées** — `evaluer()` et non
     * `forApplication()` — pour ne pas ajouter deux requêtes à l'écran.
     *
     * L'effectif y apparaît deux fois, et c'est voulu : « déclaré » vient de
     * l'étape 1, « décrit » du nombre de membres réellement renseignés ici. Les
     * confondre masquerait justement l'écart que l'administration doit voir.
     *
     * @param  array<string, mixed>  $reponses
     * @return array<string, mixed>
     */
    private function syntheseEquipe(Application $application, array $reponses): array
    {
        return TeamSectionAssessment::evaluer($reponses, $this->reponsesEligibilite($application))->toArray();
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsDefi(array $r): array
    {
        return [
            ['label' => 'Thématique du projet', 'value' => $this->enum(ProjectTheme::class, $r[ChallengeSection::THEME_FIELD] ?? null)],
            ['label' => 'Problème principal', 'value' => $this->texte($r['main_challenge'] ?? null)],
            ['label' => 'Personnes touchées', 'value' => $this->texte($r['affected_people'] ?? null)],
            ['label' => 'Causes profondes', 'value' => $this->texte($r['root_causes'] ?? null)],
            ['label' => 'Localisation', 'value' => $this->enum(NigerRegion::class, $r[ChallengeSection::REGION_FIELD] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsSolution(array $r): array
    {
        return [
            ['label' => 'Nom de la solution', 'value' => $this->texte($r[SolutionSection::SOLUTION_NAME] ?? null)],
            ['label' => 'Proposition de valeur', 'value' => $this->texte($r[SolutionSection::VALUE_PROPOSITION] ?? null)],
            ['label' => 'Fonctionnalités principales', 'value' => $this->texte($r[SolutionSection::KEY_FEATURES] ?? null)],
            ['label' => 'Scénario d’usage', 'value' => $this->texte($r[SolutionSection::USAGE_SCENARIO] ?? null)],
            ['label' => 'Différenciation', 'value' => $this->texte($r[SolutionSection::INNOVATION] ?? null)],
            ['label' => 'Stade de maturité', 'value' => $this->enum(MaturityStage::class, $r[SolutionSection::MATURITY_STAGE] ?? null)],
            ['label' => 'État du prototype', 'value' => $this->texte($r[SolutionSection::PROTOTYPE_STATUS] ?? null)],
            ['label' => 'Technologies', 'value' => $this->texte($r[SolutionSection::TECHNOLOGIES] ?? null)],
            ['label' => 'Interopérabilité', 'value' => $this->texte($r[SolutionSection::INTEROPERABILITY] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsImpact(array $r): array
    {
        return [
            ['label' => 'Bénéficiaires', 'value' => $this->texte($r[ImpactSection::BENEFICIARIES] ?? null)],
            ['label' => 'Résultats attendus', 'value' => $this->texte($r[ImpactSection::EXPECTED_RESULTS] ?? null)],
            // Indicateurs déclarés par le candidat : ce que lui compte suivre.
            // Ce n'est ni une note ni une mesure calculée par la plateforme.
            ['label' => 'Indicateurs de suivi déclarés', 'value' => $this->texte($r[ImpactSection::IMPACT_INDICATORS] ?? null)],
            ['label' => 'Mesures d’inclusion', 'value' => $this->texte($r[ImpactSection::INCLUSION_MEASURES] ?? null)],
            ['label' => 'Contribution à la résilience', 'value' => $this->texte($r[ImpactSection::RESILIENCE_CONTRIBUTION] ?? null)],
            ['label' => 'Modèle économique', 'value' => $this->texte($r[ImpactSection::BUSINESS_MODEL] ?? null)],
            ['label' => 'Adoption et pérennité', 'value' => $this->texte($r[ImpactSection::SUSTAINABILITY] ?? null)],
            ['label' => 'Mise à l’échelle', 'value' => $this->texte($r[ImpactSection::SCALING_PLAN] ?? null)],
        ];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsPlan(array $r): array
    {
        $duree = $r[ImplementationSection::DURATION_MONTHS] ?? null;
        $budget = $r[ImplementationSection::BUDGET_AMOUNT] ?? null;

        return [
            ['label' => 'Durée du plan', 'value' => is_int($duree) ? $duree.' mois' : ''],
            ['label' => 'Activités', 'value' => $this->texte($r[ImplementationSection::ACTIVITIES] ?? null)],
            ['label' => 'Jalons', 'value' => $this->texte($r[ImplementationSection::MILESTONES] ?? null)],
            ['label' => 'Ressources', 'value' => $this->texte($r[ImplementationSection::RESOURCES] ?? null)],
            ['label' => 'Partenaires', 'value' => $this->texte($r[ImplementationSection::PARTNERS] ?? null)],
            ['label' => 'Risques et hypothèses', 'value' => $this->texte($r[ImplementationSection::RISKS] ?? null)],
            ['label' => 'Besoins d’accompagnement', 'value' => $this->texte($r[ImplementationSection::SUPPORT_NEEDS] ?? null)],
            // Un budget à zéro est une réponse : il s'affiche, là où une case
            // vide reste vide.
            ['label' => 'Budget indicatif', 'value' => is_int($budget) ? number_format($budget, 0, ',', ' ').' FCFA' : ''],
            ['label' => 'Répartition du budget', 'value' => $this->texte($r[ImplementationSection::BUDGET_BREAKDOWN] ?? null)],
        ];
    }

    /**
     * Les pièces d'un dossier, décrites sans jamais nommer leur emplacement.
     *
     * Le §8.1 range les « pièces illisibles » parmi ce que le contrôle avant
     * soumission doit signaler : l'administration doit donc pouvoir ouvrir un
     * fichier pour vérifier qu'il en est un. Cela s'arrête là — pas de
     * remplacement, pas de suppression, pas de validation documentaire. Le
     * dossier appartient au candidat tant qu'il n'est pas déposé, et après le
     * dépôt il n'appartient plus à personne.
     *
     * Ni `storage_key` ni empreinte ne sortent : l'écran reçoit de quoi lire, et
     * le téléchargement repasse par une route qui revérifie le rôle.
     *
     * @return list<array{type: string, label: string, filename: string, size: int, uploadedAt: string|null, downloadUrl: string}>
     */
    private function pieces(Application $application): array
    {
        $pieces = [];

        foreach (StoreApplicationDocument::existingFor($application) as $cle => $piece) {
            $pieces[] = [
                'type' => $cle,
                'label' => $piece->type->label(),
                'filename' => $piece->original_filename,
                'size' => (int) $piece->size,
                'uploadedAt' => $piece->created_at?->toIso8601String(),
                'downloadUrl' => route('admin.applications.documents.download', [$application, $cle]),
            ];
        }

        return $pieces;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<array{label: string, value: string}>
     */
    private function champsDeclarations(array $r): array
    {
        $champs = [];

        foreach (AttachmentsSection::fields() as $declaration) {
            $valeur = $r[$declaration] ?? null;

            // Une déclaration jamais vue et une déclaration refusée se lisent
            // toutes deux « Non » : ni l'une ni l'autre n'engage le candidat.
            $champs[] = [
                'label' => ucfirst(AttachmentsSection::label($declaration)),
                'value' => $valeur === null ? '' : $this->booleen($valeur === true),
            ];
        }

        return $champs;
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
