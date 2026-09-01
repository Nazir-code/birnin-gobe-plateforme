<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Application\AttachmentsSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\DocumentType;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Application\SubmissionBlocker;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Application\SubmissionSnapshot;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\Gender;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Étape 8 — Pièces / déclarations.
 *
 * Trois choses distinctes s'y vérifient, et il ne faut pas les confondre :
 *
 *   les **déclarations** suivent le chemin des sept sections précédentes, et
 *     c'est la valeur en base qui engage — pas la case cochée ;
 *   les **pièces** sont des fichiers : elles ont leur propre stockage, leur
 *     propre validation et leurs propres règles de remplacement ;
 *   la **recevabilité** est le vrai enjeu de cette phase. C'est l'étape 8 qui
 *     fait passer un dossier de « non déposable » à « déposable », et
 *     `SubmissionReadiness` n'a pas eu à changer d'une ligne pour cela.
 *
 * Le disque est simulé (`Storage::fake`) : ce qui est vérifié est que le fichier
 * arrive au bon endroit sur le disque des pièces, pas que ce disque soit un
 * disque particulier.
 */
final class PiecesDeclarationsCandidatTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $fichiersTemporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $chemin) {
            @unlink($chemin);
        }

        $this->fichiersTemporaires = [];

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StoreApplicationDocument::diskName());
    }

    private function candidat(): User
    {
        return User::factory()->create();
    }

    private function campagne(): Campaign
    {
        return Campaign::factory()->create();
    }

    private function url(Application $application): string
    {
        return "/candidate/application/{$application->getKey()}/attachments";
    }

    private function urlPiece(Application $application, DocumentType $type): string
    {
        return $this->url($application)."/documents/{$type->value}";
    }

    /**
     * Ouvre un brouillon dont l'étape 1 est répondue : le type de candidature y
     * vit, et c'est lui qui décide des pièces et déclarations exigées.
     */
    private function brouillon(User $candidat, Campaign $campagne, CandidateType $type = CandidateType::INDIVIDUAL): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $application = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();

        $this->actingAs($candidat)->patchJson(
            "/candidate/application/{$application->getKey()}/eligibility",
            $this->eligibilite($type),
        )->assertOk();

        return $application;
    }

    /** @return array<string, mixed> */
    private function eligibilite(CandidateType $type = CandidateType::INDIVIDUAL): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(28)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => $type->value,
            EligibilitySection::TEAM_SIZE => $type->isCollective() ? 3 : null,
        ];
    }

    /**
     * Déclarations acceptées.
     *
     * @return array<string, bool>
     */
    private function declarations(bool $toutes = true): array
    {
        $valeurs = [];

        foreach (AttachmentsSection::fields() as $champ) {
            $valeurs[$champ] = $toutes;
        }

        // Le consentement à la communication publique est facultatif : le
        // laisser à `false` doit rester sans conséquence.
        $valeurs[AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT] = false;

        return $valeurs;
    }

    private function pdf(string $nom = 'presentation.pdf', int $kilooctets = 40): UploadedFile
    {
        return $this->fichier($nom, '%PDF-1.4'.PHP_EOL.str_repeat('a', $kilooctets * 1024));
    }

    /** Un PNG valide de 1x1, construit sans GD — absent de l'image de test comme de la CI. */
    private function png(string $nom = 'rccm.png'): UploadedFile
    {
        return $this->fichier($nom, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));
    }

    /**
     * Un vrai fichier téléversé, écrit sur disque.
     *
     * `UploadedFile::fake()` ne convient pas ici : son type MIME est déduit du
     * nom, si bien qu'un test bâti dessus ne pourrait pas distinguer « le
     * serveur lit le contenu » de « le serveur croit l'extension » — c'est-à-dire
     * exactement ce qu'il doit prouver. Un fichier réel force `getMimeType()` à
     * passer par fileinfo, comme pour un envoi de navigateur.
     *
     * `$mimeAnnonce` est ce que le client prétend, et il est délibérément
     * mensonger dans le test du fichier maquillé.
     */
    private function fichier(string $nom, string $contenu, ?string $mimeAnnonce = null): UploadedFile
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'bg-piece-');
        file_put_contents($chemin, $contenu);
        $this->fichiersTemporaires[] = $chemin;

        return new UploadedFile($chemin, $nom, $mimeAnnonce, null, true);
    }

    /** Dépose les pièces exigées pour ce type de candidature. */
    private function deposerLesPiecesExigees(User $candidat, Application $application, CandidateType $type = CandidateType::INDIVIDUAL): void
    {
        foreach (DocumentType::requiredFor($type) as $piece) {
            $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
                'type' => $piece->value,
                'document' => $this->pdf($piece->value.'.pdf'),
            ])->assertOk();
        }
    }

    // — L'étape entre dans le parcours ————————————————————————————

    public function test_l_etape_8_est_developpee_et_sur_le_parcours_ouvert(): void
    {
        $this->assertSame(8, ApplicationSection::ATTACHMENTS->position());
        $this->assertTrue(ApplicationSection::ATTACHMENTS->isImplemented());
        $this->assertTrue(ApplicationSection::ATTACHMENTS->isOnOpenPath());

        $this->assertSame(ApplicationSection::ATTACHMENTS, ApplicationSection::IMPLEMENTATION->nextOnOpenPath());
        $this->assertSame(ApplicationSection::IMPLEMENTATION, ApplicationSection::ATTACHMENTS->previousImplemented());

        // « Relecture / envoi » est livrée : le parcours continue jusqu'à elle.
        // Voir RelectureOuverteTest, qui fixe ce raccord et ce qu'il ne change
        // pas — ni la progression, ni la recevabilité.
        $this->assertSame(ApplicationSection::REVIEW, ApplicationSection::ATTACHMENTS->nextOnOpenPath());
        $this->assertTrue(ApplicationSection::REVIEW->isImplemented());
    }

    public function test_le_parcours_ouvert_compte_neuf_etapes_dans_l_ordre(): void
    {
        $this->assertSame(
            [
                ApplicationSection::ELIGIBILITY,
                ApplicationSection::PROFILE,
                ApplicationSection::TEAM,
                ApplicationSection::CHALLENGE,
                ApplicationSection::SOLUTION,
                ApplicationSection::IMPACT,
                ApplicationSection::IMPLEMENTATION,
                ApplicationSection::ATTACHMENTS,
                ApplicationSection::REVIEW,
            ],
            ApplicationSection::openPath(),
        );
    }

    public function test_l_etape_8_s_atteint_depuis_l_etape_7(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->get("/candidate/application/{$id}/implementation")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nextUrl', url($this->url($application))));

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Attachments')
                ->where('previousUrl', url("/candidate/application/{$id}/implementation"))
                // « Suivant » mène à la relecture. Le dépôt, lui, ne se fait
                // toujours pas ici : c'est l'étape 9 qui porte le bouton.
                ->where('nextUrl', url("/candidate/application/{$id}/review"))
                ->where('section.position', 8));
    }

    // — Ce que la source exige ——————————————————————————————————————

    public function test_les_six_pieces_du_cahier_sont_proposees(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('documentTypes', count(DocumentType::cases()))
                ->has('answers', count(AttachmentsSection::fields())));
    }

    /**
     * Une seule pièce est exigée sans condition, et c'est celle que le §7.2
     * marque « Obligatoire ». Les autres dépendent du type ou restent ouvertes.
     */
    public function test_un_porteur_individuel_ne_doit_qu_une_piece(): void
    {
        $this->assertSame(
            [DocumentType::PROJECT_PRESENTATION],
            DocumentType::requiredFor(CandidateType::INDIVIDUAL),
        );
    }

    public function test_une_equipe_doit_aussi_les_cv(): void
    {
        // « Obligatoire selon type » : des CV de membres supposent des membres.
        $this->assertSame(
            [DocumentType::PROJECT_PRESENTATION, DocumentType::KEY_MEMBER_CV],
            DocumentType::requiredFor(CandidateType::TEAM),
        );
    }

    public function test_une_startup_doit_en_outre_son_existence_legale(): void
    {
        // « Conditionnel » : le RCCM et le NIF n'ont de sens qu'avec une structure.
        $this->assertSame(
            [DocumentType::PROJECT_PRESENTATION, DocumentType::KEY_MEMBER_CV, DocumentType::LEGAL_EXISTENCE],
            DocumentType::requiredFor(CandidateType::STARTUP),
        );
    }

    /** @return array<string, array{DocumentType}> */
    public static function piecesNonExigees(): array
    {
        return [
            'budget — « Configurable »' => [DocumentType::BUDGET_PLAN],
            'prototype — « selon phase »' => [DocumentType::PROTOTYPE_DEMO],
            'lettres — « Conditionnel selon cas »' => [DocumentType::LETTERS_AUTHORISATIONS],
        ];
    }

    /**
     * Ce que la source laisse ouvert reste ouvert.
     *
     * Rendre obligatoire une pièce que le cahier dit « configurable » ou
     * « selon phase » reviendrait à arbitrer à la place du comité, et à fermer
     * le dépôt à des candidats que le règlement autorise.
     */
    #[DataProvider('piecesNonExigees')]
    public function test_une_piece_laissee_ouverte_par_la_source_n_est_exigee_d_aucun_type(DocumentType $piece): void
    {
        foreach (CandidateType::cases() as $type) {
            $this->assertFalse($piece->isRequiredFor($type));
        }
    }

    public function test_le_consentement_a_la_communication_publique_est_facultatif(): void
    {
        // §7.3 : « consentement distinct […] et, le cas échéant, pour la
        // communication publique ». Un consentement exigé n'en est pas un.
        foreach (CandidateType::cases() as $type) {
            $this->assertNotContains(
                AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT,
                AttachmentsSection::requiredFor($type),
            );
        }
    }

    public function test_la_representation_d_equipe_n_est_exigee_que_des_collectifs(): void
    {
        $this->assertNotContains(
            AttachmentsSection::TEAM_REPRESENTATION,
            AttachmentsSection::requiredFor(CandidateType::INDIVIDUAL),
        );

        $this->assertContains(
            AttachmentsSection::TEAM_REPRESENTATION,
            AttachmentsSection::requiredFor(CandidateType::TEAM),
        );
    }

    // — Déclarations ————————————————————————————————————————————————

    public function test_les_declarations_sont_reellement_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed', 'documents', 'missing']);

        $ligne = ApplicationSectionAnswers::query()
            ->where('section', ApplicationSection::ATTACHMENTS->value)
            ->sole();

        $this->assertTrue($ligne->answers[AttachmentsSection::ACCURACY]);
        $this->assertTrue($ligne->answers[AttachmentsSection::DATA_PROCESSING_CONSENT]);
        $this->assertFalse($ligne->answers[AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT]);
    }

    /** @return array<string, array{string}> */
    public static function declarationsExigees(): array
    {
        return [
            'exactitude' => [AttachmentsSection::ACCURACY],
            'absence de fraude' => [AttachmentsSection::NO_FRAUD],
            'reglement' => [AttachmentsSection::RULES_ACKNOWLEDGEMENT],
            'traitement des donnees' => [AttachmentsSection::DATA_PROCESSING_CONSENT],
        ];
    }

    #[DataProvider('declarationsExigees')]
    public function test_une_declaration_refusee_empeche_l_achevement(string $declaration): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $this->deposerLesPiecesExigees($candidat, $application);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->declarations(),
            $declaration => false,
        ])->assertOk()->assertJsonPath('completed', false);

        $this->assertNull(
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::ATTACHMENTS->value)->sole()->completed_at,
        );
    }

    public function test_une_valeur_non_booleenne_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            AttachmentsSection::ACCURACY => 'oui, bien sûr',
        ])->assertStatus(422)->assertJsonValidationErrors(AttachmentsSection::ACCURACY);
    }

    public function test_un_champ_inconnu_n_entre_pas_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->declarations(),
            'status' => ApplicationStatus::SELECTED->value,
            'submission_number' => 'BG-2026-0001',
        ])->assertOk();

        $reponses = ApplicationSectionAnswers::query()
            ->where('section', ApplicationSection::ATTACHMENTS->value)->sole()->answers;

        // Clés triées : PostgreSQL ne garantit pas leur ordre dans un `jsonb`,
        // seulement leur présence.
        $clefs = array_keys($reponses);
        sort($clefs);
        $attendues = AttachmentsSection::fields();
        sort($attendues);

        $this->assertSame($attendues, $clefs);
        $this->assertSame(ApplicationStatus::DRAFT, $application->fresh()->status);
        $this->assertNull($application->fresh()->submission_number);
    }

    // — Téléversement ——————————————————————————————————————————————

    public function test_une_piece_est_stockee_sur_le_disque_prive_avec_ses_metadonnees(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('Ruwa Link — présentation.pdf'),
        ])->assertOk()->assertJsonPath('documents.PROJECT_PRESENTATION.filename', 'Ruwa Link — présentation.pdf');

        $piece = Attachment::query()->sole();

        $this->assertSame($application->getKey(), $piece->application_id);
        $this->assertSame(DocumentType::PROJECT_PRESENTATION, $piece->type);
        $this->assertSame('Ruwa Link — présentation.pdf', $piece->original_filename);
        $this->assertSame('application/pdf', $piece->mime_type);
        $this->assertGreaterThan(0, $piece->size);
        $this->assertNotSame('', $piece->checksum);
        // L'analyse antivirus du §15.1 a pris la pièce en charge. On vérifie la
        // propriété, pas l'état exact : celui-ci dépend du pilote de file — la
        // pièce est `PENDING` tant que le job attend, et déjà `UNAVAILABLE` en
        // `sync` faute d'analyseur configuré. Épingler l'un des deux ferait
        // échouer ce test le jour où la configuration de file change, pour une
        // raison sans rapport avec ce qu'il protège.
        $this->assertFalse(
            $piece->scan_status->autoriseLaRedistribution(),
            'Une pièce fraîchement déposée n’est servie à aucun tiers.',
        );
        $this->assertTrue(
            $piece->scan_status->seRejoue(),
            'Et elle reste reprenable par « attachments:scan » jusqu’à son verdict.',
        );

        Storage::disk(StoreApplicationDocument::diskName())->assertExists($piece->storage_key);
    }

    /**
     * Le nom de stockage ne se devine pas, et le nom d'origine ne s'y retrouve
     * pas : connaître l'un ne rapproche pas de l'autre.
     */
    public function test_le_nom_de_stockage_est_tire_au_sort(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('mon-dossier-secret.pdf'),
        ])->assertOk();

        $piece = Attachment::query()->sole();

        $this->assertStringNotContainsString('mon-dossier-secret', $piece->storage_key);
        $this->assertStringStartsWith('applications/'.$application->getKey().'/', $piece->storage_key);
        $this->assertStringEndsWith('.pdf', $piece->storage_key);
    }

    public function test_l_ecran_ne_revele_jamais_l_emplacement_d_une_piece(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $this->deposerLesPiecesExigees($candidat, $application);

        $piece = Attachment::query()->sole();

        $reponse = $this->actingAs($candidat)->get($this->url($application))->assertOk();

        $this->assertStringNotContainsString($piece->storage_key, $reponse->getContent());
        $this->assertStringNotContainsString($piece->checksum, $reponse->getContent());
    }

    public function test_un_chemin_glisse_dans_le_nom_de_fichier_est_neutralise(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('../../etc/passwd.pdf'),
        ])->assertOk();

        $this->assertSame('passwd.pdf', Attachment::query()->sole()->original_filename);
    }

    // — Validation des fichiers ————————————————————————————————————

    public function test_un_fichier_trop_lourd_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('enorme.pdf', DocumentType::MAX_KILOBYTES + 64),
        ])->assertStatus(422)->assertJsonValidationErrors('document');

        $this->assertSame(0, Attachment::query()->count());
        $this->assertCount(0, Storage::disk(StoreApplicationDocument::diskName())->allFiles());
    }

    /**
     * Le nom de fichier ne prouve rien.
     *
     * Un exécutable renommé `.pdf` passerait un contrôle d'extension seul :
     * c'est le contenu que Laravel inspecte, et c'est lui qui décide.
     */
    public function test_un_fichier_dont_le_contenu_ne_correspond_pas_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            // Un exécutable, nommé `.pdf` et annoncé `application/pdf` par le
            // client : les deux mentent, seul le contenu dit vrai.
            'document' => $this->fichier('presentation.pdf', 'MZ'.str_repeat('x', 512), 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('document');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_une_extension_hors_de_celles_du_cahier_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // Le §7.2 n'admet que le PDF pour la présentation du projet.
        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->png('capture.png'),
        ])->assertStatus(422)->assertJsonValidationErrors('document');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_une_image_est_acceptee_pour_l_existence_legale(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), CandidateType::STARTUP);

        // « PDF/JPG/PNG » au §7.2 : la photo d'un RCCM est une réponse.
        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::LEGAL_EXISTENCE->value,
            'document' => $this->png('rccm.png'),
        ])->assertOk();

        $this->assertSame(DocumentType::LEGAL_EXISTENCE, Attachment::query()->sole()->type);
    }

    public function test_un_type_de_piece_invente_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => 'PASSEPORT_DIPLOMATIQUE',
            'document' => $this->pdf(),
        ])->assertStatus(422)->assertJsonValidationErrors('type');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_une_requete_sans_fichier_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
        ])->assertStatus(422)->assertJsonValidationErrors('document');
    }

    // — Remplacement et suppression ————————————————————————————————

    public function test_un_remplacement_ne_laisse_ni_ligne_ni_fichier_orphelin(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $disque = Storage::disk(StoreApplicationDocument::diskName());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('version-1.pdf'),
        ])->assertOk();

        $premiere = Attachment::query()->sole();
        $ancienChemin = $premiere->storage_key;

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('version-2.pdf'),
        ])->assertOk();

        // Une seule ligne, une seule pièce, un seul fichier : deux
        // « Présentation du projet » dans un dossier, c'est un jury qui ne sait
        // pas laquelle lire.
        $seconde = Attachment::query()->sole();

        $this->assertSame('version-2.pdf', $seconde->original_filename);
        $this->assertNotSame($ancienChemin, $seconde->storage_key);
        $disque->assertMissing($ancienChemin);
        $disque->assertExists($seconde->storage_key);
        $this->assertCount(1, $disque->allFiles());
    }

    public function test_une_suppression_retire_la_ligne_et_le_fichier(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $disque = Storage::disk(StoreApplicationDocument::diskName());

        $this->deposerLesPiecesExigees($candidat, $application);
        $chemin = Attachment::query()->sole()->storage_key;

        $this->actingAs($candidat)
            ->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertSame(0, Attachment::query()->count());
        $disque->assertMissing($chemin);
        $this->assertCount(0, $disque->allFiles());
    }

    public function test_le_depot_et_le_retrait_sont_traces(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->deposerLesPiecesExigees($candidat, $application);
        $this->actingAs($candidat)->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))->assertOk();

        foreach (['APPLICATION_DOCUMENT_UPLOADED', 'APPLICATION_DOCUMENT_DELETED'] as $action) {
            $this->assertDatabaseHas('audit_events', [
                'actor_id' => $candidat->getKey(),
                'action' => $action,
                'target_type' => Application::class,
                'target_id' => (string) $application->getKey(),
            ]);
        }
    }

    // — Complétude et progression ——————————————————————————————————

    public function test_la_section_n_est_achevee_qu_avec_les_pieces_et_les_declarations(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // Déclarations seules : il manque la pièce obligatoire.
        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())
            ->assertOk()
            ->assertJsonPath('completed', false)
            ->assertJsonPath('missing.documents.0', DocumentType::PROJECT_PRESENTATION->value);

        // La pièce arrive : la section s'achève sans qu'on recoche quoi que ce soit.
        $this->deposerLesPiecesExigees($candidat, $application);

        $this->assertNotNull(
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::ATTACHMENTS->value)->sole()->completed_at,
        );
    }

    public function test_retirer_une_piece_exigee_rouvre_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())->assertOk();
        $this->deposerLesPiecesExigees($candidat, $application);

        $this->actingAs($candidat)
            ->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::ATTACHMENTS->value)->sole()->completed_at,
        );
    }

    public function test_la_progression_atteint_les_huit_sections(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);
        $id = $application->getKey();

        // L'étape 1 est déjà faite par le fabricant de brouillon.
        $this->assertSame(1, app(ApplicationProgress::class)->completedOnOpenPath($application));

        foreach (['profile', 'team', 'challenge', 'solution', 'impact', 'implementation'] as $rang => $section) {
            $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/{$section}", $this->reponsesDe($section))->assertOk();
            $this->assertSame($this->pourcentage($rang + 2), (int) $application->fresh()->completion_percent);
        }

        // Sept sur neuf : l'étape 8 n'a encore ni pièce ni déclaration.
        $this->assertSame($this->pourcentage(7), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())->assertOk();
        $this->deposerLesPiecesExigees($candidat, $application);

        $this->assertSame($this->pourcentage(8), (int) $application->fresh()->completion_percent);
        $this->assertSame(8, app(ApplicationProgress::class)->completedOnOpenPath($application->fresh()));

        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('application.completionPercent', $this->pourcentage(8)));
    }

    // — Recevabilité ————————————————————————————————————————————————

    /**
     * **Le test qui compte.** L'étape 8 est ce qui manquait pour qu'un dossier
     * devienne déposable, et `SubmissionReadiness` n'a pas changé d'une ligne.
     */
    public function test_un_dossier_complet_de_1_a_8_devient_deposable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        foreach (['profile', 'team', 'challenge', 'solution', 'impact', 'implementation'] as $section) {
            $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/{$section}", $this->reponsesDe($section))->assertOk();
        }

        // Avant l'étape 8 : sept sur neuf, et pas déposable.
        $avant = SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class));
        $this->assertFalse($avant->ready);
        $this->assertSame([ApplicationSection::ATTACHMENTS], $avant->missingSections);
        $this->assertSame([SubmissionBlocker::SECTIONS_INCOMPLETE], $avant->blockers);

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())->assertOk();
        $this->deposerLesPiecesExigees($candidat, $application);

        $apres = SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class));

        $this->assertTrue($apres->ready);
        $this->assertSame([], $apres->blockers);
        $this->assertSame([], $apres->missingSections);
    }

    /**
     * La copie figée identifie les pièces officielles du dossier.
     *
     * Un dépôt se conteste. Le jour où quelqu'un affirme que la présentation
     * lue par le jury n'est pas celle qu'il avait envoyée, la seule réponse
     * possible est une empreinte prise à l'instant du dépôt — un nom de fichier
     * ne prouve rien, et le fichier sur le disque, lui, ne dit pas de quand il
     * date. C'est ce que `documents` porte.
     *
     * Ce qu'il ne porte pas, et le test l'exige aussi : aucun contenu binaire,
     * aucun emplacement de stockage. Le premier ferait grossir la base au poids
     * des PDF, le second deviendrait faux au premier déménagement de disque.
     */
    public function test_la_copie_figee_identifie_les_pieces_deposees(): void
    {
        $candidat = $this->candidat();
        $application = $this->dossierPresqueComplet($candidat, CandidateType::STARTUP);

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$application->getKey()}/submit")
            ->assertOk();

        $copie = $application->fresh()->submitted_snapshot;

        $this->assertSame(SubmissionSnapshot::SCHEMA_VERSION, $copie['schema_version']);
        $this->assertArrayHasKey('documents', $copie);

        // Les trois pièces qu'une startup doit joindre, dans l'ordre du §7.2.
        $this->assertSame(
            array_map(
                static fn (DocumentType $piece): string => $piece->value,
                DocumentType::requiredFor(CandidateType::STARTUP),
            ),
            array_column($copie['documents'], 'type'),
        );

        $presentation = $copie['documents'][0];
        $ligne = $application->attachments()
            ->where('type', DocumentType::PROJECT_PRESENTATION->value)
            ->firstOrFail();

        $this->assertSame(DocumentType::PROJECT_PRESENTATION->label(), $presentation['label']);
        $this->assertSame($ligne->original_filename, $presentation['filename']);
        $this->assertSame((int) $ligne->size, $presentation['size']);
        $this->assertSame($ligne->checksum, $presentation['checksum']);
        $this->assertNotSame('', $presentation['checksum']);
        $this->assertNotNull($presentation['uploaded_at']);

        // Ni le contenu, ni l'emplacement.
        $this->assertArrayNotHasKey('storage_key', $presentation);
        $this->assertArrayNotHasKey('contents', $presentation);
        $this->assertStringNotContainsString($ligne->storage_key, json_encode($copie, JSON_THROW_ON_ERROR));
    }

    /**
     * Une déclaration facultative refusée n'ampute pas la copie.
     *
     * Les déclarations vivent dans `application_sections` et sont donc déjà
     * copiées par `sections`. Le test le constate plutôt que de le supposer :
     * c'est la moitié de l'étape 8 que la copie doit porter, et elle passe par
     * un chemin différent de celui des pièces.
     */
    public function test_la_copie_figee_porte_les_declarations_acceptees(): void
    {
        $candidat = $this->candidat();
        $application = $this->dossierPresqueComplet($candidat);

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$application->getKey()}/submit")
            ->assertOk();

        $copie = $application->fresh()->submitted_snapshot;

        $etape8 = collect($copie['sections'])
            ->firstWhere('key', ApplicationSection::ATTACHMENTS->value);

        $this->assertNotNull($etape8);
        $this->assertTrue($etape8['answers'][AttachmentsSection::ACCURACY]);
        $this->assertTrue($etape8['answers'][AttachmentsSection::DATA_PROCESSING_CONSENT]);
        $this->assertNotNull($etape8['completed_at']);
    }

    public function test_une_piece_exigee_manquante_rend_le_dossier_non_deposable(): void
    {
        $candidat = $this->candidat();
        $application = $this->dossierPresqueComplet($candidat);

        // Tout est là, puis la présentation du projet est retirée.
        $this->assertTrue(SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class))->ready);

        $this->actingAs($candidat)->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))->assertOk();

        $verdict = SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class));

        $this->assertFalse($verdict->ready);
        $this->assertSame([ApplicationSection::ATTACHMENTS], $verdict->missingSections);
    }

    public function test_une_declaration_refusee_rend_le_dossier_non_deposable(): void
    {
        $candidat = $this->candidat();
        $application = $this->dossierPresqueComplet($candidat);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->declarations(),
            AttachmentsSection::RULES_ACKNOWLEDGEMENT => false,
        ])->assertOk();

        $verdict = SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class));

        $this->assertFalse($verdict->ready);
        $this->assertSame([ApplicationSection::ATTACHMENTS], $verdict->missingSections);
    }

    /**
     * Une candidature en équipe doit davantage — et le verdict le sait.
     *
     * Le même dossier, aux mêmes pièces, est déposable en individuel et ne l'est
     * pas en équipe : les CV et l'autorisation de représentation manquent.
     */
    public function test_une_equipe_sans_cv_n_est_pas_deposable(): void
    {
        $candidat = $this->candidat();
        $application = $this->dossierPresqueComplet($candidat, CandidateType::TEAM);

        $this->assertTrue(SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class))->ready);

        $this->actingAs($candidat)->deleteJson($this->urlPiece($application, DocumentType::KEY_MEMBER_CV))->assertOk();

        $this->assertFalse(SubmissionReadiness::for($application->fresh(), app(EvaluateEligibility::class))->ready);
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_declarations_et_pieces_survivent_a_une_deconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();
        $this->patchJson("/candidate/application/{$application->getKey()}/eligibility", $this->eligibilite())->assertOk();
        $this->patchJson($this->url($application), $this->declarations())->assertOk();
        $this->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('presentation-finale.pdf'),
        ])->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::ATTACHMENTS->value)
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('answers.'.AttachmentsSection::ACCURACY, true)
            ->where('answers.'.AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT, false)
            ->where('documents.PROJECT_PRESENTATION.filename', 'presentation-finale.pdf'));
    }

    // — Téléchargement ——————————————————————————————————————————————

    public function test_le_proprietaire_telecharge_sa_piece_sous_son_nom_d_origine(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('Ruwa Link.pdf'),
        ])->assertOk();

        $reponse = $this->actingAs($candidat)
            ->get($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertOk();

        $this->assertStringContainsString('attachment', (string) $reponse->headers->get('content-disposition'));
        $this->assertStringContainsString('Ruwa Link.pdf', (string) $reponse->headers->get('content-disposition'));
    }

    public function test_une_piece_absente_rend_404(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->get($this->urlPiece($application, DocumentType::BUDGET_PLAN))
            ->assertNotFound();
    }

    // — Cloisonnement entre candidats ——————————————————————————————

    public function test_un_candidat_ne_voit_pas_les_pieces_d_un_autre(): void
    {
        $proprietaire = $this->candidat();
        $application = $this->brouillon($proprietaire, $this->campagne());
        $this->deposerLesPiecesExigees($proprietaire, $application);

        $intrus = $this->candidat();

        $this->actingAs($intrus)->get($this->url($application))->assertForbidden();
        $this->actingAs($intrus)->get($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))->assertForbidden();
    }

    public function test_un_candidat_ne_remplace_ni_ne_supprime_les_pieces_d_un_autre(): void
    {
        $proprietaire = $this->candidat();
        $application = $this->brouillon($proprietaire, $this->campagne());
        $this->deposerLesPiecesExigees($proprietaire, $application);

        $originale = Attachment::query()->sole();
        $intrus = $this->candidat();

        $this->actingAs($intrus)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('intrus.pdf'),
        ])->assertForbidden();

        $this->actingAs($intrus)
            ->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertForbidden();

        $this->actingAs($intrus)->patchJson($this->url($application), $this->declarations())->assertForbidden();

        // La pièce du propriétaire est intacte, ligne comprise.
        $this->assertSame(1, Attachment::query()->count());
        $this->assertSame($originale->storage_key, Attachment::query()->sole()->storage_key);
        Storage::disk(StoreApplicationDocument::diskName())->assertExists($originale->storage_key);
    }

    /**
     * Un refus ne doit rien apprendre.
     *
     * Ni le nom d'origine, ni l'emplacement de stockage ne doivent apparaître
     * dans ce que reçoit un candidat qui n'a rien à faire là.
     */
    public function test_un_refus_ne_fuit_ni_le_nom_ni_l_emplacement(): void
    {
        $proprietaire = $this->candidat();
        $application = $this->brouillon($proprietaire, $this->campagne());

        $this->actingAs($proprietaire)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('confidentiel-ruwa-link.pdf'),
        ])->assertOk();

        $piece = Attachment::query()->sole();

        $reponse = $this->actingAs($this->candidat())
            ->get($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertForbidden();

        $this->assertStringNotContainsString('confidentiel-ruwa-link', $reponse->getContent());
        $this->assertStringNotContainsString($piece->storage_key, $reponse->getContent());
    }

    public function test_un_visiteur_n_atteint_aucune_route_de_piece(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->post($this->url($application).'/documents', ['type' => DocumentType::PROJECT_PRESENTATION->value])->assertRedirect('/login');
        $this->get($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))->assertRedirect('/login');
    }

    /** @return array<string, array{UserRole}> */
    public static function rolesInternes(): array
    {
        return [
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    #[DataProvider('rolesInternes')]
    public function test_un_role_interne_n_ouvre_pas_l_espace_candidat(UserRole $role): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs(User::factory()->role($role)->create())
            ->get($this->url($application))
            ->assertForbidden();
    }

    // — Verrou après soumission ————————————————————————————————————

    public function test_un_dossier_soumis_n_accepte_plus_aucune_ecriture(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $this->deposerLesPiecesExigees($candidat, $application);

        $piece = Attachment::query()->sole();
        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::BUDGET_PLAN->value,
            'document' => $this->pdf('budget.pdf'),
        ])->assertForbidden();

        $this->actingAs($candidat)
            ->deleteJson($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))
            ->assertForbidden();

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())->assertForbidden();

        // Rien n'a bougé, ni en base ni sur le disque.
        $this->assertSame(1, Attachment::query()->count());
        Storage::disk(StoreApplicationDocument::diskName())->assertExists($piece->storage_key);

        // La consultation et le téléchargement, eux, restent ouverts : un
        // dossier déposé reste lisible par celui qui l'a déposé.
        $this->actingAs($candidat)->get($this->url($application))->assertOk();
        $this->actingAs($candidat)->get($this->urlPiece($application, DocumentType::PROJECT_PRESENTATION))->assertOk();
    }

    // — Administration : lecture seule ——————————————————————————————

    public function test_l_administration_voit_et_telecharge_les_pieces_sans_pouvoir_les_toucher(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::PROJECT_PRESENTATION->value,
            'document' => $this->pdf('presentation.pdf'),
        ])->assertOk();

        $admin = User::factory()->role(UserRole::ADMIN)->create();

        $this->actingAs($admin)->get("/admin/applications/{$application->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('application.sections.7.documents.0.filename', 'presentation.pdf')
                ->where('application.sections.7.documents.0.label', DocumentType::PROJECT_PRESENTATION->label()));

        // Le téléchargement, lui, attend un verdict antivirus : servir à un
        // tiers la pièce d'un inconnu est une redistribution, et seul l'état
        // `CLEAN` l'autorise (§15.1). Sans analyseur configuré dans les tests,
        // la pièce reste en attente — et c'est le comportement voulu.
        $chemin = "/admin/applications/{$application->getKey()}/documents/".DocumentType::PROJECT_PRESENTATION->value;

        $this->actingAs($admin)->get($chemin)->assertStatus(423);

        Attachment::query()->where('application_id', $application->getKey())
            ->update(['scan_status' => AttachmentScanStatus::CLEAN->value]);

        $this->actingAs($admin)->get($chemin)->assertOk();

        // Aucune route d'écriture côté administration : le dossier appartient au
        // candidat tant qu'il n'est pas déposé.
        $this->actingAs($admin)->postJson($this->url($application).'/documents', [
            'type' => DocumentType::BUDGET_PLAN->value,
            'document' => $this->pdf('budget.pdf'),
        ])->assertForbidden();
    }

    public function test_un_candidat_n_ouvre_pas_la_route_de_telechargement_de_l_administration(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $this->deposerLesPiecesExigees($candidat, $application);

        $this->actingAs($candidat)
            ->get("/admin/applications/{$application->getKey()}/documents/".DocumentType::PROJECT_PRESENTATION->value)
            ->assertForbidden();
    }

    // — Outils ——————————————————————————————————————————————————————

    /**
     * Un dossier complet de l'étape 1 à l'étape 8, par le vrai parcours HTTP.
     */
    private function dossierPresqueComplet(User $candidat, CandidateType $type = CandidateType::INDIVIDUAL): Application
    {
        $application = $this->brouillon($candidat, $this->campagne(), $type);
        $id = $application->getKey();

        foreach (['profile', 'team', 'challenge', 'solution', 'impact', 'implementation'] as $section) {
            $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/{$section}", $this->reponsesDe($section, $type))->assertOk();
        }

        $this->actingAs($candidat)->patchJson($this->url($application), $this->declarations())->assertOk();
        $this->deposerLesPiecesExigees($candidat, $application, $type);

        return $application;
    }

    /**
     * Jeux de réponses minimaux des étapes 2 à 7.
     *
     * @return array<string, mixed>
     */
    private function reponsesDe(string $section, CandidateType $type = CandidateType::INDIVIDUAL): array
    {
        return match ($section) {
            'profile' => [
                'birth_place' => 'Niamey',
                'gender' => Gender::FEMALE->value,
                'phone_primary' => '90 12 34 56',
                'preferred_channel' => 'SMS',
                'residence_region' => NigerRegion::NIAMEY->value,
                'residence_locality' => 'Yantala',
                'occupation' => 'Développeuse indépendante',
                'education_level' => 'BACHELOR',
            ],
            'team' => $type->isCollective()
                ? ['members' => [
                    ['full_name' => 'Aïcha Ibrahim', 'role' => 'Développeuse', 'phone' => '90 11 22 33', 'consent' => true],
                    ['full_name' => 'Moussa Sani', 'role' => 'Technicien', 'phone' => '90 44 55 66', 'consent' => true],
                ]] + ($type === CandidateType::STARTUP ? [
                    'structure_name' => 'Sahel Data',
                    'structure_founded_year' => 2023,
                    'structure_sector' => 'Numérique',
                    'structure_address' => 'Quartier Yantala, Niamey',
                ] : [])
                : [],
            'challenge' => [
                // La thématique est devenue une réponse exigée de l'étape 4 à
                // l'intégration de `feat/application-project-theme`. Sans elle,
                // le défi reste inachevé et le dossier n'atteint jamais 8/8 —
                // ce fichier testerait alors l'étape 8 sur un parcours que le
                // candidat ne peut pas terminer.
                ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
                'main_challenge' => 'Les bornes-fontaines en panne le restent des semaines.',
                'affected_people' => 'Les ménages non raccordés des quartiers périphériques.',
                'location' => NigerRegion::NIAMEY->value,
                'root_causes' => 'Aucun circuit de signalement, et un service des eaux sans visibilité.',
            ],
            'solution' => [
                'solution_name' => 'Ruwa Link',
                'value_proposition' => 'Signaler une borne en panne par SMS et suivre sa remise en service.',
                'key_features' => 'Signalement SMS, tableau de bord communal, alerte au technicien.',
                'innovation' => 'Les signalements se perdent aujourd’hui ; ici tout est tracé.',
                'maturity_stage' => 'PROTOTYPE',
                'technologies' => 'Passerelle SMS, PostgreSQL, interface web légère.',
            ],
            'impact' => [
                'beneficiaries' => 'Environ 4 000 habitants et le service des eaux de la commune.',
                'expected_results' => 'Le délai de réparation passe de trois semaines à quatre jours.',
                'impact_indicators' => 'Signalements traités et délai moyen, relevés chaque mois.',
                'inclusion_measures' => 'SMS simple sans smartphone ; messages en haoussa et en zarma.',
                'resilience_contribution' => 'Un réseau qui se répare vite encaisse mieux les sécheresses.',
                'business_model' => 'Abonnement annuel de la commune ; 2 M FCFA de fonctionnement par an.',
                'sustainability' => 'Deux agents formés la première année, puis reprise par le service.',
            ],
            'implementation' => [
                'duration_months' => 9,
                'activities' => 'Cartographier les bornes, brancher la passerelle SMS, former les agents.',
                'milestones' => 'Mois 2 : bornes cartographiées. Mois 5 : passerelle en service.',
                'resources' => 'Un ordinateur portable, un forfait SMS, l’accès au registre communal.',
                'risks' => 'Coupures réseau prolongées ; nous supposons l’accès au registre.',
                'support_needs' => 'Appui juridique pour la convention, et un terrain de test.',
                'budget_amount' => 5_000_000,
            ],
            default => [],
        };
    }

    private function pourcentage(int $sections): int
    {
        return ApplicationProgress::percentFromCompleted($sections);
    }
}
