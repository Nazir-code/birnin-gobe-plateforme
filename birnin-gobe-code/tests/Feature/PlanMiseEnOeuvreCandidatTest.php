<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ImpactSection;
use App\Domain\Application\ImplementationSection;
use App\Domain\Application\MaturityStage;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\SolutionSection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Étape 7 — Plan de mise en œuvre, et parcours complet 1 → 7.
 *
 * Ce fichier porte aussi le test de bout en bout de la progression : c'est ici
 * que le parcours ouvert s'arrête aujourd'hui, et donc ici que se vérifie qu'il
 * mène bien de 1/9 à 7/9 sans trou.
 */
final class PlanMiseEnOeuvreCandidatTest extends TestCase
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
        return "/candidate/application/{$application->getKey()}/implementation";
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
    private function plan(array $remplacements = []): array
    {
        return [
            ImplementationSection::DURATION_MONTHS => 9,
            ImplementationSection::ACTIVITIES => 'Cartographier les bornes, brancher la passerelle SMS, former les agents.',
            ImplementationSection::MILESTONES => 'Mois 2 : bornes cartographiées. Mois 5 : passerelle en service. Mois 9 : bilan.',
            ImplementationSection::RESOURCES => 'Un ordinateur portable, un forfait SMS, l’accès au registre communal.',
            ImplementationSection::PARTNERS => 'Service des eaux de la commune, radio communautaire du quartier.',
            ImplementationSection::RISKS => 'Coupures réseau prolongées ; nous supposons que la commune fournira le registre.',
            ImplementationSection::SUPPORT_NEEDS => 'Appui juridique pour la convention avec la commune, et un terrain de test.',
            ImplementationSection::BUDGET_AMOUNT => 5_000_000,
            ImplementationSection::BUDGET_BREAKDOWN => 'Équipement 1,5 M ; SMS 2 M ; formation 1 M ; déplacements 0,5 M.',
            ...$remplacements,
        ];
    }

    // — L'étape entre dans le parcours ————————————————————————————

    public function test_l_etape_7_est_developpee_et_termine_le_parcours_ouvert(): void
    {
        $this->assertSame(7, ApplicationSection::IMPLEMENTATION->position());
        $this->assertTrue(ApplicationSection::IMPLEMENTATION->isImplemented());
        $this->assertTrue(ApplicationSection::IMPLEMENTATION->isOnOpenPath());

        $this->assertSame(ApplicationSection::IMPLEMENTATION, ApplicationSection::IMPACT->nextOnOpenPath());
        $this->assertSame(ApplicationSection::IMPACT, ApplicationSection::IMPLEMENTATION->previousImplemented());

        // Le parcours s'arrête honnêtement ici : l'étape 8 n'existe pas encore.
        $this->assertNull(ApplicationSection::IMPLEMENTATION->nextOnOpenPath());
        $this->assertFalse(ApplicationSection::ATTACHMENTS->isImplemented());
        $this->assertFalse(ApplicationSection::REVIEW->isImplemented());
    }

    public function test_le_parcours_ouvert_compte_sept_etapes_dans_l_ordre(): void
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
            ],
            ApplicationSection::openPath(),
        );
    }

    public function test_l_ecran_n_annonce_aucune_etape_suivante(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Implementation')
                ->where('previousUrl', url("/candidate/application/{$id}/impact"))
                ->where('nextUrl', null));
    }

    // — Lecture ————————————————————————————————————————————————————

    public function test_le_candidat_ouvre_sa_section_avec_ses_reponses(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Les entiers repartent en chaîne pour un formulaire contrôlé ;
                // la forme qui compte est celle stockée, vérifiée plus bas.
                ->where('answers.duration_months', '9')
                ->where('answers.budget_amount', '5000000')
                ->where('section.position', 7)
                ->where('durationMin', ImplementationSection::DURATION_MIN)
                ->where('durationMax', ImplementationSection::DURATION_MAX)
                ->where('requiredFields', ImplementationSection::REQUIRED_FIELDS));
    }

    public function test_l_ecran_rappelle_l_effectif_de_l_etape_3_sans_le_redemander(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/eligibility", $this->eligibilite([
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 3,
        ]))->assertOk();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/team", [
            'members' => [
                ['full_name' => 'Aïcha Ibrahim', 'role' => 'Développeuse', 'phone' => '90 11 22 33', 'consent' => true],
                ['full_name' => 'Moussa Sani', 'role' => 'Technicien', 'phone' => '90 44 55 66', 'consent' => true],
            ],
        ])->assertOk();

        // Deux membres listés, plus le porteur : trois personnes, comme à
        // l'étape 3. La règle est appelée, pas recopiée.
        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('known.teamSize', 3)
                ->where('known.teamUrl', url("/candidate/application/{$id}/team")));
    }

    // — Sauvegarde ————————————————————————————————————————————————

    public function test_les_reponses_sont_reellement_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed'])
            ->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame(ApplicationSection::IMPLEMENTATION, $ligne->section);
        $this->assertEquals($this->plan(), $ligne->answers);
        // Les deux valeurs numériques sont stockées comme entiers, pas comme texte.
        $this->assertIsInt($ligne->answers[ImplementationSection::DURATION_MONTHS]);
        $this->assertIsInt($ligne->answers[ImplementationSection::BUDGET_AMOUNT]);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_un_budget_saisi_avec_des_espaces_est_compris(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // La forme naturelle d'un montant en francs CFA, espace insécable compris.
        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan([
            ImplementationSection::BUDGET_AMOUNT => "5 000\u{202F}000",
        ]))->assertOk();

        $this->assertSame(5_000_000, ApplicationSectionAnswers::query()->sole()->answers[ImplementationSection::BUDGET_AMOUNT]);
    }

    public function test_une_reponse_effacee_disparait_de_la_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())->assertOk();
        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan([ImplementationSection::MILESTONES => '']))->assertOk();

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertNull($ligne->answers[ImplementationSection::MILESTONES]);
        $this->assertNull($ligne->completed_at);
    }

    // — Complétude ————————————————————————————————————————————————

    /** @return array<string, array{string}> */
    public static function champsObligatoires(): array
    {
        $cas = [];

        foreach (ImplementationSection::REQUIRED_FIELDS as $champ) {
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
            ->patchJson($this->url($application), $this->plan([$champ => null]))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_un_budget_a_zero_est_une_reponse_et_acheve_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        // « Zéro franc » et « case laissée vide » ne sont pas le même état.
        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->plan([ImplementationSection::BUDGET_AMOUNT => 0]))
            ->assertOk()
            ->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame(0, $ligne->answers[ImplementationSection::BUDGET_AMOUNT]);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_les_partenaires_et_la_repartition_du_budget_restent_facultatifs(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan([
            ImplementationSection::PARTNERS => null,
            ImplementationSection::BUDGET_BREAKDOWN => null,
        ]))->assertOk()->assertJsonPath('completed', true);

        $this->assertNotNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    // — Validation serveur ————————————————————————————————————————

    /** @return array<string, array{mixed}> */
    public static function dureesInvalides(): array
    {
        return [
            'trop courte' => [2],
            'trop longue' => [13],
            'nulle' => [0],
            'negative' => [-3],
            'non entiere' => ['neuf mois'],
        ];
    }

    #[DataProvider('dureesInvalides')]
    public function test_une_duree_hors_des_bornes_du_cahier_est_refusee(mixed $duree): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ImplementationSection::DURATION_MONTHS => $duree])
            ->assertStatus(422)
            ->assertJsonValidationErrors(ImplementationSection::DURATION_MONTHS);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    /** @return array<string, array{mixed}> */
    public static function budgetsInvalides(): array
    {
        return [
            'negatif' => [-1],
            'non numerique' => ['beaucoup'],
            'au-dela du plafond de saisie' => [ImplementationSection::BUDGET_CEILING + 1],
        ];
    }

    #[DataProvider('budgetsInvalides')]
    public function test_un_budget_invalide_est_refuse(mixed $budget): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ImplementationSection::BUDGET_AMOUNT => $budget])
            ->assertStatus(422)
            ->assertJsonValidationErrors(ImplementationSection::BUDGET_AMOUNT);
    }

    public function test_une_reponse_trop_longue_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ImplementationSection::ACTIVITIES => str_repeat('a', ImplementationSection::LONG_TEXT_MAX + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(ImplementationSection::ACTIVITIES);
    }

    public function test_un_champ_inconnu_n_entre_pas_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->plan(),
            'submission_number' => 'BG-2026-0001',
            'submitted_at' => now()->toIso8601String(),
        ])->assertOk();

        // Les clés sont triées : PostgreSQL ne garantit pas leur ordre dans un
        // `jsonb`, seulement leur présence.
        $clefs = array_keys(ApplicationSectionAnswers::query()->sole()->answers);
        sort($clefs);
        $attendues = ImplementationSection::fields();
        sort($attendues);

        $this->assertSame($attendues, $clefs);
    }

    public function test_current_step_persiste_apres_sauvegarde(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::IMPLEMENTATION->value,
        ]);
    }

    // — Progression 4/9 → 5/9 → 6/9 → 7/9 —————————————————————————

    public function test_la_progression_atteint_sept_neuviemes(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/eligibility", $this->eligibilite())->assertOk();
        $this->assertSame($this->pourcentage(1), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/profile", $this->profil())->assertOk();
        $this->assertSame($this->pourcentage(2), (int) $application->fresh()->completion_percent);

        // Candidature individuelle : l'étape 3 n'a rien à renseigner.
        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/team", [])->assertOk();
        $this->assertSame($this->pourcentage(3), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", $this->defi())->assertOk();
        $this->assertSame($this->pourcentage(4), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/solution", $this->solution())->assertOk();
        $this->assertSame($this->pourcentage(5), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/impact", $this->impact())->assertOk();
        $this->assertSame($this->pourcentage(6), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())->assertOk();
        $this->assertSame($this->pourcentage(7), (int) $application->fresh()->completion_percent);

        // Sept sections achevées sur neuf, et le tableau de bord dit la même
        // chose que la colonne : la lecture recalcule.
        $this->assertSame(7, ApplicationSectionAnswers::query()->whereNotNull('completed_at')->count());

        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('application.completionPercent', $this->pourcentage(7)));
    }

    public function test_un_dossier_arrete_au_defi_voit_la_solution_comme_suite(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne());
        $id = $application->getKey();

        // État d'un dossier d'avant cette phase : arrêté à « Défi », qui était
        // alors la dernière étape ouverte.
        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", $this->defi())->assertOk();
        $acheveLe = ApplicationSectionAnswers::query()->where('section', ApplicationSection::CHALLENGE->value)->sole()->completed_at;

        // Aucune migration, aucune réécriture : « Solution » devient simplement
        // la suite, et le défi enregistré est intact.
        $this->actingAs($candidat)->get("/candidate/application/{$id}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextUrl', url("/candidate/application/{$id}/solution"))
                ->where('answers.main_challenge', $this->defi()['main_challenge']));

        $this->assertEquals(
            $acheveLe,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::CHALLENGE->value)->sole()->completed_at,
        );
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();
        $this->patchJson($this->url($application), $this->plan())->assertOk();

        $this->post('/logout');
        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::IMPLEMENTATION->value)
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('answers.milestones', $this->plan()[ImplementationSection::MILESTONES])
            ->where('answers.budget_amount', '5000000'));
    }

    // — Cloisonnement ——————————————————————————————————————————————

    public function test_un_candidat_ne_lit_pas_le_plan_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->get($this->url($application))->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_le_plan_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->patchJson($this->url($application), $this->plan())->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_visiteur_n_ouvre_pas_le_plan(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->patch($this->url($application), $this->plan())->assertRedirect('/login');
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
    public function test_un_role_interne_n_ouvre_pas_le_plan_d_un_candidat(UserRole $role): void
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

        $this->actingAs($candidat)->patchJson($this->url($application), $this->plan())->assertForbidden();
        $this->actingAs($candidat)->get($this->url($application))->assertOk();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    // — Jeux de réponses des étapes précédentes ————————————————————

    /**
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function eligibilite(array $remplacements = []): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(28)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
            ...$remplacements,
        ];
    }

    /** @return array<string, mixed> */
    private function profil(): array
    {
        return [
            'birth_place' => 'Niamey',
            'phone_primary' => '90 12 34 56',
            'preferred_channel' => 'SMS',
            'residence_region' => NigerRegion::NIAMEY->value,
            'residence_locality' => 'Yantala',
            'occupation' => 'Développeuse indépendante',
            'education_level' => 'BACHELOR',
        ];
    }

    /** @return array<string, mixed> */
    private function defi(): array
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
    private function solution(): array
    {
        return [
            SolutionSection::SOLUTION_NAME => 'Ruwa Link',
            SolutionSection::VALUE_PROPOSITION => 'Signaler une borne en panne par SMS et suivre sa remise en service.',
            SolutionSection::KEY_FEATURES => 'Signalement SMS, tableau de bord communal, alerte au technicien.',
            SolutionSection::INNOVATION => 'Les signalements se perdent aujourd’hui ; ici tout est tracé.',
            SolutionSection::MATURITY_STAGE => MaturityStage::PROTOTYPE->value,
            SolutionSection::PROTOTYPE_STATUS => 'Une version SMS tourne depuis trois mois sur deux quartiers.',
            SolutionSection::TECHNOLOGIES => 'Passerelle SMS, PostgreSQL, interface web légère.',
        ];
    }

    /** @return array<string, mixed> */
    private function impact(): array
    {
        return [
            ImpactSection::BENEFICIARIES => 'Environ 4 000 habitants et le service des eaux de la commune.',
            ImpactSection::EXPECTED_RESULTS => 'Le délai de réparation passe de trois semaines à quatre jours.',
            ImpactSection::IMPACT_INDICATORS => 'Signalements traités et délai moyen, relevés chaque mois.',
            ImpactSection::INCLUSION_MEASURES => 'SMS simple sans smartphone ; messages en haoussa et en zarma.',
            ImpactSection::RESILIENCE_CONTRIBUTION => 'Un réseau qui se répare vite encaisse mieux les sécheresses.',
            ImpactSection::BUSINESS_MODEL => 'Abonnement annuel de la commune ; 2 M FCFA de fonctionnement par an.',
            ImpactSection::SUSTAINABILITY => 'Deux agents formés la première année, puis reprise par le service.',
        ];
    }

    private function pourcentage(int $sections): int
    {
        return (int) round($sections / ApplicationSection::total() * 100);
    }
}
