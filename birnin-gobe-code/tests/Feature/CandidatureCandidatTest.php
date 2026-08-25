<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Auth\UserRole;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Candidature persistante rattachée au candidat connecté (Phase 1C).
 *
 * Ces tests écrivent réellement en PostgreSQL : ce qui est vérifié, ce sont les
 * lignes en base, pas ce que l'interface prétend afficher.
 */
final class CandidatureCandidatTest extends TestCase
{
    use RefreshDatabase;

    private function candidat(): User
    {
        return User::factory()->create();
    }

    private function campagneOuverte(): Campaign
    {
        return Campaign::factory()->create();
    }

    /** Brouillon créé par le vrai parcours HTTP, jamais par la factory. */
    private function brouillonDe(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    /** @return array<string, string|null> */
    private function reponsesCompletes(): array
    {
        return [
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
            'main_challenge' => 'L’accès à l’eau potable dans les quartiers périphériques.',
            'affected_people' => 'Les ménages non raccordés au réseau, en particulier les femmes.',
            'location' => NigerRegion::NIAMEY->value,
            'root_causes' => 'Extension urbaine plus rapide que le réseau de distribution.',
        ];
    }

    // — Création du brouillon ——————————————————————————————————————

    public function test_un_candidat_ouvre_un_brouillon_reellement_persiste(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();

        $reponse = $this->actingAs($candidat)->post('/candidate/application');

        $application = Application::query()->sole();

        // Depuis la Phase 1D, un brouillon s'ouvre sur l'etape 1 (Eligibilite)
        // et non plus sur « Defi » : l'ordre metier prime sur l'ordre dans
        // lequel les sections ont ete developpees.
        $reponse->assertRedirect("/candidate/application/{$application->getKey()}/eligibility");

        // Les quatre invariants de la phase, lus en base et non dans la réponse.
        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'candidate_id' => $candidat->getKey(),
            'campaign_id' => $campagne->getKey(),
            'status' => ApplicationStatus::DRAFT->value,
        ]);
    }

    public function test_le_brouillon_demarre_sur_la_premiere_section_implementee(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();

        $application = $this->brouillonDe($candidat, $campagne);

        $this->assertSame(ApplicationSection::firstImplemented(), $application->current_step);
        $this->assertSame(0, (int) $application->completion_percent);
    }

    public function test_la_creation_est_tracee_dans_le_journal_d_audit(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();

        $application = $this->brouillonDe($candidat, $campagne);

        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $candidat->getKey(),
            'action' => 'APPLICATION_CREATED',
            'target_type' => Application::class,
            'target_id' => (string) $application->getKey(),
        ]);
    }

    public function test_un_second_envoi_ne_cree_pas_un_deuxieme_brouillon(): void
    {
        $candidat = $this->candidat();
        $this->campagneOuverte();

        // Double-clic, rafraîchissement après envoi, requête rejouée : trois
        // façons d'arriver au même point.
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $this->assertSame(1, Application::query()->count());
    }

    public function test_la_base_refuse_elle_meme_un_doublon_candidat_campagne(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $this->brouillonDe($candidat, $campagne);

        // La protection ne repose pas seulement sur le code applicatif : une
        // écriture directe est rejetée par la contrainte d'unicité.
        $this->expectException(UniqueConstraintViolationException::class);

        Application::factory()->create([
            'candidate_id' => $candidat->getKey(),
            'campaign_id' => $campagne->getKey(),
        ]);
    }

    public function test_un_meme_candidat_peut_candidater_a_une_autre_campagne(): void
    {
        $candidat = $this->candidat();
        $premiere = $this->campagneOuverte();
        $this->brouillonDe($candidat, $premiere);

        // En préparation, et non ouverte : une seule campagne peut porter le
        // statut OPEN (ADR-008). Ce que ce test vérifie — un même candidat peut
        // avoir un dossier dans deux campagnes — n'en dépend pas.
        $seconde = Campaign::factory()->draft()->create();
        Application::factory()->create([
            'candidate_id' => $candidat->getKey(),
            'campaign_id' => $seconde->getKey(),
        ]);

        $this->assertSame(2, $candidat->applications()->count());
    }

    public function test_sans_campagne_ouverte_aucune_candidature_n_est_creee(): void
    {
        $candidat = $this->candidat();
        Campaign::factory()->draft()->create();
        Campaign::factory()->closed()->create();

        $this->actingAs($candidat)->post('/candidate/application')->assertForbidden();

        $this->assertSame(0, Application::query()->count());
    }

    // — Qui a le droit de créer ————————————————————————————————————

    public function test_un_visiteur_ne_cree_pas_de_candidature(): void
    {
        $this->campagneOuverte();

        $this->post('/candidate/application')->assertRedirect('/login');

        $this->assertSame(0, Application::query()->count());
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function rolesInternes(): array
    {
        return [
            'administrateur' => [UserRole::ADMIN],
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    #[DataProvider('rolesInternes')]
    public function test_un_role_interne_ne_cree_pas_de_candidature(UserRole $role): void
    {
        $this->campagneOuverte();
        $interne = User::factory()->role($role)->create();

        $this->actingAs($interne)->post('/candidate/application')->assertForbidden();

        $this->assertSame(0, Application::query()->count());
    }

    // — Tableau de bord ————————————————————————————————————————————

    public function test_le_tableau_de_bord_annonce_l_absence_de_candidature(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();

        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Dashboard')
                ->where('application', null)
                ->where('campaign.code', $campagne->code)
                ->has('steps', ApplicationSection::total()));
    }

    public function test_le_tableau_de_bord_retrouve_le_brouillon_depuis_la_base(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Dashboard')
                ->where('application.id', $application->getKey())
                ->where('application.status', ApplicationStatus::DRAFT->value)
                ->where('application.currentStep.key', ApplicationSection::ELIGIBILITY->value)
                ->where('application.continueUrl', url("/candidate/application/{$application->getKey()}/eligibility")));
    }

    public function test_le_tableau_de_bord_ne_montre_pas_le_brouillon_d_un_autre(): void
    {
        $campagne = $this->campagneOuverte();
        $autre = $this->candidat();
        $this->brouillonDe($autre, $campagne);

        $this->actingAs($this->candidat())->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('application', null));
    }

    public function test_l_entree_ma_candidature_ne_cree_rien(): void
    {
        $candidat = $this->candidat();
        $this->campagneOuverte();

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect('/candidate/dashboard');

        $this->assertSame(0, Application::query()->count());
    }

    public function test_l_entree_ma_candidature_renvoie_vers_la_section_en_cours(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect("/candidate/application/{$application->getKey()}/eligibility");
    }

    // — Section « Défi » : lecture et sauvegarde ————————————————————

    public function test_le_candidat_ouvre_sa_section_avec_ses_reponses(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertOk();

        $this->actingAs($candidat)->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Challenge')
                ->where('answers.main_challenge', $this->reponsesCompletes()['main_challenge'])
                ->where('answers.location', NigerRegion::NIAMEY->value)
                ->where('section.position', ApplicationSection::CHALLENGE->position())
                ->has('regions', count(NigerRegion::cases())));
    }

    public function test_les_reponses_sont_reellement_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed']);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame($application->getKey(), $ligne->application_id);
        $this->assertSame(ApplicationSection::CHALLENGE, $ligne->section);
        // `assertEquals` et non `assertSame` : PostgreSQL ne garantit pas
        // l'ordre des clés d'une valeur `jsonb`, seulement son contenu.
        $this->assertEquals($this->reponsesCompletes(), $ligne->answers);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_une_sauvegarde_partielle_est_acceptee_mais_n_acheve_pas_la_section(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", [
                'main_challenge' => 'Une première idée, encore à préciser.',
            ])
            ->assertOk();

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame('Une première idée, encore à préciser.', $ligne->answers['main_challenge']);
        $this->assertNull($ligne->answers['location']);
        $this->assertNull($ligne->completed_at, 'Un brouillon incomplet ne doit pas compter comme une section faite.');
        $this->assertSame(0, (int) $application->fresh()->completion_percent);
    }

    public function test_les_sauvegardes_successives_ne_creent_qu_une_ligne(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);
        $url = "/candidate/application/{$application->getKey()}/challenge";

        // Ce que produit la saisie continue : beaucoup d'écritures, un seul état.
        foreach (['a', 'ab', 'abc'] as $frappe) {
            $this->actingAs($candidat)->patchJson($url, ['main_challenge' => $frappe])->assertOk();
        }

        $this->assertSame(1, ApplicationSectionAnswers::query()->count());
        $this->assertSame('abc', ApplicationSectionAnswers::query()->sole()->answers['main_challenge']);
    }

    public function test_une_reponse_effacee_disparait_de_la_base(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);
        $url = "/candidate/application/{$application->getKey()}/challenge";

        $this->actingAs($candidat)->patchJson($url, $this->reponsesCompletes())->assertOk();
        $this->actingAs($candidat)->patchJson($url, ['main_challenge' => ''] + $this->reponsesCompletes())->assertOk();

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertNull($ligne->answers['main_challenge']);
        $this->assertNull($ligne->completed_at);
    }

    public function test_la_progression_est_calculee_par_le_serveur(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertOk();

        // Depuis la Phase 1F, l'étape 3 est développée : le parcours n'a plus
        // de trou et « Défi » y est revenu. Une section achevée sur les neuf,
        // donc — ni 65 %, ni 0 % : la valeur affichée est celle que le backend
        // sait démontrer. Le détail de la règle est couvert par
        // StructureEquipeCandidatTest.
        $attendu = (int) round(1 / ApplicationSection::total() * 100);

        $this->assertSame($attendu, (int) $application->fresh()->completion_percent);

        $this->assertNotNull(
            ApplicationSectionAnswers::query()->sole()->completed_at,
            'La section reste achevée, et compte désormais dans la progression.',
        );
    }

    public function test_current_step_persiste_apres_sauvegarde(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        // Étape volontairement rendue incohérente : la sauvegarde doit la
        // remettre sur la section réellement éditée.
        $application->forceFill(['current_step' => null])->save();

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::CHALLENGE->value,
        ]);
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_une_reponse_trop_longue_est_refusee(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", [
                'main_challenge' => str_repeat('a', 501),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('main_challenge');

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_une_region_hors_referentiel_est_refusee(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", [
                'location' => 'FR-75',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location');
    }

    public function test_un_champ_inconnu_n_entre_pas_en_base(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", [
                'main_challenge' => 'Défi retenu.',
                'status' => ApplicationStatus::SELECTED->value,
                'completion_percent' => 100,
                'champ_invente' => 'valeur',
            ])
            ->assertOk();

        $this->assertArrayNotHasKey('champ_invente', ApplicationSectionAnswers::query()->sole()->answers);
        $this->assertSame(ApplicationStatus::DRAFT, $application->fresh()->status);
        $this->assertNotSame(100, (int) $application->fresh()->completion_percent);
    }

    // — Cloisonnement entre candidats ——————————————————————————————

    public function test_un_candidat_ne_lit_pas_la_candidature_d_un_autre(): void
    {
        $campagne = $this->campagneOuverte();
        $proprietaire = $this->candidat();
        $application = $this->brouillonDe($proprietaire, $campagne);

        // Le cas réel : l'identifiant est changé à la main dans l'URL.
        $this->actingAs($this->candidat())
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_la_candidature_d_un_autre(): void
    {
        $campagne = $this->campagneOuverte();
        $proprietaire = $this->candidat();
        $application = $this->brouillonDe($proprietaire, $campagne);

        $this->actingAs($this->candidat())
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_role_interne_n_ouvre_pas_la_candidature_d_un_candidat(): void
    {
        $campagne = $this->campagneOuverte();
        $application = $this->brouillonDe($this->candidat(), $campagne);
        $admin = User::factory()->role(UserRole::ADMIN)->create();

        // 403 par le middleware d'espace, avant même la policy : l'espace
        // candidat n'est pas une porte d'entrée pour l'administration.
        $this->actingAs($admin)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertForbidden();
    }

    public function test_un_visiteur_n_ouvre_pas_une_candidature(): void
    {
        // Créée par factory et non par le parcours HTTP : `actingAs` resterait
        // sinon actif pour la requête suivante, et le test ne serait plus celui
        // d'un visiteur anonyme.
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagneOuverte()->getKey(),
        ]);

        $this->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertRedirect('/login');

        $this->patch("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertRedirect('/login');

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    // — Lecture seule après soumission ——————————————————————————————

    public function test_une_candidature_soumise_n_est_plus_modifiable(): void
    {
        $campagne = $this->campagneOuverte();
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $campagne);

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())
            ->assertForbidden();

        // La consultation, elle, reste ouverte à son propriétaire.
        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk();
    }

    // — Reprise après déconnexion ——————————————————————————————————

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $campagne = $this->campagneOuverte();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();
        $this->patchJson("/candidate/application/{$application->getKey()}/challenge", $this->reponsesCompletes())->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.id', $application->getKey())
            ->where('application.currentStep.key', ApplicationSection::CHALLENGE->value));

        $this->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.main_challenge', $this->reponsesCompletes()['main_challenge'])
                ->where('answers.root_causes', $this->reponsesCompletes()['root_causes']));
    }
}
