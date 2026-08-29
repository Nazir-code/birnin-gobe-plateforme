<?php

namespace Database\Seeders;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ImpactSection;
use App\Domain\Application\ImplementationSection;
use App\Domain\Application\MaturityStage;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\SolutionSection;
use App\Domain\Application\TeamSection;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\Gender;
use App\Domain\Candidate\PreferredChannel;
use App\Domain\Evaluation\AcceptEvaluationCharter;
use App\Domain\Evaluation\AssignApplications;
use App\Domain\Evaluation\DivergenceReviewOutcome;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Evaluation\LockEvaluation;
use App\Domain\Evaluation\RecordDivergenceReview;
use App\Domain\Evaluation\SaveEvaluationDraft;
use App\Domain\Evaluation\SaveEvaluationSettings;
use App\Domain\Evaluation\ScoreSheet;
use App\Domain\Reference\NigerRegion;
use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\DecideAdmissibility;
use App\Domain\Verification\SaveVerificationChecks;
use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationOutcome;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Un jeu de démonstration pour parcourir le back-office — §10 à §11.3.
 *
 * **Ce semis ne fait pas partie du semis par défaut, et ne doit jamais y
 * entrer.** `DatabaseSeeder` ne crée aucun compte ni aucune candidature ;
 * celui-ci en crée une quinzaine, avec des noms et des projets inventés. Il
 * s'appelle explicitement :
 *
 *     php artisan db:seed --class=DemonstrationSeeder
 *
 * Il **refuse de s'exécuter hors d'un environnement local**. Des candidats
 * fictifs en production ne sont pas une gêne, ce sont des dossiers qu'un jury
 * pourrait classer ; et rien dans le produit ne les distinguerait des vrais.
 *
 * ## Ce qui passe par les vrais cas d'usage, et ce qui n'y passe pas
 *
 * Les transitions subtiles — grille du §10.2, décision d'admissibilité,
 * affectation, charte, notation, verrouillage, revue d'écart — sont jouées par
 * leurs cas d'usage réels. C'est le point de ce fichier : une insertion directe
 * produirait des états que l'application ne sait pas produire, et les écrans se
 * comporteraient alors bizarrement pour des raisons qui n'existent pas. Les
 * événements d'audit sont écrits au passage, ce qui donne un journal réaliste
 * sans effort.
 *
 * **Le dépôt, lui, est simulé.** `SubmitApplication` exige des pièces jointes
 * réellement téléversées et analysées par l'antivirus : les fabriquer
 * demanderait de poser des fichiers sur le disque S3 et de faire tourner
 * ClamAV, pour un décor. Le statut, le numéro de dépôt et la date sont donc
 * posés en fixture. C'est la seule entorse, et elle est visible : les dossiers
 * de démonstration n'ont pas de pièces, et l'écran de contrôle le dira.
 *
 * ## Ce que le jeu contient
 *
 * De quoi peupler les dix écrans : des dossiers à chaque étape du parcours, une
 * file de vérification non vide, des dossiers recevables à affecter, des
 * notations en cours et verrouillées, et — c'est le plus difficile à obtenir à
 * la main — **deux dossiers dont les évaluateurs divergent**, dont un déjà
 * arbitré.
 */
final class DemonstrationSeeder extends Seeder
{
    /** Le nombre minimal d'évaluations et le seuil d'écart de la campagne de démonstration. */
    private const MIN_EVALUATIONS = 2;

    private const SCORE_GAP = 2.0;

    /** Le même pour tous les comptes de démonstration : c'est un décor local. */
    private const MOT_DE_PASSE = 'motdepasse';

    private int $numero = 0;

    private Campaign $campagne;

    private User $verificateur;

    /** @var array<string, User> */
    private array $evaluateurs = [];

