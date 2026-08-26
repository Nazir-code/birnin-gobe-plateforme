<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\Gender;
use App\Domain\Candidate\PreferredChannel;
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
 * Étape 2 — Profil du candidat (Phase 1E).
 *
 * Ces tests vérifient trois choses distinctes : ce qui est écrit en PostgreSQL,
 * ce que le serveur refuse d'écrire, et ce que la section ne redemande pas
 * parce qu'une autre source le détient déjà.
 */
final class ProfilCandidatTest extends TestCase
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

    private function brouillonDe(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    private function url(Application $application): string
    {
        return "/candidate/application/{$application->getKey()}/profile";
    }

    /**
     * Réponses complètes de la section.
     *
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function profilComplet(array $remplacements = []): array
    {
        return [
            ProfileSection::BIRTH_PLACE => 'Tahoua',
            ProfileSection::GENDER => Gender::FEMALE->value,
            ProfileSection::PHONE_PRIMARY => '90 12 34 56',
            ProfileSection::PHONE_SECONDARY => '+227 96 55 44 33',
            ProfileSection::PREFERRED_CHANNEL => PreferredChannel::SMS->value,
            ProfileSection::RESIDENCE_REGION => NigerRegion::NIAMEY->value,
            ProfileSection::RESIDENCE_LOCALITY => 'Yantala Haut',
            ProfileSection::OCCUPATION => 'Développeuse indépendante',
            ProfileSection::EDUCATION_LEVEL => EducationLevel::BACHELOR->value,
            ProfileSection::SPECIALTY => 'Systèmes d’information',
            ProfileSection::ACCESSIBILITY_NEED => 'Salle accessible en fauteuil pour le pitch.',
            ...$remplacements,
        ];
    }

    // — L'ordre des étapes ————————————————————————————————————————

    public function test_le_profil_est_la_deuxieme_etape(): void
    {
        $this->assertSame(2, ApplicationSection::PROFILE->position());
        $this->assertTrue(ApplicationSection::PROFILE->isImplemented());
        $this->assertTrue(ApplicationSection::PROFILE->isOnOpenPath());
    }

    public function test_le_profil_est_sur_le_parcours_ouvert(): void
    {
        // Le trou du parcours a été refermé en Phase 1F : « Structure / équipe »
        // (étape 3) est développée, et « Défi » y est donc revenu. Le détail de
        // cette bascule est couvert par StructureEquipeCandidatTest.
        $this->assertTrue(ApplicationSection::PROFILE->isOnOpenPath());
        $this->assertContains(ApplicationSection::PROFILE, ApplicationSection::openPath());
    }

    public function test_l_eligibilite_mene_au_profil_et_le_profil_a_la_suite(): void
    {
        $this->assertSame(ApplicationSection::PROFILE, ApplicationSection::ELIGIBILITY->nextOnOpenPath());
        $this->assertSame(ApplicationSection::TEAM, ApplicationSection::PROFILE->nextOnOpenPath());

        $this->assertNull(ApplicationSection::ELIGIBILITY->previousImplemented());
        $this->assertSame(ApplicationSection::ELIGIBILITY, ApplicationSection::PROFILE->previousImplemented());
    }

    public function test_l_ecran_annonce_la_navigation_calculee_par_le_serveur(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->get("/candidate/application/{$application->getKey()}/eligibility")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('previousUrl', null)
                ->where('nextUrl', url($this->url($application))));

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Profile')
                ->where('previousUrl', url("/candidate/application/{$application->getKey()}/eligibility"))
                // Depuis la Phase 1F, l'étape 3 existe : le parcours continue.
                ->where('nextUrl', url("/candidate/application/{$application->getKey()}/team")));
    }

    // — Persistance ————————————————————————————————————————————————

    public function test_les_reponses_sont_ecrites_en_base(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed']);

        $ligne = ApplicationSectionAnswers::query()
            ->where('section', ApplicationSection::PROFILE->value)
            ->sole();

        $this->assertSame($application->getKey(), $ligne->application_id);
        $this->assertSame('Tahoua', $ligne->answers[ProfileSection::BIRTH_PLACE]);
        $this->assertSame(NigerRegion::NIAMEY->value, $ligne->answers[ProfileSection::RESIDENCE_REGION]);
        $this->assertSame(EducationLevel::BACHELOR->value, $ligne->answers[ProfileSection::EDUCATION_LEVEL]);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_une_sauvegarde_partielle_est_acceptee_sans_achever_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ProfileSection::OCCUPATION => 'Étudiante'])
            ->assertOk()
            ->assertJsonPath('completed', false);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame('Étudiante', $ligne->answers[ProfileSection::OCCUPATION]);
        $this->assertNull($ligne->answers[ProfileSection::PHONE_PRIMARY]);
        $this->assertNull($ligne->completed_at, 'Ouvrir la page ne vaut pas la remplir.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function champsObligatoires(): array
    {
        return array_combine(
            ProfileSection::REQUIRED_FIELDS,
            array_map(static fn (string $champ): array => [$champ], ProfileSection::REQUIRED_FIELDS),
        );
    }

    #[DataProvider('champsObligatoires')]
    public function test_chaque_champ_obligatoire_manquant_empeche_l_achevement(string $champ): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet([$champ => null]))
            ->assertOk()
            ->assertJsonPath('completed', false);

        $this->assertNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_les_champs_facultatifs_ne_bloquent_pas_l_achevement(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet([
                ProfileSection::GENDER => null,
                ProfileSection::PHONE_SECONDARY => null,
                ProfileSection::SPECIALTY => null,
                ProfileSection::ACCESSIBILITY_NEED => null,
            ]))
            ->assertOk()
            ->assertJsonPath('completed', true);

        $this->assertNotNull(ApplicationSectionAnswers::query()->sole()->completed_at);
    }

    public function test_les_sauvegardes_successives_ne_creent_qu_une_ligne(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        foreach (['Agadez', 'Dosso', 'Zinder'] as $lieu) {
            $this->actingAs($candidat)
                ->patchJson($this->url($application), [ProfileSection::BIRTH_PLACE => $lieu])
                ->assertOk();
        }

        $this->assertSame(1, ApplicationSectionAnswers::query()->count());
        $this->assertSame('Zinder', ApplicationSectionAnswers::query()->sole()->answers[ProfileSection::BIRTH_PLACE]);
    }

    public function test_current_step_bascule_sur_le_profil(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->profilComplet())->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::PROFILE->value,
        ]);
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_un_numero_national_est_normalise_au_format_international(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet([
                ProfileSection::PHONE_PRIMARY => '90.12.34.56',
                ProfileSection::PHONE_SECONDARY => '0022796554433',
            ]))
            ->assertOk();

        $answers = ApplicationSectionAnswers::query()->sole()->answers;

        // Ce qui est stocké est ce que la passerelle SMS saura composer.
        $this->assertSame('+22790123456', $answers[ProfileSection::PHONE_PRIMARY]);
        $this->assertSame('+22796554433', $answers[ProfileSection::PHONE_SECONDARY]);
    }

    public function test_un_numero_etranger_est_conserve_tel_quel(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        // L'éligibilité admet des candidats hors du Niger : le stockage doit
        // rester international.
        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ProfileSection::PHONE_PRIMARY => '+33 6 12 34 56 78'])
            ->assertOk();

        $this->assertSame('+33612345678', ApplicationSectionAnswers::query()->sole()->answers[ProfileSection::PHONE_PRIMARY]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function numerosInvalides(): array
    {
        return [
            'trop court' => ['12345'],
            'lettres' => ['appelez-moi'],
            'indicatif nul' => ['+0123456789'],
            'trop long' => ['+2279012345678901234'],
        ];
    }

    #[DataProvider('numerosInvalides')]
    public function test_un_numero_invalide_est_refuse(string $numero): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [ProfileSection::PHONE_PRIMARY => $numero])
            ->assertStatus(422)
            ->assertJsonValidationErrors(ProfileSection::PHONE_PRIMARY);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_le_second_numero_doit_differer_du_premier(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet([
                ProfileSection::PHONE_PRIMARY => '90123456',
                ProfileSection::PHONE_SECONDARY => '+22790123456',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(ProfileSection::PHONE_SECONDARY);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function valeursHorsReferentiel(): array
    {
        return [
            'region inconnue' => [ProfileSection::RESIDENCE_REGION, 'FR-75'],
            'canal inconnu' => [ProfileSection::PREFERRED_CHANNEL, 'WHATSAPP'],
            'niveau inconnu' => [ProfileSection::EDUCATION_LEVEL, 'POSTDOC'],
            'sexe inconnu' => [ProfileSection::GENDER, 'AUTRE'],
        ];
    }

    #[DataProvider('valeursHorsReferentiel')]
    public function test_une_valeur_hors_referentiel_est_refusee(string $champ, mixed $valeur): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [$champ => $valeur])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function longueursMaximales(): array
    {
        return [
            'lieu de naissance' => [ProfileSection::BIRTH_PLACE, ProfileSection::SHORT_TEXT_MAX],
            'quartier' => [ProfileSection::RESIDENCE_LOCALITY, ProfileSection::SHORT_TEXT_MAX],
            'occupation' => [ProfileSection::OCCUPATION, ProfileSection::SHORT_TEXT_MAX],
            'specialite' => [ProfileSection::SPECIALTY, ProfileSection::SHORT_TEXT_MAX],
            'accessibilite' => [ProfileSection::ACCESSIBILITY_NEED, ProfileSection::LONG_TEXT_MAX],
        ];
    }

    #[DataProvider('longueursMaximales')]
    public function test_un_texte_trop_long_est_refuse(string $champ, int $maximum): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->url($application), [$champ => str_repeat('a', $maximum + 1)])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champ);
    }

    // — Pas de doublon : les données déjà détenues ailleurs ————————

    public function test_la_section_n_ecrit_pas_les_donnees_du_compte_ni_de_l_eligibilite(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        // Requête forgée : le client tente d'écrire l'identité et la date de
        // naissance dans la section « Profil ».
        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet([
                'name' => 'Nom Usurpé',
                'email' => 'ailleurs@example.test',
                EligibilitySection::BIRTH_DATE => '1970-01-01',
            ]))
            ->assertOk();

        $answers = ApplicationSectionAnswers::query()
            ->where('section', ApplicationSection::PROFILE->value)
            ->sole()
            ->answers;

        foreach (['name', 'email', EligibilitySection::BIRTH_DATE] as $intrus) {
            $this->assertArrayNotHasKey($intrus, $answers, "« {$intrus} » a sa source ailleurs et ne doit pas être recopié ici.");
        }

        // Et le compte lui-même n'a pas bougé.
        $this->assertSame($candidat->name, $candidat->fresh()->name);
        $this->assertSame($candidat->email, $candidat->fresh()->email);
    }

    public function test_l_ecran_affiche_les_donnees_deja_connues_sans_les_dupliquer(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson(
            "/candidate/application/{$application->getKey()}/eligibility",
            [
                EligibilitySection::BIRTH_DATE => '1998-04-12',
                EligibilitySection::NIGERIEN_NATIONAL => true,
            ],
        )->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('known.accountName', $candidat->name)
                ->where('known.accountEmail', $candidat->email)
                ->where('known.birthDate', '1998-04-12')
                ->where('known.nigerienNational', true)
                ->where('known.eligibilityUrl', url("/candidate/application/{$application->getKey()}/eligibility"))
                // La section ne détient aucune de ces valeurs : elle les montre.
                ->missing('answers.birth_date')
                ->missing('answers.name'));
    }

    public function test_la_region_de_residence_est_distincte_de_la_zone_d_intervention(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());
        $eligibilite = "/candidate/application/{$application->getKey()}/eligibility";

        // Le §6.1 distingue le lieu de vie du candidat et la zone où le projet
        // agira : deux réponses différentes, deux sections différentes.
        $this->actingAs($candidat)->patchJson($eligibilite, [
            EligibilitySection::INTERVENTION_REGION => NigerRegion::ZINDER->value,
        ])->assertOk();

        $this->actingAs($candidat)->patchJson($this->url($application), [
            ProfileSection::RESIDENCE_REGION => NigerRegion::NIAMEY->value,
        ])->assertOk();

        $sections = ApplicationSectionAnswers::query()->get()->keyBy(fn ($ligne): string => $ligne->section->value);

        $this->assertSame(NigerRegion::ZINDER->value, $sections['eligibility']->answers[EligibilitySection::INTERVENTION_REGION]);
        $this->assertSame(NigerRegion::NIAMEY->value, $sections['profile']->answers[ProfileSection::RESIDENCE_REGION]);
    }

    // — Progression ————————————————————————————————————————————————

    public function test_la_progression_compte_l_eligibilite_puis_le_profil(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson(
            "/candidate/application/{$application->getKey()}/eligibility",
            $this->eligibiliteComplete(),
        )->assertOk();

        $this->assertSame($this->pourcentage(1), (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->patchJson($this->url($application), $this->profilComplet())->assertOk();

        $this->assertSame($this->pourcentage(2), (int) $application->fresh()->completion_percent);
    }

    public function test_les_sept_premieres_etapes_sont_sur_le_parcours_ouvert(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        // Depuis l'ouverture de l'étape 3, « Défi » compte de nouveau : le
        // remplir fait avancer la progression.
        $this->actingAs($candidat)->patchJson(
            "/candidate/application/{$application->getKey()}/challenge",
            [
                ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
                'main_challenge' => 'L’accès à l’eau potable en périphérie.',
                'affected_people' => 'Les ménages non raccordés au réseau.',
                'location' => NigerRegion::NIAMEY->value,
                'root_causes' => 'Une extension urbaine plus rapide que le réseau.',
            ],
        )->assertOk();

        $unSurNeuf = (int) round(1 / ApplicationSection::total() * 100);
        $this->assertSame($unSurNeuf, (int) $application->fresh()->completion_percent);

        $this->actingAs($candidat)->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $etapes = collect($page->toArray()['props']['steps']);

                foreach ([
                    ApplicationSection::ELIGIBILITY,
                    ApplicationSection::PROFILE,
                    ApplicationSection::TEAM,
                    ApplicationSection::CHALLENGE,
                    ApplicationSection::SOLUTION,
                    ApplicationSection::IMPACT,
                    ApplicationSection::IMPLEMENTATION,
                    ApplicationSection::ATTACHMENTS,
                ] as $section) {
                    $this->assertTrue(
                        $etapes->firstWhere('key', $section->value)['onOpenPath'],
                        "L'étape {$section->value} devrait être sur le parcours ouvert.",
                    );
                }

                // L'étape 9 a son écran depuis le correctif de la relecture :
                // le parcours va jusqu'à elle, sans qu'elle enregistre rien.
                $this->assertTrue($etapes->firstWhere('key', ApplicationSection::REVIEW->value)['onOpenPath']);
                $this->assertSame('done', $etapes->firstWhere('key', ApplicationSection::CHALLENGE->value)['state']);
            });
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_les_reponses_survivent_a_un_rechargement(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->url($application), $this->profilComplet())->assertOk();

        $this->actingAs($candidat)->get($this->url($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.'.ProfileSection::OCCUPATION, 'Développeuse indépendante')
                ->where('answers.'.ProfileSection::PHONE_PRIMARY, '+22790123456')
                ->where('answers.'.ProfileSection::PREFERRED_CHANNEL, PreferredChannel::SMS->value));
    }

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();

        $this->patchJson($this->url($application), $this->profilComplet())->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::PROFILE->value)
            ->where('application.continueUrl', url($this->url($application))));

        $this->get($this->url($application))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('answers.'.ProfileSection::RESIDENCE_LOCALITY, 'Yantala Haut')
            ->where('answers.'.ProfileSection::EDUCATION_LEVEL, EducationLevel::BACHELOR->value));
    }

    // — Compatibilité des brouillons antérieurs ————————————————————

    public function test_un_brouillon_ouvert_sur_le_defi_reste_recuperable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        // État d'un dossier créé avant cette phase.
        $application->forceFill(['current_step' => ApplicationSection::CHALLENGE])->save();

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect("/candidate/application/{$application->getKey()}/challenge");

        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Le retour en arrière mène à l'étape 3, section développée la
                // plus proche : le candidat rejoint le parcours sans perdre sa
                // saisie ni se retrouver sur un écran inexistant.
                ->where('previousUrl', url("/candidate/application/{$application->getKey()}/team"))
                // Et la suite existe désormais : l'ouverture de l'étape 5 rend
                // au dossier arrêté au « Défi » un chemin vers l'avant, sans
                // qu'une seule de ses réponses ait été touchée.
                ->where('nextUrl', url("/candidate/application/{$application->getKey()}/solution")));
    }

    public function test_un_brouillon_ancien_conserve_ses_reponses_et_son_etape(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson(
            "/candidate/application/{$application->getKey()}/challenge",
            ['main_challenge' => 'Saisi avant la phase Profil.'],
        )->assertOk();

        $application->forceFill(['current_step' => ApplicationSection::CHALLENGE])->save();

        $this->actingAs($candidat)->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.main_challenge', 'Saisi avant la phase Profil.'));

        $this->assertSame(ApplicationSection::CHALLENGE, $application->fresh()->current_step);
    }

    // — Ownership et cloisonnement ————————————————————————————————

    public function test_un_candidat_ne_lit_pas_le_profil_d_un_autre(): void
    {
        $application = $this->brouillonDe($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())->get($this->url($application))->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_le_profil_d_un_autre(): void
    {
        $application = $this->brouillonDe($this->candidat(), $this->campagne());

        $this->actingAs($this->candidat())
            ->patchJson($this->url($application), $this->profilComplet())
            ->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_visiteur_n_ouvre_pas_le_profil(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->url($application))->assertRedirect('/login');
        $this->patch($this->url($application), $this->profilComplet())->assertRedirect('/login');

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
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
    public function test_un_role_interne_n_ouvre_pas_le_profil_d_un_candidat(UserRole $role): void
    {
        $application = $this->brouillonDe($this->candidat(), $this->campagne());
        $interne = User::factory()->role($role)->create();

        $this->actingAs($interne)->get($this->url($application))->assertForbidden();
        $this->actingAs($interne)->patchJson($this->url($application), $this->profilComplet())->assertForbidden();
    }

    public function test_une_candidature_soumise_n_est_plus_modifiable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)
            ->patchJson($this->url($application), $this->profilComplet())
            ->assertForbidden();

        // La consultation reste ouverte à son propriétaire.
        $this->actingAs($candidat)->get($this->url($application))->assertOk();
    }

    // — Non-régression de l'éligibilité ————————————————————————————

    public function test_le_profil_est_ferme_a_un_candidat_non_eligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $campagne->forceFill(['settings' => ['eligibility' => ['requires_niger_link' => true]]])->save();
        $application = $this->brouillonDe($candidat, $campagne);
        $eligibilite = "/candidate/application/{$application->getKey()}/eligibility";

        $this->actingAs($candidat)->patchJson($eligibilite, $this->eligibiliteComplete([
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]))->assertOk();

        // Même barrière que « Défi » : la règle ne dépend pas de la section.
        $this->actingAs($candidat)->get($this->url($application))->assertRedirect($eligibilite);
        $this->actingAs($candidat)->patchJson($this->url($application), $this->profilComplet())->assertForbidden();

        $this->assertSame(
            0,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::PROFILE->value)->count(),
        );
    }

    public function test_corriger_l_eligibilite_rouvre_le_profil(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $campagne->forceFill(['settings' => ['eligibility' => ['requires_niger_link' => true]]])->save();
        $application = $this->brouillonDe($candidat, $campagne);
        $eligibilite = "/candidate/application/{$application->getKey()}/eligibility";

        $this->actingAs($candidat)->patchJson($eligibilite, $this->eligibiliteComplete([
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]))->assertOk();

        $this->actingAs($candidat)->get($this->url($application))->assertRedirect($eligibilite);

        $this->actingAs($candidat)->patchJson($eligibilite, $this->eligibiliteComplete())->assertOk();

        $this->actingAs($candidat)->get($this->url($application))->assertOk();
    }

    /**
     * Réponses d'éligibilité ne déclenchant aucune règle bloquante.
     *
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function eligibiliteComplete(array $remplacements = []): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
            ...$remplacements,
        ];
    }

    /** Pourcentage attendu pour n sections achevées sur les neuf. */
    private function pourcentage(int $sections): int
    {
        return (int) round($sections / ApplicationSection::total() * 100);
    }
}
