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
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Application\SubmissionSnapshot;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\Gender;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Point de rencontre entre la thématique du projet (étape 4) et les pièces et
 * déclarations (étape 8).
 *
 * Les deux fonctionnalités ont chacune leur suite, et ce fichier ne les répète
 * pas. Il vérifie ce qu'aucune des deux ne pouvait vérifier seule, parce que
 * chacune a été développée sans l'autre :
 *
 *   la branche « thématique » ne pouvait pas déposer de dossier — l'étape 8
 *     n'existait pas, donc `SubmissionReadiness` refusait toujours ;
 *   la branche « pièces » ne connaissait pas la thématique — son parcours
 *     achevait le défi sans elle, ce que la fusion rend impossible.
 *
 * D'où le seul test qui compte ici : **un dossier réel, construit par les
 * vraies routes de l'étape 1 à l'étape 8**, atteint 8/8, devient déposable, et
 * sa copie figée porte les deux apports à la fois.
 *
 * Ce que ce fichier surveille en creux : que l'ouverture de l'étape 8 n'a pas
 * dispensé le défi de sa thématique, et que la thématique n'a pas déplacé ce
 * que la copie de dépôt retient d'une pièce.
 */
final class IntegrationThematiqueEtPiecesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Le disque est simulé : ce qui est vérifié est le contenu de la copie
        // figée, pas la capacité d'écrire sur un disque réel — c'est la suite
        // des pièces qui s'en charge.
        Storage::fake(StoreApplicationDocument::diskName());
    }

    /**
     * Le dossier complet des deux vagues : 8/8, déposable, copie complète.
     *
     * Ce test a longtemps figé `89`, c'est-à-dire le plafond que « Relecture /
     * envoi » imposait en occupant le dénominateur sans jamais pouvoir occuper
     * le numérateur. Un défaut inscrit dans une assertion cesse d'être un
     * défaut visible : il devient la référence.
     */
    public function test_un_dossier_thematise_et_documente_est_deposable(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeBoutEnBout($candidat);

        // — Les huit sections de contenu sont achevées : le dossier est fait.
        $this->assertSame(8, app(ApplicationProgress::class)->completedOnOpenPath($dossier));
        $this->assertSame(100, app(ApplicationProgress::class)->percent($dossier));

        // — Et le dossier est réellement déposable.
        $verdict = SubmissionReadiness::for($dossier->fresh(), app(EvaluateEligibility::class));

        $this->assertTrue($verdict->ready, 'Un dossier complet de 1 à 8 doit être déposable.');
        $this->assertSame([], $verdict->blockers);
        $this->assertSame([], $verdict->missingSections);
    }

    /**
     * La copie figée porte les deux apports, et rien de ce qu'elle ne doit pas
     * porter.
     */
    public function test_la_copie_figee_porte_la_thematique_et_les_pieces(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeBoutEnBout($candidat);

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$dossier->getKey()}/submit")
            ->assertOk();

        $depose = $dossier->fresh();
        $copie = $depose->submitted_snapshot;

        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
        $this->assertSame(SubmissionSnapshot::SCHEMA_VERSION, $copie['schema_version']);

        // — La thématique, portée par les réponses du défi.
        $defi = collect($copie['sections'])->firstWhere('key', ApplicationSection::CHALLENGE->value);
        $this->assertNotNull($defi);
        $this->assertSame(
            ProjectTheme::URBAN_MANAGEMENT->value,
            $defi['answers'][ChallengeSection::THEME_FIELD],
        );

        // — Les déclarations, portées par les réponses de l'étape 8.
        $pieces = collect($copie['sections'])->firstWhere('key', ApplicationSection::ATTACHMENTS->value);
        $this->assertNotNull($pieces);
        $this->assertTrue($pieces['answers'][AttachmentsSection::ACCURACY]);
        $this->assertTrue($pieces['answers'][AttachmentsSection::DATA_PROCESSING_CONSENT]);
        // Le consentement facultatif a été refusé : la copie doit le dire.
        $this->assertFalse($pieces['answers'][AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT]);

        // — Les pièces, dans leur propre clé, réduites à ce qui les identifie.
        $this->assertSame(
            [DocumentType::PROJECT_PRESENTATION->value],
            array_column($copie['documents'], 'type'),
        );

        $presentation = $copie['documents'][0];
        $this->assertSame('presentation.pdf', $presentation['filename']);
        $this->assertNotSame('', $presentation['checksum']);
        $this->assertGreaterThan(0, $presentation['size']);
    }

    /**
     * Ce que la copie ne doit contenir à aucun endroit.
     *
     * Vérifié sur la copie **entière** sérialisée, et non clé par clé : une
     * fuite se glisse dans la clé qu'on n'a pas pensé à regarder.
     */
    public function test_la_copie_figee_ne_contient_ni_binaire_ni_emplacement(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeBoutEnBout($candidat);

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$dossier->getKey()}/submit")
            ->assertOk();

        $piece = $dossier->attachments()->sole();
        $copie = json_encode($dossier->fresh()->submitted_snapshot, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($piece->storage_key, $copie);
        $this->assertStringNotContainsString('storage_key', $copie);
        $this->assertStringNotContainsString('%PDF', $copie);
        $this->assertStringNotContainsString('/var/www', $copie);
        $this->assertStringNotContainsString(storage_path(), $copie);
    }

    /**
     * L'administration voit un seul dossier, avec les deux apports.
     */
    public function test_l_administration_voit_la_thematique_les_pieces_et_les_declarations(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeBoutEnBout($candidat);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        // — La liste : la thématique y est lisible.
        $this->actingAs($admin)
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.theme', ProjectTheme::URBAN_MANAGEMENT->value)
                ->where('applications.0.themeLabel', ProjectTheme::URBAN_MANAGEMENT->label()));

        // — Le détail : thématique, pièces et déclarations.
        $this->actingAs($admin)
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $sections = collect($page->toArray()['props']['application']['sections']);

                $defi = $sections->firstWhere('key', ApplicationSection::CHALLENGE->value);
                $this->assertContains(
                    ProjectTheme::URBAN_MANAGEMENT->label(),
                    array_column($defi['fields'], 'value'),
                    'Le détail doit afficher la thématique en toutes lettres.',
                );

                $pieces = $sections->firstWhere('key', ApplicationSection::ATTACHMENTS->value);
                $this->assertSame(
                    [DocumentType::PROJECT_PRESENTATION->value],
                    array_column($pieces['documents'], 'type'),
                );
                $this->assertNotSame([], $pieces['fields'], 'Les déclarations doivent être listées.');

                // L'emplacement de stockage ne sort jamais vers l'écran.
                $this->assertArrayNotHasKey('storage_key', $pieces['documents'][0]);
            });

        // — Le téléchargement : lecture seule, et il attend un verdict.
        // Servir à un tiers la pièce d'un inconnu est une redistribution ; seul
        // l'état `CLEAN` l'autorise (§15.1). La pièce vient d'être déposée, donc
        // elle attend encore son analyse.
        $chemin = "/admin/applications/{$dossier->getKey()}/documents/".DocumentType::PROJECT_PRESENTATION->value;

        $this->actingAs($admin)->get($chemin)->assertStatus(423);

        Attachment::query()->where('application_id', $dossier->getKey())
            ->update(['scan_status' => AttachmentScanStatus::CLEAN->value]);

        $this->actingAs($admin)
            ->get($chemin)
            ->assertOk()
            ->assertDownload('presentation.pdf');
    }

    /**
     * Un brouillon d'avant la thématique, mais déjà pourvu de ses pièces.
     *
     * Le cas croisé des deux vagues : un dossier écrit avant que le défi
     * n'exige sa thématique, et dont l'étape 8 est pourtant faite. Il doit se
     * charger, se déclarer incomplet **sur le défi et non sur les pièces**, et
     * redevenir déposable dès que le candidat choisit — sans qu'aucune valeur
     * ne soit inventée à sa place.
     */
    public function test_un_ancien_brouillon_documente_reclame_sa_thematique(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeBoutEnBout($candidat);

        // Retour à l'état d'avant la thématique : la réponse est retirée
        // directement, puisqu'aucune route ne sait plus produire un défi sans
        // elle.
        $ligne = $dossier->sections()->where('section', ApplicationSection::CHALLENGE->value)->sole();
        $reponses = $ligne->answers;
        unset($reponses[ChallengeSection::THEME_FIELD]);
        $ligne->forceFill(['answers' => $reponses, 'completed_at' => null])->save();

        // — L'écran se charge, et ne choisit rien à la place du candidat.
        $this->actingAs($candidat)
            ->get("/candidate/application/{$dossier->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.'.ChallengeSection::THEME_FIELD, null));

        // — Le dossier retombe à sept sections sur huit, et le motif est le défi.
        $this->assertSame(7, app(ApplicationProgress::class)->completedOnOpenPath($dossier->fresh()));

        $avant = SubmissionReadiness::for($dossier->fresh(), app(EvaluateEligibility::class));
        $this->assertFalse($avant->ready);
        $this->assertSame([ApplicationSection::CHALLENGE], $avant->missingSections);

        // — Les pièces, elles, n'ont pas bougé.
        $this->assertSame(
            [DocumentType::PROJECT_PRESENTATION],
            StoreApplicationDocument::typesFor($dossier->fresh()),
        );

        // — Le choix de la thématique suffit à tout refermer.
        $this->actingAs($candidat)
            ->patchJson(
                "/candidate/application/{$dossier->getKey()}/challenge",
                $this->reponsesDefi(),
            )
            ->assertOk();

        $this->assertSame(8, app(ApplicationProgress::class)->completedOnOpenPath($dossier->fresh()));
        $this->assertTrue(
            SubmissionReadiness::for($dossier->fresh(), app(EvaluateEligibility::class))->ready,
        );
    }

    // — Outils ——————————————————————————————————————————————————————

    private function candidat(): User
    {
        return User::factory()->create(['role' => UserRole::CANDIDATE]);
    }

    /**
     * Un dossier complet de l'étape 1 à l'étape 8, par les vraies routes.
     */
    private function dossierDeBoutEnBout(User $candidat): Application
    {
        $campagne = Campaign::factory()->create();

        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $dossier = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();

        $id = $dossier->getKey();

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$id}/eligibility", [
                EligibilitySection::BIRTH_DATE => now()->subYears(28)->format('Y-m-d'),
                EligibilitySection::NIGERIEN_NATIONAL => true,
                EligibilitySection::RESIDES_IN_NIGER => true,
                EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
                EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
                EligibilitySection::TEAM_SIZE => null,
            ])->assertOk();

        foreach (['profile', 'team', 'challenge', 'solution', 'impact', 'implementation'] as $section) {
            $this->actingAs($candidat)
                ->patchJson("/candidate/application/{$id}/{$section}", $this->reponsesDe($section))
                ->assertOk();
        }

        // Étape 8 : la pièce exigée d'un porteur individuel, puis ses
        // déclarations.
        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$id}/attachments/documents", [
                'type' => DocumentType::PROJECT_PRESENTATION->value,
                'document' => UploadedFile::fake()->createWithContent(
                    'presentation.pdf',
                    '%PDF-1.4'.PHP_EOL.str_repeat('a', 20 * 1024),
                ),
            ])->assertOk();

        $declarations = [];

        foreach (AttachmentsSection::fields() as $champ) {
            $declarations[$champ] = true;
        }

        // Le consentement à la communication publique est facultatif : le
        // refuser doit rester sans conséquence sur le dépôt.
        $declarations[AttachmentsSection::PUBLIC_COMMUNICATION_CONSENT] = false;

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$id}/attachments", $declarations)
            ->assertOk();

        return $dossier->fresh();
    }

    /** @return array<string, mixed> */
    private function reponsesDefi(): array
    {
        return [
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
            'main_challenge' => 'Les bornes-fontaines en panne le restent des semaines.',
            'affected_people' => 'Les ménages non raccordés des quartiers périphériques.',
            'location' => NigerRegion::NIAMEY->value,
            'root_causes' => 'Aucun circuit de signalement, et un service des eaux sans visibilité.',
        ];
    }

    /** @return array<string, mixed> */
    private function reponsesDe(string $section): array
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
            // Une candidature individuelle n'a ni structure ni membres.
            'team' => [],
            'challenge' => $this->reponsesDefi(),
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
}