    public function run(): void
    {
        // Le garde-fou d'abord : tout le reste écrit en base.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'DemonstrationSeeder crée des candidats fictifs : il ne doit tourner qu’en local.'
            );
        }

        $this->nettoyer();

        $this->campagne = $this->campagne();
        $this->verificateur = $this->interne('Aïcha Diallo', 'demo.verification@birningobe.test', UserRole::ADMIN);

        foreach (['Mouhamadou Kane', 'Fatouma Issa', 'Ibrahim Sani'] as $nom) {
            $this->evaluateurs[$nom] = $this->interne(
                $nom,
                'demo.'.str_replace(' ', '.', mb_strtolower($nom)).'@birningobe.test',
                UserRole::EVALUATOR,
            );
        }

        $this->reglerLEvaluation();

        $this->brouillons();
        $this->fileDeVerification();
        $this->clarificationDemandee();
        $this->inadmissible();
        $this->recevablesNonAffectes();
        $this->enCoursDEvaluation();
        $this->divergenceNonArbitree();
        $this->divergenceArbitree();

        $this->command?->info('Jeu de démonstration créé. Tous les comptes : mot de passe « '.self::MOT_DE_PASSE.' ».');
    }

    // — Le décor ————————————————————————————————————————————————

    /**
     * Efface le jeu précédent, pour que ce semis soit rejouable.
     *
     * Un semis de démonstration qu'on ne peut lancer qu'une fois n'en est pas
     * un : on l'ajuste, on le relance, on regarde. Sans ce nettoyage, la
     * deuxième exécution bute sur l'unique `(campaign_id, candidate_id)`, à
     * mi-parcours, en laissant la base dans un état bâtard.
     *
     * **La sélection est stricte** : le domaine `@birningobe.test`, qu'aucun
     * compte réel ne peut porter — c'est un TLD réservé aux tests par la
     * RFC 2606. Un filtre plus large, sur le préfixe `demo.` par exemple,
     * finirait un jour par emporter le compte de quelqu'un.
     *
     * Les événements d'audit des acteurs de démonstration partent aussi.
     * `actor_id` n'a pas de clé étrangère — c'est voulu, un compte supprimé ne
     * doit pas effacer sa trace — donc rien ne les emporterait, et le journal
     * accumulerait les décisions de comptes qui n'existent plus à chaque
     * relance.
     */
    private function nettoyer(): void
    {
        $comptes = User::query()->where('email', 'like', '%@birningobe.test')->pluck('id');

        if ($comptes->isEmpty()) {
            return;
        }

        // L'ordre suit les dépendances : le journal, puis les dossiers (dont
        // les cascades emportent grilles, décisions, affectations, notations),
        // puis les comptes.
        AuditEvent::query()->whereIn('actor_id', $comptes)->delete();
        Application::query()->whereIn('candidate_id', $comptes)->delete();
        User::query()->whereIn('id', $comptes)->delete();

        $this->command?->info('Jeu de démonstration précédent effacé.');
    }

    private function campagne(): Campaign
    {
        $campagne = Campaign::query()->where('status', CampaignStatus::OPEN->value)->first();

        if ($campagne !== null) {
            return $campagne;
        }

        // `CampaignSeeder` n'a pas tourné : on ne le contourne pas, on l'appelle.
        $this->call(CampaignSeeder::class);

        return Campaign::query()->where('status', CampaignStatus::OPEN->value)->firstOrFail();
    }

    /**
     * Les réglages d'évaluation, posés par leur cas d'usage.
     *
     * Sans eux, la couverture reste inconnue et l'écran des écarts ne compare
     * rien — ce qui est le bon comportement, mais ne montre pas grand-chose.
     * Les fixer ici est un choix de **décor**, pas une valeur par défaut : le
     * produit continue de n'en inventer aucune.
     */
    private function reglerLEvaluation(): void
    {
        app(SaveEvaluationSettings::class)->handle(
            $this->verificateur,
            $this->campagne,
            EvaluationSettings::make(self::MIN_EVALUATIONS, self::SCORE_GAP),
        );

        $this->campagne->refresh();
    }

    private function interne(string $nom, string $email, UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            // En clair : `User` caste `password` en `hashed`, et lui passer un
            // `bcrypt()` le ferait re-hacher un hachage — d'où le refus
            // « Could not verify the hashed value's configuration ».
            ['name' => $nom, 'password' => self::MOT_DE_PASSE, 'role' => $role->value],
        );
    }

    // — Les dossiers ————————————————————————————————————————————

    /** Des candidatures en cours de saisie : le tableau de bord candidat a de quoi montrer. */
    private function brouillons(): void
    {
        foreach ([['Hadiza Moussa', 2], ['Souleymane Adamou', 5]] as [$nom, $sections]) {
            $this->dossier($nom, ApplicationStatus::DRAFT, sectionsRemplies: $sections);
        }
    }

    /** La file du §10 : des dossiers déposés que personne n'a encore ouverts. */
    private function fileDeVerification(): void
    {
        foreach ([
            ['Rakia Boubacar', 12, ProjectTheme::URBAN_MANAGEMENT],
            ['Abdoul Aziz Garba', 8, ProjectTheme::LAND_REGISTRY],
            ['Mariama Oumarou', 3, ProjectTheme::CIVIL_REGISTRY],
        ] as [$nom, $jours, $theme]) {
            $this->dossier($nom, ApplicationStatus::SUBMITTED, $theme, deposeIlYA: $jours);
        }
    }

    /** Un dossier dont le vérificateur a demandé un complément (§10.3). */
    private function clarificationDemandee(): void
    {
        $dossier = $this->dossier('Zeinabou Harouna', ApplicationStatus::SUBMITTED, ProjectTheme::MAPPING_RESILIENCE, deposeIlYA: 15);

        $this->remplirLaGrille($dossier, [
            VerificationControl::COMPLETENESS->value => [
                'outcome' => VerificationOutcome::FILE_CLARIFICATION,
                'observation' => 'Le budget prévisionnel est annoncé en annexe mais ne figure pas au dossier.',
            ],
        ]);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::CLARIFICATION,
            actor: $this->verificateur,
            primaryReason: null,
            secondaryReason: null,
            internalNote: 'Relancer sous huit jours ; le reste du dossier est solide.',
            candidateMessage: 'Votre dossier est en cours d’examen. Merci de nous transmettre le budget prévisionnel détaillé, annoncé en annexe mais absent du dépôt.',
            respondBy: now()->addDays(8)->toDateString(),
        );
    }

    /** Un dossier écarté, avec son motif codifié (§10.3). */
    private function inadmissible(): void
    {
        $dossier = $this->dossier('Ousmane Idrissa', ApplicationStatus::SUBMITTED, ProjectTheme::URBAN_MANAGEMENT, deposeIlYA: 20);

        $this->remplirLaGrille($dossier, [
            VerificationControl::PROFILE->value => [
                'outcome' => VerificationOutcome::PROFILE_INELIGIBLE,
                'observation' => 'Date de naissance déclarée hors de la tranche 18–35 ans de l’édition.',
            ],
        ]);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::INADMISSIBLE,
            actor: $this->verificateur,
            primaryReason: VerificationControl::PROFILE,
            secondaryReason: null,
            internalNote: 'Pièce d’identité cohérente avec la déclaration : pas de suspicion de fraude.',
            candidateMessage: 'Votre candidature ne satisfait pas la condition d’âge de l’édition 2026. Cette décision ne préjuge pas de vos prochaines candidatures.',
            respondBy: null,
        );
    }

    /** Des dossiers recevables qu'aucun évaluateur ne porte encore : l'écran du §11.1 a du travail. */
    private function recevablesNonAffectes(): void
    {
        foreach ([
            ['Salamatou Yacouba', ProjectTheme::CIVIL_REGISTRY],
            ['Chaibou Maazou', ProjectTheme::MAPPING_RESILIENCE],
        ] as [$nom, $theme]) {
            $this->dossierRecevable($nom, $theme, deposeIlYA: 25);
        }
    }

    /** Un dossier affecté à deux évaluateurs, dont un seul a verrouillé. */
    private function enCoursDEvaluation(): void
    {
        $dossier = $this->dossierRecevable('Nafissatou Amadou', ProjectTheme::LAND_REGISTRY, deposeIlYA: 30);

        $premier = $this->affecter($dossier, 'Mouhamadou Kane');
        $this->affecter($dossier, 'Fatouma Issa');

        $this->noter($premier, 'Mouhamadou Kane', [
            EvaluationCriterion::RELEVANCE->value => 4,
            EvaluationCriterion::INNOVATION->value => 3,
            EvaluationCriterion::TECHNICAL_FEASIBILITY->value => 4,
            EvaluationCriterion::VIABILITY->value => 3,
            EvaluationCriterion::IMPACT->value => 4,
            EvaluationCriterion::SUSTAINABILITY->value => 3,
            EvaluationCriterion::TEAM->value => 4,
            EvaluationCriterion::INCLUSION->value => 3,
        ], EvaluationRecommendation::SHORTLIST, 'Dossier solide et bien documenté, porté par une équipe complémentaire. À retenir pour la suite.');
    }

    /**
     * Deux évaluateurs qui divergent, sans arbitrage : le cœur de l'écran du §11.3.
     *
     * L'écart porte sur la faisabilité technique — 1 contre 5 — ce qui est le
     * cas intéressant : deux personnes n'ont pas lu le même dossier, et c'est
     * nommable. Un écart réparti sur huit critères ne dirait rien.
     */
    private function divergenceNonArbitree(): void
    {
        $dossier = $this->dossierRecevable('Ramatou Seyni', ProjectTheme::URBAN_MANAGEMENT, deposeIlYA: 35);

        $indulgent = $this->affecter($dossier, 'Mouhamadou Kane');
        $severe = $this->affecter($dossier, 'Fatouma Issa');

        $this->noter($indulgent, 'Mouhamadou Kane', [
            EvaluationCriterion::TECHNICAL_FEASIBILITY->value => 5,
            EvaluationCriterion::INNOVATION->value => 4,
        ], EvaluationRecommendation::SHORTLIST, 'Architecture convaincante, la démonstration tourne réellement hors connexion.', [
            EvaluationCriterion::TECHNICAL_FEASIBILITY->value => 'Prototype fonctionnel présenté, synchronisation différée déjà éprouvée sur deux communes.',
        ]);

        $this->noter($severe, 'Fatouma Issa', [
            EvaluationCriterion::TECHNICAL_FEASIBILITY->value => 1,
            EvaluationCriterion::INNOVATION->value => 2,
        ], EvaluationRecommendation::REJECT, 'Aucune preuve de robustesse en conditions réelles ; les dépendances externes ne sont pas maîtrisées.', [
            EvaluationCriterion::TECHNICAL_FEASIBILITY->value => 'Le dossier décrit une intention d’architecture, pas une architecture. Rien sur la sécurité ni la reprise après panne.',
        ]);
    }

    /** Le même désaccord, déjà arbitré : l'historique de revue a de quoi montrer. */
    private function divergenceArbitree(): void
    {
        $dossier = $this->dossierRecevable('Boubacar Alzouma', ProjectTheme::CIVIL_REGISTRY, deposeIlYA: 40);

        $premier = $this->affecter($dossier, 'Fatouma Issa');
        $second = $this->affecter($dossier, 'Ibrahim Sani');

        $this->noter($premier, 'Fatouma Issa', [
            EvaluationCriterion::IMPACT->value => 5,
        ], EvaluationRecommendation::SHORTLIST, 'Impact mesurable et déjà documenté sur un terrain difficile.', [
            EvaluationCriterion::IMPACT->value => 'Quatre mille actes d’état civil régularisés en douze mois, chiffre vérifiable auprès de la commune.',
        ]);

        $this->noter($second, 'Ibrahim Sani', [
            EvaluationCriterion::IMPACT->value => 2,
        ], EvaluationRecommendation::RESERVE, 'Les résultats annoncés reposent sur une seule commune pilote ; la généralisation reste à démontrer.');

        app(RecordDivergenceReview::class)->handle(
            dossier: $dossier->refresh(),
            issue: DivergenceReviewOutcome::DIVERGENCE_ACCEPTED,
            motif: 'Les deux lectures sont défendables : l’une juge le résultat obtenu, l’autre sa reproductibilité. Le comité tranchera sur pièces, sans troisième avis.',
            actor: $this->verificateur,
        );
    }

    // — Les briques ————————————————————————————————————————————

    /** Un dossier déclaré recevable par le vrai cas d'usage. */
    private function dossierRecevable(string $nom, ProjectTheme $theme, int $deposeIlYA): Application
    {
        $dossier = $this->dossier($nom, ApplicationStatus::SUBMITTED, $theme, deposeIlYA: $deposeIlYA);

        $this->remplirLaGrille($dossier);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::ADMISSIBLE,
            actor: $this->verificateur,
            primaryReason: null,
            secondaryReason: null,
            internalNote: null,
            candidateMessage: null,
            respondBy: null,
        );

        return $dossier->refresh();
    }

    /**
     * La grille du §10.2, conforme par défaut.
     *
     * `outcomes()[0]` est le verdict conforme de chaque contrôle — c'est la
     * convention de `VerificationControl`, et la lire plutôt que d'écrire sept
     * constantes évite qu'un ajout de contrôle laisse ce semis en arrière.
     *
     * @param  array<string, array{outcome: VerificationOutcome, observation: ?string}>  $exceptions
     */
    private function remplirLaGrille(Application $dossier, array $exceptions = []): void
    {
        $grille = [];

        foreach (VerificationControl::cases() as $controle) {
            $grille[$controle->value] = $exceptions[$controle->value] ?? [
                'outcome' => $controle->outcomes()[0],
                'observation' => null,
            ];
        }

        app(SaveVerificationChecks::class)->handle($dossier, $grille, $this->verificateur);
    }

    private function affecter(Application $dossier, string $evaluateur): EvaluationAssignment
    {
        app(AssignApplications::class)->handle(
            [$dossier->getKey()],
            $this->evaluateurs[$evaluateur],
            $this->verificateur,
        );

        return EvaluationAssignment::query()
            ->where('application_id', $dossier->getKey())
            ->where('evaluator_id', $this->evaluateurs[$evaluateur]->getKey())
            ->enVigueur()
            ->firstOrFail();
    }

    /**
     * Une notation complète et verrouillée, par les vrais cas d'usage.
     *
     * Les critères non cités valent 3 — « satisfaisant » : c'est la note qui ne
     * dit rien de particulier, donc celle qui laisse voir les écarts qu'on a
     * voulu créer.
     *
     * @param  array<string, int>  $notes
     * @param  array<string, string>  $justifications
     */
    private function noter(
        EvaluationAssignment $affectation,
        string $evaluateur,
        array $notes,
        EvaluationRecommendation $recommandation,
        string $commentaire,
        array $justifications = [],
    ): void {
        $qui = $this->evaluateurs[$evaluateur];

        $evaluation = app(AcceptEvaluationCharter::class)->handle($affectation, $qui);

        $lignes = [];

        foreach (EvaluationCriterion::cases() as $critere) {
            $note = $notes[$critere->value] ?? 3;
            $justification = $justifications[$critere->value] ?? null;

            // Le §11.3 exige une justification sur 0 et 5 : le semis ne
            // contourne pas la règle, il la respecte comme un évaluateur.
            if ($justification === null && in_array($note, [0, EvaluationCriterion::MAX_SCORE], true)) {
                $justification = 'Note extrême assumée, argumentée en séance de calibrage.';
            }

            $lignes[$critere->value] = ['score' => $note, 'comment' => $justification];
        }

        app(SaveEvaluationDraft::class)->handle(
            evaluation: $evaluation,
            evaluateur: $qui,
            feuille: ScoreSheet::make($lignes),
            recommandation: $recommandation,
            commentaire: $commentaire,
        );

        app(LockEvaluation::class)->handle($evaluation->refresh(), $qui);
    }

    /**
     * Un dossier et son candidat.
     *
     * Le dépôt est posé en fixture — statut, numéro, date — parce que
     * `SubmitApplication` exige des pièces réellement téléversées et analysées.
     * C'est la seule entorse de ce semis, et elle est assumée.
     */
    private function dossier(
        string $nom,
        ApplicationStatus $statut,
        ?ProjectTheme $theme = null,
        int $deposeIlYA = 0,
        ?int $sectionsRemplies = null,
    ): Application {
        $candidat = $this->interne($nom, $this->adresse($nom), UserRole::CANDIDATE);

        $sections = $this->reponses($nom, $theme ?? ProjectTheme::URBAN_MANAGEMENT);

        if ($sectionsRemplies !== null) {
            $sections = array_slice($sections, 0, $sectionsRemplies, preserve_keys: true);
        }

        $fabrique = Application::factory()->for($this->campagne)->for($candidat, 'candidate');

        foreach ($sections as $cle => $reponses) {
            $fabrique = $fabrique->withSection(ApplicationSection::from($cle), $reponses);
        }

        $dossier = $fabrique->create();

        if ($statut !== ApplicationStatus::DRAFT) {
            $dossier->forceFill([
                'status' => $statut->value,
                'submission_number' => sprintf('BG-2026-%03d', ++$this->numero),
                'submitted_at' => now()->subDays($deposeIlYA),
            ])->save();
        }

        return $dossier->refresh();
    }

    private function adresse(string $nom): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom;

        return 'demo.'.str_replace([' ', "'"], ['.', ''], mb_strtolower($translitere)).'@birningobe.test';
    }

    /**
     * Les réponses des sept sections de contenu, réalistes et variées.
     *
     * Écrites en clair plutôt que tirées au hasard : c'est un décor qu'un
     * humain va lire écran par écran, et « Réponse de l'étape 5 » ne permet ni
     * de juger une mise en page ni de repérer un champ mal étiqueté.
     *
     * @return array<string, array<string, mixed>>
     */
    private function reponses(string $nom, ProjectTheme $theme): array
    {
        $prenom = explode(' ', $nom)[0];
        $regions = NigerRegion::cases();
        $region = $regions[abs(crc32($nom)) % count($regions)];

        return [
            ApplicationSection::ELIGIBILITY->value => [
                EligibilitySection::BIRTH_DATE => now()->subYears(22 + abs(crc32($nom)) % 12)->toDateString(),
                EligibilitySection::NIGERIEN_NATIONAL => true,
                EligibilitySection::RESIDES_IN_NIGER => true,
                EligibilitySection::INTERVENTION_REGION => $region->value,
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 3,
            ],
            ApplicationSection::PROFILE->value => [
                ProfileSection::BIRTH_PLACE => $region->label(),
                ProfileSection::GENDER => (abs(crc32($nom)) % 2 === 0 ? Gender::FEMALE : Gender::MALE)->value,
                ProfileSection::PHONE_PRIMARY => '+227 90 '.str_pad((string) (abs(crc32($nom)) % 100), 2, '0', STR_PAD_LEFT).' '.str_pad((string) (abs(crc32($nom.'b')) % 100), 2, '0', STR_PAD_LEFT).' '.str_pad((string) (abs(crc32($nom.'c')) % 100), 2, '0', STR_PAD_LEFT),
                ProfileSection::PREFERRED_CHANNEL => PreferredChannel::EMAIL->value,
                ProfileSection::RESIDENCE_REGION => $region->value,
                ProfileSection::RESIDENCE_LOCALITY => 'Quartier administratif',
                ProfileSection::OCCUPATION => 'Développeuse et formatrice en outils numériques municipaux',
                ProfileSection::EDUCATION_LEVEL => EducationLevel::MASTER->value,
                ProfileSection::SPECIALTY => 'Systèmes d’information géographique',
            ],
            ApplicationSection::TEAM->value => [
                TeamSection::STRUCTURE_NAME => $prenom.' Tech Collectif',
                TeamSection::STRUCTURE_FOUNDED_YEAR => 2022,
                TeamSection::STRUCTURE_SECTOR => 'Services numériques aux collectivités',
                TeamSection::STRUCTURE_ADDRESS => $region->label().', Niger',
                TeamSection::MEMBERS => [
                    [
                        TeamSection::MEMBER_NAME => $nom,
                        TeamSection::MEMBER_ROLE => 'Coordination et développement',
                        TeamSection::MEMBER_EMAIL => $this->adresse($nom),
                        TeamSection::MEMBER_PHONE => '+227 90 00 00 01',
                        TeamSection::MEMBER_SKILLS => 'Conception logicielle, SIG, formation des agents',
                        TeamSection::MEMBER_AVAILABILITY => 'Temps plein',
                        TeamSection::MEMBER_IS_FOUNDER => true,
                        TeamSection::MEMBER_CONSENT => true,
                    ],
                    [
                        TeamSection::MEMBER_NAME => 'Amina Sadou',
                        TeamSection::MEMBER_ROLE => 'Relations avec les communes',
                        TeamSection::MEMBER_EMAIL => 'demo.amina.sadou@birningobe.test',
                        TeamSection::MEMBER_PHONE => '+227 90 00 00 02',
                        TeamSection::MEMBER_SKILLS => 'Administration territoriale, conduite du changement',
                        TeamSection::MEMBER_AVAILABILITY => 'Mi-temps',
                        TeamSection::MEMBER_IS_FOUNDER => false,
                        TeamSection::MEMBER_CONSENT => true,
                    ],
                ],
            ],
            ApplicationSection::CHALLENGE->value => [
                ChallengeSection::THEME_FIELD => $theme->value,
                ChallengeSection::REGION_FIELD => $region->value,
                'main_challenge' => 'Les services municipaux de '.$region->label().' tiennent leurs registres sur papier : une demande d’acte prend en moyenne onze jours, et les doublons ne sont détectés qu’au guichet.',
                'affected_people' => 'Environ 60 000 habitants de la commune, et en premier lieu les femmes en zone périurbaine, qui perdent une journée de travail à chaque démarche.',
                'root_causes' => 'Absence de registre numérique commun, connexion internet intermittente, et rotation des agents formés.',
            ],
            ApplicationSection::SOLUTION->value => [
                SolutionSection::SOLUTION_NAME => $prenom.'Registre',
                SolutionSection::VALUE_PROPOSITION => 'Un registre municipal qui fonctionne hors connexion et se synchronise quand le réseau revient, pour ramener la délivrance d’un acte à moins d’une heure.',
                SolutionSection::KEY_FEATURES => 'Saisie hors ligne, détection de doublons à la saisie, impression d’actes, journal des consultations, export vers le système national.',
                SolutionSection::USAGE_SCENARIO => 'L’agent saisit la demande au guichet, même sans réseau ; l’acte est imprimé immédiatement et la synchronisation part la nuit.',
                SolutionSection::INNOVATION => 'La synchronisation différée avec résolution de conflits, pensée pour des communes qui n’ont du réseau que quelques heures par jour.',
                SolutionSection::MATURITY_STAGE => MaturityStage::PILOT->value,
                SolutionSection::TECHNOLOGIES => 'Application web progressive, base locale chiffrée, synchronisation par file d’attente.',
                SolutionSection::INTEROPERABILITY => 'Export au format du système national d’état civil ; aucune donnée ne quitte la commune sans journalisation.',
            ],
            ApplicationSection::IMPACT->value => [
                ImpactSection::BENEFICIARIES => '60 000 habitants, 14 agents municipaux, 3 arrondissements.',
                ImpactSection::EXPECTED_RESULTS => 'Délai de délivrance ramené de onze jours à moins d’une heure ; doublons détectés à la saisie plutôt qu’au guichet.',
                ImpactSection::IMPACT_INDICATORS => 'Délai médian de délivrance, nombre de doublons évités, taux de synchronisation réussie, satisfaction des usagers.',
                ImpactSection::INCLUSION_MEASURES => 'Interface en français et en haoussa, parcours utilisable par un agent peu à l’aise avec l’écrit, guichet mobile pour les villages éloignés.',
                ImpactSection::RESILIENCE_CONTRIBUTION => 'Le registre reste consultable en cas de coupure prolongée : les données vivent d’abord sur le poste de la commune.',
                ImpactSection::BUSINESS_MODEL => 'Licence annuelle par commune, dégressive selon la population, avec maintenance incluse.',
                ImpactSection::SUSTAINABILITY => 'Deux agents formés par commune deviennent référents et forment les suivants.',
                ImpactSection::SCALING_PLAN => 'Trois communes la première année, quinze la deuxième, en s’appuyant sur les associations de maires.',
            ],
            ApplicationSection::IMPLEMENTATION->value => [
                ImplementationSection::DURATION_MONTHS => 12,
                ImplementationSection::ACTIVITIES => 'Cadrage avec la commune, reprise des registres papier, déploiement, formation des agents, accompagnement sur six mois.',
                ImplementationSection::MILESTONES => 'M2 : reprise des registres achevée. M4 : mise en service. M8 : deuxième commune. M12 : bilan et transfert.',
                ImplementationSection::RESOURCES => 'Deux développeurs, une chargée de relation avec les communes, un poste par guichet.',
                ImplementationSection::PARTNERS => 'Commune pilote, association des maires, école de formation des agents territoriaux.',
                ImplementationSection::RISKS => 'Rotation des agents formés — atténuée par deux référents par commune ; qualité des registres papier — atténuée par une phase de reprise contrôlée.',
                ImplementationSection::SUPPORT_NEEDS => 'Mise en relation institutionnelle avec les communes, et validation du format d’export national.',
                ImplementationSection::BUDGET_AMOUNT => 18_500_000,
                ImplementationSection::BUDGET_BREAKDOWN => 'Développement 8,5 M FCFA · Équipement 4 M · Formation 3,5 M · Suivi 2,5 M.',
            ],
        ];
    }
}
