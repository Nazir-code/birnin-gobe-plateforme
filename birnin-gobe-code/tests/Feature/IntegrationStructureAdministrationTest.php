<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\TeamSection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Candidate\EducationLevel;
use App\Domain\Candidate\PreferredChannel;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Point de rencontre entre l'étape 3 du candidat et l'écran de consultation de
 * l'administration.
 *
 * Chacune des deux fonctionnalités a sa propre suite. Ce fichier ne les répète
 * pas : il vérifie ce qu'aucune des deux ne peut vérifier seule — que le
 * candidat et l'administration racontent **le même dossier**, avec le même
 * pourcentage, et que la section ouverte par la phase 1F apparaît telle quelle
 * dans le back-office.
 *
 * Tout passe par les vraies routes des deux espaces. Aucune écriture directe en
 * base, hormis la fabrication d'un brouillon « d'avant l'étape 3 » — qu'aucune
 * route ne sait plus produire, puisque l'étape existe désormais.
 */
final class IntegrationStructureAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    private function campagne(): Campaign
    {
        return Campaign::factory()->create();
    }

    /** @return array<string, mixed> */
    private function eligibilite(CandidateType $type, ?int $effectif = null): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => $type->value,
            EligibilitySection::TEAM_SIZE => $effectif,
        ];
    }

    /** @return array<string, mixed> */
    private function profil(): array
    {
        return [
            ProfileSection::BIRTH_PLACE => 'Zinder',
            ProfileSection::PHONE_PRIMARY => '90123456',
            ProfileSection::PREFERRED_CHANNEL => PreferredChannel::SMS->value,
            ProfileSection::RESIDENCE_REGION => NigerRegion::NIAMEY->value,
            ProfileSection::RESIDENCE_LOCALITY => 'Yantala',
            ProfileSection::OCCUPATION => 'Développeuse',
            ProfileSection::EDUCATION_LEVEL => EducationLevel::BACHELOR->value,
        ];
    }

    /** @return array<string, mixed> */
    private function membre(string $nom, string $role): array
    {
        return [
            TeamSection::MEMBER_NAME => $nom,
            TeamSection::MEMBER_ROLE => $role,
            TeamSection::MEMBER_EMAIL => strtolower(str_replace(' ', '.', $nom)).'@example.test',
            TeamSection::MEMBER_PHONE => '',
            TeamSection::MEMBER_SKILLS => 'Cartographie',
            TeamSection::MEMBER_AVAILABILITY => 'Temps partiel',
            TeamSection::MEMBER_IS_FOUNDER => false,
            TeamSection::MEMBER_CONSENT => true,
        ];
    }

    /** @return array<string, mixed> */
    private function defi(): array
    {
        return [
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
            'main_challenge' => 'L’accès à l’eau potable dans les quartiers périphériques.',
            'affected_people' => 'Les ménages non raccordés au réseau.',
            'root_causes' => 'Un réseau qui n’a pas suivi l’extension urbaine.',
            'location' => NigerRegion::NIAMEY->value,
        ];
    }

    /** Ouvre un brouillon par la vraie route et rend la candidature. */
    private function brouillon(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    private function enregistrer(User $candidat, Application $dossier, string $section, array $reponses): void
    {
        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/{$section}", $reponses)
            ->assertOk();
    }

    private function pourcentage(int $sections): int
    {
        return (int) round($sections / ApplicationSection::total() * 100);
    }

    // — Le parcours complet, vu des deux côtés ————————————————————

    /**
     * Le cœur de cette intégration : un seul calcul d'avancement.
     *
     * Le candidat remplit les quatre étapes ouvertes, puis le même dossier est
     * lu par le tableau de bord candidat, par la liste d'administration et par
     * le détail d'administration. Les trois doivent annoncer le même
     * pourcentage — non pas parce qu'ils sont d'accord par hasard, mais parce
     * qu'ils interrogent tous `ApplicationProgress`.
     */
    public function test_les_trois_ecrans_annoncent_le_meme_avancement(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $etapes = [
            ['eligibility', $this->eligibilite(CandidateType::TEAM, 3), 1],
            ['profile', $this->profil(), 2],
            ['team', [TeamSection::MEMBERS => [$this->membre('Ibrahim Moussa', 'Technicien'), $this->membre('Amina Issa', 'Chargée de terrain')]], 3],
            ['challenge', $this->defi(), 4],
        ];

        foreach ($etapes as [$section, $reponses, $attendues]) {
            $this->enregistrer($candidat, $dossier, $section, $reponses);

            $this->assertSame(
                $attendues,
                app(ApplicationProgress::class)->completedOnOpenPath($dossier->fresh()),
                "Après « {$section} », le domaine devrait compter {$attendues} sections achevées.",
            );
        }

        $attendu = $this->pourcentage(4);

        // 1. Le tableau de bord du candidat
        $this->actingAs($candidat)->get('/candidate/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $attendu));

        $administrateur = $this->admin();

        // 2. La liste d'administration, dont le compte vient d'un `withCount`
        $this->actingAs($administrateur)->get('/admin/applications')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.completionPercent', $attendu)
                ->where('applications.0.completedSections', 4));

        // 3. Le détail d'administration, qui compte sur la relation chargée
        $this->actingAs($administrateur)->get("/admin/applications/{$dossier->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.completionPercent', $attendu)
                ->where('application.completedSections', 4));
    }

    /**
     * Le parcours ouvert va sans trou de l'étape 1 à l'étape 7.
     *
     * Ce test suivait l'étape 4 quand elle fermait la marche ; l'ouverture des
     * étapes 5 à 7 l'a prolongé jusqu'au plan de mise en œuvre. Ce qu'il vérifie
     * n'a pas changé : le parcours est continu, et la première étape encore
     * fermée en marque honnêtement la fin.
     */
    public function test_le_parcours_ouvert_va_de_l_etape_1_a_l_etape_9(): void
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

        // La relecture ferme le parcours : elle a un écran, et rien ne la suit.
        $this->assertTrue(ApplicationSection::REVIEW->isOnOpenPath());
        $this->assertNull(ApplicationSection::REVIEW->nextOnOpenPath());
    }

    // — Structure vue par l'administration ————————————————————————

    /**
     * Une candidature en équipe : l'administration voit les membres, nommés,
     * avec leur rôle et leur consentement — et ne peut rien y changer.
     */
    public function test_une_equipe_montre_ses_membres_dans_le_detail(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::TEAM, 3));
        $this->enregistrer($candidat, $dossier, 'team', [
            TeamSection::MEMBERS => [
                $this->membre('Ibrahim Moussa', 'Technicien'),
                $this->membre('Amina Issa', 'Chargée de terrain'),
            ],
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.2.key', ApplicationSection::TEAM->value)
                ->where('application.sections.2.state', 'complete')
                ->has('application.sections.2.members', 2)
                ->where('application.sections.2.members.0.name', 'Ibrahim Moussa')
                ->where('application.sections.2.members.0.role', 'Technicien')
                ->where('application.sections.2.members.0.consent', true)
                // La synthèse vient de `TeamSectionAssessment`, pas d'un second
                // calcul : effectif déclaré à l'étape 1, effectif décrit ici.
                ->where('application.sections.2.team.typeLabel', CandidateType::TEAM->label())
                ->where('application.sections.2.team.declaredSize', 3)
                ->where('application.sections.2.team.describedSize', 3)
                ->where('application.sections.2.team.sizeMismatch', false));
    }

    /**
     * Une candidature individuelle : la section est complète sans le moindre
     * membre, et l'administration n'invente pas d'équipe.
     */
    public function test_une_candidature_individuelle_n_affiche_aucune_equipe(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::INDIVIDUAL));
        // Aucun champ : c'est bien le propre de cette variante, et la sauvegarde
        // explicite doit tout de même achever la section.
        $this->enregistrer($candidat, $dossier, 'team', []);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.2.state', 'complete')
                ->has('application.sections.2.members', 0)
                ->where('application.sections.2.team.typeLabel', CandidateType::INDIVIDUAL->label())
                ->where('application.sections.2.team.describedSize', 1)
                // Aucun champ de structure : « Startup » seule en a une.
                ->has('application.sections.2.fields', 0));
    }

    /** Une startup décrit sa structure ; ses champs sont lisibles, pas du JSON. */
    public function test_une_startup_montre_sa_structure_en_clair(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::STARTUP, 2));
        $this->enregistrer($candidat, $dossier, 'team', [
            TeamSection::STRUCTURE_NAME => 'Sahel Waters',
            TeamSection::STRUCTURE_FOUNDED_YEAR => 2024,
            TeamSection::STRUCTURE_SECTOR => 'Eau et assainissement',
            TeamSection::STRUCTURE_ADDRESS => 'Quartier Yantala, Niamey',
            TeamSection::MEMBERS => [$this->membre('Ibrahim Moussa', 'Directeur technique')],
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.2.fields.0.label', 'Dénomination')
                ->where('application.sections.2.fields.0.value', 'Sahel Waters')
                ->where('application.sections.2.fields.1.label', 'Année de création')
                ->where('application.sections.2.fields.1.value', '2024')
                ->has('application.sections.2.members', 1));
    }

    // — Le type de candidature garde une source unique ————————————

    /**
     * « Structure / équipe » ne crée pas une seconde source du type de
     * candidature : la liste d'administration continue de le lire dans les
     * réponses de l'étape 1, et son filtre aussi.
     */
    public function test_le_type_de_candidature_vient_toujours_de_l_etape_1(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::TEAM, 2));
        $this->enregistrer($candidat, $dossier, 'team', [TeamSection::MEMBERS => [$this->membre('Ibrahim Moussa', 'Technicien')]]);

        $this->actingAs($this->admin())
            ->get('/admin/applications?type='.CandidateType::TEAM->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.candidateTypeLabel', CandidateType::TEAM->label())
                ->where('applications.0.regionLabel', NigerRegion::NIAMEY->label()));
    }

    // — Brouillon d'avant l'étape 3 ————————————————————————————————

    /**
     * Un dossier commencé avant l'ouverture de l'étape 3.
     *
     * Il porte « Éligibilité », « Profil » et « Défi » achevés, aucune ligne
     * « Structure », un `current_step` pointant sur « Défi » et un
     * `completion_percent` calculé sous l'ancienne règle — où « Défi » ne
     * comptait pas.
     *
     * Deux propriétés sont vérifiées ici, et elles sont indépendantes :
     *
     *  1. **« Défi » est repris sans rien réécrire.** L'ouverture de l'étape 3
     *     l'a fait entrer dans le parcours ; le compte le reflète immédiatement,
     *     des deux côtés, sans sauvegarde, sans migration et sans que le
     *     `completed_at` d'origine bouge.
     *  2. **Le cache périmé n'est jamais affiché.** La colonne vaut encore
     *     l'ancien pourcentage ; ni le candidat ni l'administration ne le
     *     montrent.
     *
     * Note sur le compte attendu : la règle d'ADR-009 est un **ensemble** —
     * « sections achevées qui appartiennent au parcours ouvert » — et non un
     * préfixe. Trois sections achevées sur le parcours donnent donc 3/9 dès
     * l'ouverture de l'étape 3, et non 2/9 : « Défi » est repris à cet
     * instant-là, pas à la complétion de « Structure ».
     */
    public function test_un_ancien_brouillon_reprend_son_defi_des_l_ouverture_de_l_etape_3(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::INDIVIDUAL));
        $this->enregistrer($candidat, $dossier, 'profile', $this->profil());
        $this->enregistrer($candidat, $dossier, 'challenge', $this->defi());

        $defi = ApplicationSectionAnswers::query()
            ->where('application_id', $dossier->getKey())
            ->where('section', ApplicationSection::CHALLENGE->value)
            ->sole();
        $acheveLe = $defi->completed_at;

        // On remet le dossier dans l'état qu'il aurait eu avant l'étape 3 :
        // aucune section « Structure », et un cache calculé sans « Défi ».
        ApplicationSectionAnswers::query()
            ->where('application_id', $dossier->getKey())
            ->where('section', ApplicationSection::TEAM->value)
            ->delete();

        $dossier->forceFill([
            'current_step' => ApplicationSection::CHALLENGE,
            'completion_percent' => $this->pourcentage(2),
        ])->save();

        $administrateur = $this->admin();

        // — Avant que « Structure » soit remplie : trois sections comptent.
        $this->actingAs($candidat)->get('/candidate/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $this->pourcentage(3)));

        $this->actingAs($administrateur)->get('/admin/applications')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.completedSections', 3)
                ->where('applications.0.completionPercent', $this->pourcentage(3)));

        $this->actingAs($administrateur)->get("/admin/applications/{$dossier->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.2.state', 'non-commencee')
                ->where('application.sections.3.state', 'complete')
                ->where('application.completionPercent', $this->pourcentage(3)));

        // — Le candidat remplit enfin l'étape 3.
        $this->enregistrer($candidat, $dossier, 'team', []);

        $this->actingAs($administrateur)->get("/admin/applications/{$dossier->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.2.state', 'complete')
                ->where('application.sections.3.state', 'complete')
                ->where('application.completionPercent', $this->pourcentage(4)));

        $this->actingAs($candidat)->get('/candidate/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $this->pourcentage(4)));

        // — Et « Défi » n'a pas été retouché : même date d'achèvement.
        $this->assertEquals($acheveLe, $defi->fresh()->completed_at);
    }

    /**
     * Le cache ne remonte jamais à l'écran, ni chez le candidat ni chez
     * l'administration : la colonne est délibérément fausse ici.
     */
    public function test_le_cache_perime_n_est_affiche_nulle_part(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::INDIVIDUAL));
        $dossier->forceFill(['completion_percent' => 99])->save();

        $administrateur = $this->admin();
        $attendu = $this->pourcentage(1);

        $this->actingAs($candidat)->get('/candidate/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $attendu));

        $this->actingAs($administrateur)->get('/admin/applications')->assertOk()
            ->assertInertia(fn ($page) => $page->where('applications.0.completionPercent', $attendu));

        $this->actingAs($administrateur)->get("/admin/applications/{$dossier->getKey()}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $attendu));

        // La colonne, elle, n'a pas été corrigée en douce : c'est un cache, il
        // se rafraîchira à la prochaine sauvegarde du candidat.
        $this->assertSame(99, (int) $dossier->fresh()->completion_percent);
    }

    /** L'administration ne peut pas écrire, pas même sur la nouvelle section. */
    public function test_l_administration_ne_peut_pas_modifier_la_structure(): void
    {
        $campagne = $this->campagne();
        $candidat = User::factory()->create();
        $dossier = $this->brouillon($candidat, $campagne);

        $this->enregistrer($candidat, $dossier, 'eligibility', $this->eligibilite(CandidateType::TEAM, 2));
        $this->enregistrer($candidat, $dossier, 'team', [TeamSection::MEMBERS => [$this->membre('Ibrahim Moussa', 'Technicien')]]);

        $administrateur = $this->admin();

        // Aucune route d'écriture sur l'espace d'administration…
        $this->actingAs($administrateur)
            ->patch("/admin/applications/{$dossier->getKey()}", [TeamSection::MEMBERS => []])
            ->assertMethodNotAllowed();

        // …et la route candidat lui reste fermée : il n'est pas candidat.
        $this->actingAs($administrateur)
            ->patchJson("/candidate/application/{$dossier->getKey()}/team", [TeamSection::MEMBERS => []])
            ->assertForbidden();

        $this->assertCount(
            1,
            $dossier->fresh()->sectionAnswers(ApplicationSection::TEAM)->answers[TeamSection::MEMBERS],
        );
    }
}
