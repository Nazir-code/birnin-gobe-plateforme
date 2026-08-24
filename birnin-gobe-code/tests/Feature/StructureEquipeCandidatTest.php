<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\TeamSection;
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
 * Étape 3 — Structure / équipe (Phase 1F).
 *
 * C'est la première section dont le contenu **et** la complétude dépendent
 * d'une autre : le type de candidature et l'effectif annoncé vivent à l'étape 1.
 * Ces tests vérifient donc en plus des habituels — persistance, validation,
 * ownership — qu'aucune seconde source de vérité n'apparaît.
 */
final class StructureEquipeCandidatTest extends TestCase
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
        return "/candidate/application/{$application->getKey()}/team";
    }

    /**
     * Ouvre un brouillon dont l'étape 1 est déjà répondue : sans elle, l'étape 3
     * ne sait pas quoi demander.
     *
     * @param  array<string, mixed>  $eligibilite
     */
    private function brouillon(User $candidat, Campaign $campagne, array $eligibilite = []): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $application = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();

        if ($eligibilite !== []) {
            $this->actingAs($candidat)->patchJson(
                "/candidate/application/{$application->getKey()}/eligibility",
                $this->eligibilite($eligibilite),
            )->assertOk();
        }

        return $application;
    }

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

    /**
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function membre(array $remplacements = []): array
    {
        return [
            TeamSection::MEMBER_NAME => 'Aïcha Ibrahim',
            TeamSection::MEMBER_EMAIL => 'aicha@example.test',
            TeamSection::MEMBER_PHONE => '90 11 22 33',
            TeamSection::MEMBER_ROLE => 'Développeuse',
            TeamSection::MEMBER_SKILLS => 'Applications mobiles, cartographie',
            TeamSection::MEMBER_AVAILABILITY => 'Temps plein',
            TeamSection::MEMBER_IS_FOUNDER => true,
            TeamSection::MEMBER_CONSENT => true,
            ...$remplacements,
        ];
    }

    /**
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function structure(array $remplacements = []): array
    {
        return [
            TeamSection::STRUCTURE_NAME => 'Sahel Data',
            TeamSection::STRUCTURE_ACRONYM => 'SD',
            TeamSection::STRUCTURE_FOUNDED_YEAR => 2023,
            TeamSection::STRUCTURE_SECTOR => 'Numérique',
            TeamSection::STRUCTURE_ADDRESS => 'Quartier Yantala, Niamey',
            TeamSection::STRUCTURE_RCCM => 'NE-NIM-2023-B-1234',
            TeamSection::STRUCTURE_NIF => '12345678',
            TeamSection::STRUCTURE_WEBSITE => 'https://sahel-data.ne',
            TeamSection::STRUCTURE_SOCIAL => 'facebook.com/saheldata',
            ...$remplacements,
        ];
    }

    // — Le parcours ouvert se referme enfin ————————————————————————

    public function test_l_etape_3_est_developpee_et_referme_le_trou_du_parcours(): void
    {
        $this->assertSame(3, ApplicationSection::TEAM->position());
        $this->assertTrue(ApplicationSection::TEAM->isImplemented());
        $this->assertTrue(ApplicationSection::TEAM->isOnOpenPath());

        // Le seul obstacle qui tenait « Défi » hors du parcours vient de tomber.
        $this->assertTrue(ApplicationSection::CHALLENGE->isOnOpenPath());

        // Le parcours s'est prolongé depuis, jusqu'à l'étape 8 : ce que ce test
        // vérifie reste que « Défi » y est entré et que rien n'y manque avant
        // lui. Les trois étapes suivantes sont couvertes par leurs propres
        // fichiers.
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
            ],
            ApplicationSection::openPath(),
        );
    }

    public function test_la_navigation_relie_desormais_les_quatre_etapes(): void
    {
        $this->assertSame(ApplicationSection::TEAM, ApplicationSection::PROFILE->nextOnOpenPath());
        $this->assertSame(ApplicationSection::CHALLENGE, ApplicationSection::TEAM->nextOnOpenPath());
        // « Défi » n'est plus le terminus : l'étape 5 l'a prolongé.
        $this->assertSame(ApplicationSection::SOLUTION, ApplicationSection::CHALLENGE->nextOnOpenPath());

        $this->assertSame(ApplicationSection::PROFILE, ApplicationSection::TEAM->previousImplemented());
        $this->assertSame(ApplicationSection::TEAM, ApplicationSection::CHALLENGE->previousImplemented());
    }

    public function test_les_ecrans_annoncent_la_navigation_calculee_par_le_serveur(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), []);
        $id = $application->getKey();

        $this->actingAs($candidat)->get("/candidate/application/{$id}/profile")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('nextUrl', url($this->url($application))));

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Team')
                ->where('previousUrl', url("/candidate/application/{$id}/profile"))
                ->where('nextUrl', url("/candidate/application/{$id}/challenge")));

        $this->actingAs($candidat)->get("/candidate/application/{$id}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('previousUrl', url($this->url($application))));
    }

    // — Variante INDIVIDUAL ————————————————————————————————————————

    public function test_une_candidature_individuelle_n_a_rien_a_renseigner(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]);

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('assessment.type', CandidateType::INDIVIDUAL->value)
                ->where('assessment.complete', true)
                ->where('assessment.describedSize', 1)
                ->where('assessment.missing', []));

        // L'acte explicite d'enregistrer achève la section : le §6.2 ne prévoit
        // ni structure ni membres pour une candidature individuelle.
        $this->actingAs($candidat)->patchJson($this->url($application), [])
            ->assertOk()
            ->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole();

        $this->assertNotNull($ligne->completed_at);
        $this->assertSame([], $ligne->answers);
    }

    public function test_une_candidature_individuelle_n_enregistre_ni_structure_ni_membres(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]);

        // Requête forgée : le client envoie des données qui ne concernent pas
        // sa variante.
        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure(),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk();

        $this->assertSame([], ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->answers);
    }

    // — Variante TEAM ——————————————————————————————————————————————

    public function test_une_equipe_enregistre_ses_membres(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 3,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [
                $this->membre(),
                $this->membre([TeamSection::MEMBER_NAME => 'Boubacar Sow', TeamSection::MEMBER_EMAIL => 'boubacar@example.test', TeamSection::MEMBER_PHONE => '']),
            ],
        ])->assertOk()->assertJsonPath('completed', true);

        $ligne = ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole();

        $this->assertCount(2, $ligne->answers[TeamSection::MEMBERS]);
        $this->assertSame('Aïcha Ibrahim', $ligne->answers[TeamSection::MEMBERS][0][TeamSection::MEMBER_NAME]);
        // Le numéro d'un membre est normalisé comme celui du candidat.
        $this->assertSame('+22790112233', $ligne->answers[TeamSection::MEMBERS][0][TeamSection::MEMBER_PHONE]);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_une_equipe_informelle_n_enregistre_pas_de_donnees_legales(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        // « Non constituée » est l'état d'une équipe informelle (§6.2) : les
        // champs de personne morale ne la concernent pas.
        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure(),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk();

        $answers = ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->answers;

        foreach (TeamSection::structureFields() as $champ) {
            $this->assertArrayNotHasKey($champ, $answers);
        }
    }

    // — Variante STARTUP ———————————————————————————————————————————

    public function test_une_startup_enregistre_structure_et_membres(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure(),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk()->assertJsonPath('completed', true);

        $answers = ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->answers;

        $this->assertSame('Sahel Data', $answers[TeamSection::STRUCTURE_NAME]);
        $this->assertSame(2023, $answers[TeamSection::STRUCTURE_FOUNDED_YEAR]);
        $this->assertSame('NE-NIM-2023-B-1234', $answers[TeamSection::STRUCTURE_RCCM]);
        $this->assertTrue($answers[TeamSection::MEMBERS][0][TeamSection::MEMBER_IS_FOUNDER]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function champsStructureObligatoires(): array
    {
        return array_combine(
            TeamSection::REQUIRED_STRUCTURE_FIELDS,
            array_map(static fn (string $c): array => [$c], TeamSection::REQUIRED_STRUCTURE_FIELDS),
        );
    }

    #[DataProvider('champsStructureObligatoires')]
    public function test_une_startup_sans_champ_obligatoire_n_est_pas_complete(string $champ): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure([$champ => null]),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk()->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->completed_at);
    }

    public function test_rccm_et_nif_restent_facultatifs(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        // Le §6.2 les qualifie de « si applicable » : toutes les structures n'en
        // disposent pas.
        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure([
                TeamSection::STRUCTURE_RCCM => null,
                TeamSection::STRUCTURE_NIF => null,
                TeamSection::STRUCTURE_ACRONYM => null,
                TeamSection::STRUCTURE_WEBSITE => null,
                TeamSection::STRUCTURE_SOCIAL => null,
            ]),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk()->assertJsonPath('completed', true);
    }

    // — Pas de seconde source de vérité ————————————————————————————

    public function test_le_type_de_candidature_n_est_jamais_ecrit_par_cette_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        // Requête forgée : le client tente de se déclarer startup pour ouvrir
        // les champs de structure.
        $this->actingAs($candidat)->patchJson($this->url($application), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 9,
            ...$this->structure(),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk();

        $sections = ApplicationSectionAnswers::query()->get()->keyBy(fn ($l): string => $l->section->value);

        // Ni recopié dans « équipe »…
        $this->assertArrayNotHasKey(EligibilitySection::CANDIDATE_TYPE, $sections['team']->answers);
        $this->assertArrayNotHasKey(EligibilitySection::TEAM_SIZE, $sections['team']->answers);
        // …ni modifié à sa source.
        $this->assertSame(CandidateType::TEAM->value, $sections['eligibility']->answers[EligibilitySection::CANDIDATE_TYPE]);
        $this->assertSame(2, $sections['eligibility']->answers[EligibilitySection::TEAM_SIZE]);
        // Et la variante appliquée reste celle de l'étape 1 : pas de structure.
        $this->assertArrayNotHasKey(TeamSection::STRUCTURE_NAME, $sections['team']->answers);
    }

    public function test_un_ecart_entre_effectif_annonce_et_equipe_decrite_est_signale(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 4,
        ]);

        // 4 annoncés à l'étape 1, 2 décrits ici (un membre + le porteur).
        $reponse = $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk();

        $reponse->assertJsonPath('completed', false)
            ->assertJsonPath('assessment.sizeMismatch', true)
            ->assertJsonPath('assessment.declaredSize', 4)
            ->assertJsonPath('assessment.describedSize', 2);

        $this->assertStringContainsString('4 personnes', $reponse->json('assessment.missing.0'));

        // Rien n'est réécrit en douce : l'étape 1 garde sa valeur.
        $this->assertSame(
            4,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::ELIGIBILITY->value)->sole()->answers[EligibilitySection::TEAM_SIZE],
        );
    }

    public function test_l_ecart_disparait_quand_l_equipe_correspond(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 3,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [
                $this->membre(),
                $this->membre([TeamSection::MEMBER_NAME => 'Boubacar Sow']),
            ],
        ])->assertOk()
            ->assertJsonPath('assessment.sizeMismatch', false)
            ->assertJsonPath('assessment.describedSize', 3)
            ->assertJsonPath('completed', true);
    }

    // — Complétude d'un membre ————————————————————————————————————

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function membresIncomplets(): array
    {
        return [
            'sans nom' => [TeamSection::MEMBER_NAME => null],
            'sans role' => [TeamSection::MEMBER_ROLE => null],
            'sans consentement' => [TeamSection::MEMBER_CONSENT => false],
            'sans aucun contact' => [TeamSection::MEMBER_EMAIL => null, TeamSection::MEMBER_PHONE => null],
        ];
    }

    #[DataProvider('membresIncomplets')]
    public function test_un_membre_incomplet_empeche_l_achevement(mixed ...$remplacements): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre($remplacements)],
        ])->assertOk()->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->completed_at);
    }

    public function test_un_seul_moyen_de_contact_suffit(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        // Le §6.2 demande « contact », au singulier. Exiger une adresse e-mail
        // exclurait les membres qui n'en ont pas.
        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre([TeamSection::MEMBER_EMAIL => null])],
        ])->assertOk()->assertJsonPath('completed', true);
    }

    public function test_une_equipe_sans_aucun_membre_n_est_pas_complete(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [TeamSection::MEMBERS => []])
            ->assertOk()
            ->assertJsonPath('completed', false);
    }

    public function test_une_ligne_de_membre_entierement_vide_n_est_pas_persistee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre(), array_fill_keys(TeamSection::memberFields(), null)],
        ])->assertOk();

        $this->assertCount(
            1,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->sole()->answers[TeamSection::MEMBERS],
        );
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_un_numero_de_membre_invalide_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre([TeamSection::MEMBER_PHONE => 'appelez-moi'])],
        ])->assertStatus(422)->assertJsonValidationErrors('members.0.phone');

        $this->assertSame(0, ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->count());
    }

    public function test_une_adresse_de_membre_invalide_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            TeamSection::MEMBERS => [$this->membre([TeamSection::MEMBER_EMAIL => 'pas-une-adresse'])],
        ])->assertStatus(422)->assertJsonValidationErrors('members.0.email');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function anneesInvalides(): array
    {
        return [
            'avant le plancher' => [1800],
            'dans le futur' => [3000],
            'non numerique' => ['bientot'],
        ];
    }

    #[DataProvider('anneesInvalides')]
    public function test_une_annee_de_creation_invalide_est_refusee(mixed $annee): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure([TeamSection::STRUCTURE_FOUNDED_YEAR => $annee]),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertStatus(422)->assertJsonValidationErrors(TeamSection::STRUCTURE_FOUNDED_YEAR);
    }

    public function test_un_site_internet_mal_forme_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ...$this->structure([TeamSection::STRUCTURE_WEBSITE => 'sahel-data']),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertStatus(422)->assertJsonValidationErrors(TeamSection::STRUCTURE_WEBSITE);
    }

    public function test_le_nombre_de_membres_est_plafonne(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $trop = array_fill(0, TeamSection::MEMBERS_CEILING + 1, $this->membre());

        $this->actingAs($candidat)->patchJson($this->url($application), [TeamSection::MEMBERS => $trop])
            ->assertStatus(422)
            ->assertJsonValidationErrors(TeamSection::MEMBERS);
    }

    // — Progression 1/9 → 2/9 → 3/9 → 4/9 —————————————————————————

    public function test_la_progression_atteint_quatre_neuviemes(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]);
        $id = $application->getKey();

        $this->assertSame($this->pourcentage(1), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/profile", $this->profil())->assertOk();
        $this->assertSame($this->pourcentage(2), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson($this->url($application), [])->assertOk();
        $this->assertSame($this->pourcentage(3), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", $this->defi())->assertOk();
        $this->assertSame($this->pourcentage(4), (int) $application->fresh()->completion_percent);
    }

    // — Brouillons antérieurs ——————————————————————————————————————

    public function test_un_ancien_brouillon_retrouve_son_defi_dans_la_progression(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]);
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", $this->defi())->assertOk();
        $acheveLe = ApplicationSectionAnswers::query()->where('section', ApplicationSection::CHALLENGE->value)->sole()->completed_at;

        // État d'un dossier d'avant cette phase : l'étape 3 n'existait pas, son
        // pourcentage a été calculé sous l'ancienne règle, et « Défi » n'y
        // comptait pas.
        $application->forceFill([
            'current_step' => ApplicationSection::CHALLENGE,
            'completion_percent' => $this->pourcentage(1),
        ])->save();

        // Aucune sauvegarde, aucune migration : la seule ouverture de l'étape 3
        // remet « Défi » dans le parcours, et l'affichage le reflète aussitôt.
        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('application.completionPercent', $this->pourcentage(2)));

        // Et rien n'a été perdu au passage.
        $this->assertEquals(
            $acheveLe,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::CHALLENGE->value)->sole()->completed_at,
        );
    }

    public function test_un_ancien_brouillon_reste_recuperable_et_garde_son_etape(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]);
        $id = $application->getKey();

        $this->actingAs($candidat)->patchJson("/candidate/application/{$id}/challenge", $this->defi())->assertOk();
        $application->forceFill(['current_step' => ApplicationSection::CHALLENGE])->save();

        // L'historique n'est pas réécrit : le dossier reste où il en était.
        $this->actingAs($candidat)->get('/candidate/application')->assertRedirect("/candidate/application/{$id}/challenge");
        $this->assertSame(ApplicationSection::CHALLENGE, $application->fresh()->current_step);

        $this->actingAs($candidat)->get("/candidate/application/{$id}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.main_challenge', $this->defi()['main_challenge'])
                // « Précédent » ramène vers l'étape 3, désormais ouverte : c'est
                // par là que le candidat rejoint le parcours complet.
                ->where('previousUrl', url($this->url($application))));
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();
        $id = $application->getKey();

        $this->patchJson("/candidate/application/{$id}/eligibility", $this->eligibilite([
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]))->assertOk();

        $this->patchJson($this->url($application), [
            ...$this->structure(),
            TeamSection::MEMBERS => [$this->membre()],
        ])->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::TEAM->value)
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('structure.structure_name', 'Sahel Data')
            ->where('members.0.full_name', 'Aïcha Ibrahim')
            ->where('members.0.consent', true));
    }

    // — Ownership et cloisonnement ————————————————————————————————

    public function test_un_candidat_ne_lit_pas_l_equipe_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->get($this->url($application))->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_l_equipe_d_un_autre(): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $this->actingAs($this->candidat())
            ->patchJson($this->url($application), [TeamSection::MEMBERS => [$this->membre()]])
            ->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->where('section', ApplicationSection::TEAM->value)->count());
    }

    public function test_un_visiteur_n_ouvre_pas_l_equipe(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->patch($this->url($application), [])->assertRedirect('/login');
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
    public function test_un_role_interne_n_ouvre_pas_l_equipe_d_un_candidat(UserRole $role): void
    {
        $application = $this->brouillon($this->candidat(), $this->campagne());
        $interne = User::factory()->role($role)->create();

        $this->actingAs($interne)->get($this->url($application))->assertForbidden();
        $this->actingAs($interne)->patchJson($this->url($application), [])->assertForbidden();
    }

    public function test_une_candidature_soumise_n_est_plus_modifiable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillon($candidat, $this->campagne(), [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
        ]);

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [TeamSection::MEMBERS => [$this->membre()]])
            ->assertForbidden();

        $this->actingAs($candidat)->get($this->url($application))->assertOk();
    }

    // — Non-régression de l'éligibilité ————————————————————————————

    public function test_l_equipe_est_fermee_a_un_candidat_non_eligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $campagne->forceFill(['settings' => ['eligibility' => ['requires_niger_link' => true]]])->save();

        $application = $this->brouillon($candidat, $campagne, [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 2,
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]);

        $eligibilite = "/candidate/application/{$application->getKey()}/eligibility";

        $this->actingAs($candidat)->get($this->url($application))->assertRedirect($eligibilite);
        $this->actingAs($candidat)->patchJson($this->url($application), [])->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function profil(): array
    {
        return [
            'birth_place' => 'Tahoua',
            'phone_primary' => '90 12 34 56',
            'preferred_channel' => 'SMS',
            'residence_region' => NigerRegion::NIAMEY->value,
            'residence_locality' => 'Yantala',
            'occupation' => 'Développeuse',
            'education_level' => 'BACHELOR',
        ];
    }

    /** @return array<string, string> */
    private function defi(): array
    {
        return [
            'main_challenge' => 'L’accès à l’eau potable en périphérie.',
            'affected_people' => 'Les ménages non raccordés au réseau.',
            'location' => NigerRegion::NIAMEY->value,
            'root_causes' => 'Une extension urbaine plus rapide que le réseau.',
        ];
    }

    private function pourcentage(int $sections): int
    {
        return (int) round($sections / ApplicationSection::total() * 100);
    }
}
