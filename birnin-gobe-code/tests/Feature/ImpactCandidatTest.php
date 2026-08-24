<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ImpactSection;
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
 * Étape 6 — Impact / viabilité.
 *
 * Une vérification vaut d'être dite : cette étape **décrit**, elle ne note pas.
 * Le test `test_aucune_notation_n_est_persistee_par_cette_section` le tient — un
 * score glissé dans la charge utile n'entre pas en base, et aucune colonne de
 * notation n'apparaît.
 */
final class ImpactCandidatTest extends TestCase
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
        return "/candidate/application/{$application->getKey()}/impact";
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
    private function impact(array $remplacements = []): array
    {
        return [
            ImpactSection::BENEFICIARIES => 'Environ 4 000 habitants de deux quartiers, et le service des eaux de la commune.',
            ImpactSection::EXPECTED_RESULTS => 'Le délai de remise en service d’une borne passe de trois semaines à quatre jours.',
            ImpactSection::IMPACT_INDICATORS => 'Nombre de signalements traités et délai moyen de réparation, relevés chaque mois.',
            ImpactSection::INCLUSION_MEASURES => 'Signalement par SMS simple, sans smartphone ; messages en haoussa et en zarma.',
            ImpactSection::RESILIENCE_CONTRIBUTION => 'Un réseau d’eau qui se répare vite encaisse mieux les pics de sécheresse.',
            ImpactSection::BUSINESS_MODEL => 'Abonnement annuel de la commune ; coût de fonctionnement estimé à 2 M FCFA par an.',
            ImpactSection::SUSTAINABILITY => 'Formation de deux agents communaux la première année, puis reprise complète par le service.',
            ImpactSection::SCALING_PLAN => 'Extension aux cinq arrondissements après la première année.',
            ...$remplacements,
        ];
    }

    // — L'étape entre dans le parcours ————————————————————————————

    public function test_l_etape_6_est_developpee_et_sur_le_parcours_ouvert(): void
    {
        $this->assertSame(6, ApplicationSection::IMPACT->position());
        $this->assertTrue(ApplicationSection::IMPACT->isImplemented());
        $this->assertTrue(ApplicationSection::IMPACT->isOnOpenPath());

        $this->assertSame(ApplicationSection::IMPACT, ApplicationSection::SOLUTION->nextOnOpenPath());
        $this->assertSame(ApplicationSection::SOLUTION, ApplicationSection::IMPACT->previousImplemented());
    }

    public function test_les_ecrans_annoncent_la_navigation_calculee_par_le_serveur(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Impact')
                ->where('previousUrl', url("/candidate/application/{$id}/solution"))
                ->where('nextUrl', url("/candidate/application/{$id}/implementation")));
    }

    // — Lecture ————————————————————————————————————————————————————

    public function test_le_candidat_ouvre_sa_section_avec_ses_reponses(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact())->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.beneficiaries', $this->impact()[ImpactSection::BENEFICIARIES])
                ->where('answers.business_model', $this->impact()[ImpactSection::BUSINESS_MODEL])
                ->where('section.position', 6)
                ->where('requiredFields', ImpactSection::REQUIRED_FIELDS));
    }

    public function test_l_ecran_rappelle_la_solution_sans_la_redemander(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/solution", [
            SolutionSection::SOLUTION_NAME => 'Ruwa Link',
        ])->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('known.solutionName', 'Ruwa Link')
                ->where('known.solutionUrl', url("/candidate/application/{$id}/solution")));

        // Le nom de la solution reste à l'étape 5 : il n'est pas recopié ici.
        $this->assertNull(
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::IMPACT->value)->first(),
        );
    }

    // — Sauvegarde ————————————————————————————————————————————————

    public function test_les_reponses_sont_reellement_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed'])
            ->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame(ApplicationSection::IMPACT, $ligne->section);
        $this->assertEquals($this->impact(), $ligne->answers);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_les_sauvegardes_successives_ne_creent_qu_une_ligne(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        foreach (['4', '40', '400'] as $frappe) {
            $this->actingAs($candidat)->patchJson($this->url($application), [ImpactSection::BENEFICIARIES => $frappe])->assertOk();
        }

        $this->assertSame(1, ApplicationSectionAnswers::query()->count());
        $this->assertSame('400', ApplicationSectionAnswers::query()->sole()->answers[ImpactSection::BENEFICIARIES]);
    }

    public function test_une_reponse_effacee_disparait_de_la_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact())->assertOk();
        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact([ImpactSection::INCLUSION_MEASURES => '']))->assertOk();

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertNull($ligne->answers[ImpactSection::INCLUSION_MEASURES]);
        $this->assertNull($ligne->completed_at);
    }

    // — Complétude ————————————————————————————————————————————————

    public function test_une_sauvegarde_partielle_est_acceptee_mais_n_acheve_pas_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ImpactSection::BENEFICIARIES => 'À préciser.'])
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->sole()->completed_at);
        $this->assertSame(0, (int) $application->fresh()->completion_percent);
    }

    /** @return array<string, array{string}> */
    public static function champsObligatoires(): array
    {
        $cas = [];

        foreach (ImpactSection::REQUIRED_FIELDS as $champ) {
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
            ->patchJson($this->url($application), $this->impact([$champ => null]))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_la_mise_a_l_echelle_reste_facultative(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // Un projet au stade de l'idée n'a pas encore à formuler son extension.
        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->impact([ImpactSection::SCALING_PLAN => null]))
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->assertNotNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    // — Aucune notation ————————————————————————————————————————————

    public function test_aucune_notation_n_est_persistee_par_cette_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->impact(),
            'score' => 87,
            'note' => 4.5,
            'ponderation' => 0.3,
            'rang' => 1,
        ])->assertOk();

        $reponses = ApplicationSectionAnswers::query()->sole()->answers;

        foreach (['score', 'note', 'ponderation', 'rang'] as $intrus) {
            $this->assertArrayNotHasKey($intrus, $reponses);
        }

        // La section n'écrit que ses propres champs : rien de plus, rien de
        // moins. Les clés sont triées — PostgreSQL ne garantit pas leur ordre
        // dans un `jsonb`, seulement leur présence.
        $clefs = array_keys($reponses);
        sort($clefs);
        $attendues = ImpactSection::fields();
        sort($attendues);

        $this->assertSame($attendues, $clefs);
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_une_reponse_trop_longue_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ImpactSection::EXPECTED_RESULTS => str_repeat('a', ImpactSection::LONG_TEXT_MAX + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(ImpactSection::EXPECTED_RESULTS);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_current_step_persiste_apres_sauvegarde(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact())->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::IMPACT->value,
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
        $this->patchJson($this->url($application), $this->impact())->assertOk();

        $this->post('/logout');
        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('answers.impact_indicators', $this->impact()[ImpactSection::IMPACT_INDICATORS]));
    }

    // — Cloisonnement ——————————————————————————————————————————————

    public function test_un_candidat_ne_lit_pas_l_impact_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->get($this->url($application))->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_l_impact_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->patchJson($this->url($application), $this->impact())->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_visiteur_n_ouvre_pas_l_impact(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->patch($this->url($application), $this->impact())->assertRedirect('/login');
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
    public function test_un_role_interne_n_ouvre_pas_l_impact_d_un_candidat(UserRole $role): void
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

        $this->actingAs($candidat)->patchJson($this->url($application), $this->impact())->assertForbidden();
        $this->actingAs($candidat)->get($this->url($application))->assertOk();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }
}
