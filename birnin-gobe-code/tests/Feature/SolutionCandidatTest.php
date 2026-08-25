<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\MaturityStage;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\SolutionSection;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Étape 5 — Solution.
 *
 * Ces tests écrivent réellement en PostgreSQL : ce qui est vérifié, ce sont les
 * lignes en base, pas ce que l'interface prétend afficher.
 */
final class SolutionCandidatTest extends TestCase
{
    use RefreshDatabase;

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
        return "/candidate/application/{$application->getKey()}/solution";
    }

    private function brouillon(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    /**
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function solution(array $remplacements = []): array
    {
        return [
            SolutionSection::SOLUTION_NAME => 'Ruwa Link',
            SolutionSection::VALUE_PROPOSITION => 'Signaler une borne-fontaine en panne par SMS et suivre sa remise en service.',
            SolutionSection::KEY_FEATURES => 'Signalement par SMS, tableau de bord communal, alerte au technicien de secteur.',
            SolutionSection::USAGE_SCENARIO => 'Une habitante envoie le code de la borne ; le technicien reçoit l’alerte et confirme la réparation.',
            SolutionSection::INNOVATION => 'Les signalements se font aujourd’hui de vive voix et se perdent ; ici tout est tracé.',
            SolutionSection::MATURITY_STAGE => MaturityStage::PROTOTYPE->value,
            SolutionSection::PROTOTYPE_STATUS => 'Une première version SMS tourne depuis trois mois sur deux quartiers.',
            SolutionSection::TECHNOLOGIES => 'Passerelle SMS, PostgreSQL, interface web légère.',
            SolutionSection::INTEROPERABILITY => 'Export vers le système de gestion du service des eaux de la commune.',
            ...$remplacements,
        ];
    }

    // — L'étape entre dans le parcours ————————————————————————————

    public function test_l_etape_5_est_developpee_et_sur_le_parcours_ouvert(): void
    {
        $this->assertSame(5, ApplicationSection::SOLUTION->position());
        $this->assertTrue(ApplicationSection::SOLUTION->isImplemented());
        $this->assertTrue(ApplicationSection::SOLUTION->isOnOpenPath());

        $this->assertSame(ApplicationSection::SOLUTION, ApplicationSection::CHALLENGE->nextOnOpenPath());
        $this->assertSame(ApplicationSection::CHALLENGE, ApplicationSection::SOLUTION->previousImplemented());
    }

    public function test_les_ecrans_annoncent_la_navigation_calculee_par_le_serveur(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->get("/candidate/application/{$id}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nextUrl', url($this->url($application))));

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Solution')
                ->where('previousUrl', url("/candidate/application/{$id}/challenge"))
                ->where('nextUrl', url("/candidate/application/{$id}/impact")));
    }

    // — Lecture ————————————————————————————————————————————————————

    public function test_le_candidat_ouvre_sa_section_avec_ses_reponses(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Solution')
                ->where('answers.solution_name', 'Ruwa Link')
                ->where('answers.maturity_stage', MaturityStage::PROTOTYPE->value)
                ->where('section.position', 5)
                ->where('requiredFields', SolutionSection::REQUIRED_FIELDS)
                ->has('maturityStages', count(MaturityStage::cases())));
    }

    public function test_l_ecran_rappelle_le_defi_sans_le_redemander(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", [
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
            'main_challenge' => 'Les bornes-fontaines en panne le restent des semaines.',
        ])->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('known.mainChallenge', 'Les bornes-fontaines en panne le restent des semaines.')
                ->where('known.challengeUrl', url("/candidate/application/{$id}/challenge")));

        // Et le défi n'est pas recopié dans les réponses de l'étape 5.
        $ligne = ApplicationSectionAnswers::query()->where('section', ApplicationSection::SOLUTION->value)->first();
        $this->assertNull($ligne);
    }

    // — Sauvegarde ————————————————————————————————————————————————

    public function test_les_reponses_sont_reellement_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed'])
            ->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame(ApplicationSection::SOLUTION, $ligne->section);
        // `assertEquals` : PostgreSQL ne garantit pas l'ordre des clés d'un `jsonb`.
        $this->assertEquals($this->solution(), $ligne->answers);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_les_sauvegardes_successives_ne_creent_qu_une_ligne(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        foreach (['R', 'Ru', 'Ruwa'] as $frappe) {
            $this->actingAs($candidat)->patchJson($this->url($application), [SolutionSection::SOLUTION_NAME => $frappe])->assertOk();
        }

        $this->assertSame(1, ApplicationSectionAnswers::query()->count());
        $this->assertSame('Ruwa', ApplicationSectionAnswers::query()->sole()->answers[SolutionSection::SOLUTION_NAME]);
    }

    public function test_une_reponse_effacee_disparait_de_la_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())->assertOk();
        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution([SolutionSection::TECHNOLOGIES => '']))->assertOk();

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertNull($ligne->answers[SolutionSection::TECHNOLOGIES]);
        $this->assertNull($ligne->completed_at);
    }

    // — Complétude ————————————————————————————————————————————————

    public function test_une_sauvegarde_partielle_est_acceptee_mais_n_acheve_pas_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            SolutionSection::SOLUTION_NAME => 'Une idée encore à préciser.',
        ])->assertOk()->assertJsonPath('completed', false);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame('Une idée encore à préciser.', $ligne->answers[SolutionSection::SOLUTION_NAME]);
        $this->assertNull($ligne->completed_at, 'Un brouillon incomplet ne doit pas compter comme une section faite.');
        $this->assertSame(0, (int) $application->fresh()->completion_percent);
    }

    /** @return array<string, array{string}> */
    public static function champsObligatoires(): array
    {
        $cas = [];

        foreach (SolutionSection::REQUIRED_FIELDS as $champ) {
            $cas[$champ] = [$champ];
        }

        return $cas;
    }

    #[DataProvider('champsObligatoires')]
    public function test_un_champ_obligatoire_manquant_empeche_l_achevement(string $champ): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->solution([$champ => null]))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_les_champs_facultatifs_n_empechent_pas_l_achevement(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // Le §5.2 ne fait pas du scénario d'usage ni de l'interopérabilité des
        // fonctions déterminantes : toutes les solutions ne dialoguent pas avec
        // un système tiers.
        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution([
            SolutionSection::USAGE_SCENARIO => null,
            SolutionSection::INTEROPERABILITY => null,
        ]))->assertOk()->assertJsonPath('completed', true);

        $this->assertNotNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_la_date_d_achevement_d_origine_est_conservee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())->assertOk();
        $premiere = ApplicationSectionAnswers::query()->sole()->completed_at;

        $this->travel(2)->hours();
        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution([
            SolutionSection::SOLUTION_NAME => 'Ruwa Link Niamey',
        ]))->assertOk();

        // C'est une date d'événement, pas un drapeau remis à zéro à chaque frappe.
        $this->assertEquals($premiere, ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_un_nom_de_solution_trop_long_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [SolutionSection::SOLUTION_NAME => str_repeat('a', SolutionSection::SHORT_TEXT_MAX + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(SolutionSection::SOLUTION_NAME);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_une_reponse_redigee_trop_longue_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [SolutionSection::VALUE_PROPOSITION => str_repeat('a', SolutionSection::LONG_TEXT_MAX + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(SolutionSection::VALUE_PROPOSITION);
    }

    public function test_un_stade_de_maturite_hors_liste_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [SolutionSection::MATURITY_STAGE => 'LICORNE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(SolutionSection::MATURITY_STAGE);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_champ_inconnu_n_entre_pas_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            SolutionSection::SOLUTION_NAME => 'Ruwa Link',
            'score' => 18,
            'status' => ApplicationStatus::SELECTED->value,
            'completion_percent' => 100,
        ])->assertOk();

        $reponses = ApplicationSectionAnswers::query()->sole()->answers;

        $this->assertArrayNotHasKey('score', $reponses);
        $this->assertArrayNotHasKey('status', $reponses);
        $this->assertSame(ApplicationStatus::DRAFT, $application->fresh()->status);
        $this->assertNotSame(100, (int) $application->fresh()->completion_percent);
    }

    public function test_current_step_persiste_apres_sauvegarde(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $application->forceFill(['current_step' => null])->save();

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::SOLUTION->value,
        ]);
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();
        $this->patchJson($this->url($application), $this->solution())->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::SOLUTION->value)
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('answers.solution_name', 'Ruwa Link')
            ->where('answers.technologies', $this->solution()[SolutionSection::TECHNOLOGIES]));
    }

    // — Cloisonnement ——————————————————————————————————————————————

    public function test_un_candidat_ne_lit_pas_la_solution_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->get($this->url($application))->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_la_solution_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())
            ->patchJson($this->url($application), $this->solution())
            ->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_visiteur_n_ouvre_pas_la_solution(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->patch($this->url($application), $this->solution())->assertRedirect('/login');

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    /** @return array<string, array{UserRole}> */
    public static function rolesInternes(): array
    {
        return [
            'administrateur' => [UserRole::ADMIN],
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    #[DataProvider('rolesInternes')]
    public function test_un_role_interne_n_ouvre_pas_la_solution_d_un_candidat(UserRole $role): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs(User::factory()->role($role)->create())
            ->get($this->url($application))
            ->assertForbidden();
    }

    // — Lecture seule après soumission ——————————————————————————————

    public function test_une_candidature_soumise_n_est_plus_modifiable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)->patchJson($this->url($application), $this->solution())->assertForbidden();

        // La consultation, elle, reste ouverte à son propriétaire.
        $this->actingAs($candidat)->get($this->url($application))->assertOk();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }
}
