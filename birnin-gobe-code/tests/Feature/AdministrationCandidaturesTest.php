<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilityOutcome;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Consultation des candidatures par l'administration (Admin Phase 3).
 *
 * Ce que cette suite protège, dans l'ordre :
 *
 * 1. **L'espace reste étanche.** Candidat, évaluateur et jury n'entrent pas ;
 *    un visiteur est renvoyé vers l'accès interne.
 *
 * 2. **La liste ne ment pas.** Recherche, filtres et tri s'appliquent dans
 *    PostgreSQL, avant le découpage en pages : un filtre appliqué après
 *    pagination rendrait des pages inégales et un total faux.
 *
 * 3. **Les nombres affichés viennent du domaine.** La progression est celle
 *    d'`ApplicationProgress` — la règle du candidat — et le verdict celui
 *    d'`EvaluateEligibility`, rendu sur les critères de la campagne **du
 *    dossier**. L'administration n'a pas sa propre arithmétique.
 *
 * 4. **Rien ne s'écrit.** Aucune route d'écriture n'existe sur cet espace.
 */
final class AdministrationCandidaturesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    /** Les cinq critères renseignés : le seul état où `ELIGIBLE` est atteignable. */
    private function reglesCompletes(): array
    {
        return [
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->addMonths(2)->format('Y-m-d')],
            'requires_niger_link' => true,
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
            'candidate_types' => array_map(static fn (CandidateType $t): string => $t->value, CandidateType::cases()),
            'team_size' => ['min' => 2, 'max' => 10],
        ];
    }

    private function campagne(array $eligibilite = [], bool $ouverte = false): Campaign
    {
        // `draft()` explicite : l'invariant d'ADR-008 interdit deux campagnes
        // ouvertes, et plusieurs de ces tests en créent deux ou trois.
        $fabrique = Campaign::factory();
        $campagne = ($ouverte ? $fabrique : $fabrique->draft())->create();

        $campagne->forceFill(['settings' => $eligibilite === [] ? [] : ['eligibility' => $eligibilite]])->save();

        return $campagne->fresh();
    }

    /** @return array<string, mixed> */
    private function reponsesEligibilite(array $remplacements = []): array
    {
        return array_merge([
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
        ], $remplacements);
    }

    /** Un dossier complet côté éligibilité, rattaché à une campagne donnée. */
    private function dossier(Campaign $campagne, array $reponses = [], array $attributs = []): Application
    {
        return Application::factory()
            ->for($campagne)
            ->for(User::factory(), 'candidate')
            ->withSection(ApplicationSection::ELIGIBILITY, $this->reponsesEligibilite($reponses))
            ->create($attributs);
    }

    // — Accès ——————————————————————————————————————————————————————

    public function test_un_admin_voit_la_liste_des_candidatures(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), ouverte: true);
        $dossier = $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Applications/Index')
                ->where('pagination.total', 1)
                ->where('applications.0.id', $dossier->getKey())
                ->where('applications.0.candidateName', $dossier->candidate->name)
                ->where('applications.0.campaignCode', $campagne->code)
                ->where('applications.0.eligibility.outcome', EligibilityOutcome::ELIGIBLE->value));
    }

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/applications')->assertRedirect('/admin/login');
        $this->get('/admin/applications/1')->assertRedirect('/admin/login');
    }

    /**
     * Ni candidat, ni évaluateur, ni jury.
     *
     * Les deux derniers auront leur propre espace ; en attendant, appartenir à
     * l'organisation ne donne pas accès au back-office (ADR-003).
     */
    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $campagne = $this->campagne(ouverte: true);
        $dossier = $this->dossier($campagne);

        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/applications')->assertForbidden();
        $this->actingAs($utilisateur)->get("/admin/applications/{$dossier->getKey()}")->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function rolesSansAcces(): array
    {
        return [
            'candidat' => [UserRole::CANDIDATE->value],
            'évaluateur' => [UserRole::EVALUATOR->value],
            'jury' => [UserRole::JURY->value],
        ];
    }

    public function test_un_dossier_inexistant_rend_404(): void
    {
        $this->actingAs($this->admin())->get('/admin/applications/999999')->assertNotFound();
    }

    /**
     * L'espace est en lecture seule, et cela se vérifie sur les routes.
     *
     * Pas un contrôle applicatif que l'on pourrait oublier : les verbes
     * d'écriture n'existent pas, le routeur répond 405.
     */
    #[DataProvider('verbesDEcriture')]
    public function test_aucune_ecriture_n_est_routable(string $verbe): void
    {
        $dossier = $this->dossier($this->campagne(ouverte: true));

        $this->actingAs($this->admin())
            ->call($verbe, "/admin/applications/{$dossier->getKey()}", [
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            ])
            ->assertMethodNotAllowed();

        // Et les réponses n'ont pas bougé.
        $this->assertSame(
            CandidateType::INDIVIDUAL->value,
            $dossier->fresh()->sectionAnswers(ApplicationSection::ELIGIBILITY)->answers[EligibilitySection::CANDIDATE_TYPE],
        );
    }

    /** @return array<string, array{string}> */
    public static function verbesDEcriture(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    /** Ouvrir un dossier n'est pas une décision : le journal d'audit ne bouge pas. */
    public function test_la_consultation_n_ecrit_pas_au_journal(): void
    {
        $dossier = $this->dossier($this->campagne(ouverte: true));
        $avant = AuditEvent::query()->count();

        $this->actingAs($this->admin())->get('/admin/applications')->assertOk();
        $this->actingAs($this->admin())->get("/admin/applications/{$dossier->getKey()}")->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }

    // — Pagination ——————————————————————————————————————————————————

    public function test_la_liste_est_paginee_par_le_serveur(): void
    {
        $campagne = $this->campagne(ouverte: true);

        for ($i = 0; $i < 26; $i++) {
            $this->dossier($campagne);
        }

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 26)
                ->where('pagination.perPage', 25)
                ->where('pagination.lastPage', 2)
                ->has('applications', 25));

        $this->actingAs($this->admin())
            ->get('/admin/applications?page=2')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.currentPage', 2)
                ->has('applications', 1));
    }

    /**
     * Les filtres survivent au changement de page.
     *
     * Sans cela, la page 2 rendrait la liste entière et l'administrateur
     * croirait à un bug — ou pire, ne le verrait pas.
     */
    public function test_les_filtres_survivent_a_la_pagination(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $autre = $this->campagne();

        for ($i = 0; $i < 26; $i++) {
            $this->dossier($campagne);
        }
        $this->dossier($autre);

        $this->actingAs($this->admin())
            ->get('/admin/applications?campaign='.$campagne->getKey())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 26)
                ->where('filters.campaign', (string) $campagne->getKey())
                ->where('pagination.nextUrl', fn (?string $url): bool => $url !== null
                    && str_contains($url, 'campaign='.$campagne->getKey())));
    }

    // — Recherche ————————————————————————————————————————————————————

    public function test_la_recherche_porte_sur_le_nom_du_candidat(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $cherche = Application::factory()
            ->for($campagne)
            ->for(User::factory()->create(['name' => 'Hadiza Souley']), 'candidate')
            ->create();
        Application::factory()
            ->for($campagne)
            ->for(User::factory()->create(['name' => 'Ibrahim Moussa']), 'candidate')
            ->create();

        $this->actingAs($this->admin())
            ->get('/admin/applications?q=hadiza')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $cherche->getKey()));
    }

    public function test_la_recherche_porte_sur_l_adresse_du_candidat(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $cherche = Application::factory()
            ->for($campagne)
            ->for(User::factory()->create(['email' => 'hadiza.souley@example.test']), 'candidate')
            ->create();
        Application::factory()
            ->for($campagne)
            ->for(User::factory()->create(['email' => 'ibrahim@example.test']), 'candidate')
            ->create();

        $this->actingAs($this->admin())
            ->get('/admin/applications?q=souley@example')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $cherche->getKey()));
    }

    /**
     * Le numéro de dossier est cherché bien qu'il soit encore toujours nul.
     *
     * `SubmitApplication` n'est câblé à aucune route : aucun dossier n'en porte
     * aujourd'hui. La colonne existe, elle est unique, et la recherche doit
     * fonctionner le jour où la soumission l'alimentera — ce test la fixe en
     * écrivant directement la colonne, faute de workflow pour le faire.
     */
    public function test_la_recherche_porte_sur_le_numero_de_dossier(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $cherche = $this->dossier($campagne);
        $cherche->forceFill(['submission_number' => 'BG-2026-000042'])->save();
        $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications?q=000042')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.submissionNumber', 'BG-2026-000042'));
    }

    /** Le contenu des réponses n'est pas fouillé : ce serait de la coïncidence, pas de la recherche. */
    public function test_la_recherche_ne_fouille_pas_les_reponses(): void
    {
        $campagne = $this->campagne(ouverte: true);

        Application::factory()
            ->for($campagne)
            ->for(User::factory()->create(['name' => 'Hadiza Souley']), 'candidate')
            ->withSection(ApplicationSection::PROFILE, [ProfileSection::OCCUPATION => 'Agronome'])
            ->create();

        $this->actingAs($this->admin())
            ->get('/admin/applications?q=Agronome')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pagination.total', 0));
    }

    // — Filtres ——————————————————————————————————————————————————————

    public function test_le_filtre_par_campagne(): void
    {
        $ouverte = $this->campagne(ouverte: true);
        $close = $this->campagne();

        $attendu = $this->dossier($ouverte);
        $this->dossier($close);

        $this->actingAs($this->admin())
            ->get('/admin/applications?campaign='.$ouverte->getKey())
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $attendu->getKey()));
    }

    public function test_le_filtre_par_statut(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $this->dossier($campagne);
        $soumise = $this->dossier($campagne, attributs: ['status' => ApplicationStatus::SUBMITTED]);

        $this->actingAs($this->admin())
            ->get('/admin/applications?status='.ApplicationStatus::SUBMITTED->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $soumise->getKey()));
    }

    /**
     * Le type de candidature n'a pas de colonne : c'est une réponse, rangée dans
     * le `jsonb` de la section « Éligibilité ». Le filtre est donc porté par
     * PostgreSQL, et ce test le prouve — un filtrage en PHP après pagination
     * rendrait le total faux.
     */
    public function test_le_filtre_par_forme_de_candidature(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $this->dossier($campagne);
        $equipe = $this->dossier($campagne, [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 4,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/applications?type='.CandidateType::TEAM->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $equipe->getKey())
                ->where('applications.0.candidateTypeLabel', CandidateType::TEAM->label()));
    }

    public function test_le_filtre_par_zone_d_intervention(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $this->dossier($campagne);
        $agadez = $this->dossier($campagne, [EligibilitySection::INTERVENTION_REGION => NigerRegion::AGADEZ->value]);

        $this->actingAs($this->admin())
            ->get('/admin/applications?region='.NigerRegion::AGADEZ->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $agadez->getKey())
                ->where('applications.0.regionLabel', NigerRegion::AGADEZ->label()));
    }

    /** Un dossier sans section « Éligibilité » ne remonte sous aucun filtre de réponse. */
    public function test_un_dossier_sans_reponses_ne_remonte_pas_sous_un_filtre_de_reponse(): void
    {
        $campagne = $this->campagne(ouverte: true);
        Application::factory()->for($campagne)->for(User::factory(), 'candidate')->create();

        $this->actingAs($this->admin())
            ->get('/admin/applications?region='.NigerRegion::NIAMEY->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('pagination.total', 0));
    }

    /**
     * Une valeur hors référentiel est ignorée, pas rejetée.
     *
     * Un écran de consultation dont un paramètre a été tronqué doit s'ouvrir. Ce
     * qui compte est que la valeur n'atteigne pas le SQL : la liste revient
     * entière et le formulaire réaffiche le filtre vide.
     */
    #[DataProvider('filtresIllisibles')]
    public function test_un_filtre_illisible_est_ignore(string $parametre, string $clefRendue): void
    {
        $campagne = $this->campagne(ouverte: true);
        $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications?'.$parametre)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('filters.'.$clefRendue, ''));
    }

    /** @return array<string, array{string, string}> */
    public static function filtresIllisibles(): array
    {
        return [
            'statut inconnu' => ['status=PAS_UN_STATUT', 'status'],
            'forme inconnue' => ['type=ASSOCIATION', 'type'],
            'région inconnue' => ['region=NE-99', 'region'],
            'campagne non numérique' => ['campaign=abc', 'campaign'],
            'campagne à zéro' => ['campaign=0', 'campaign'],
        ];
    }

    // — Tri ————————————————————————————————————————————————————————

    public function test_le_tri_par_nom_de_candidat(): void
    {
        $campagne = $this->campagne(ouverte: true);

        foreach (['Zeinabou Adamou', 'Amina Issa', 'Moussa Barry'] as $nom) {
            Application::factory()
                ->for($campagne)
                ->for(User::factory()->create(['name' => $nom]), 'candidate')
                ->create();
        }

        $this->actingAs($this->admin())
            ->get('/admin/applications?sort=nom')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.candidateName', 'Amina Issa')
                ->where('applications.2.candidateName', 'Zeinabou Adamou'));
    }

    public function test_le_tri_par_progression(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $vide = Application::factory()->for($campagne)->for(User::factory(), 'candidate')->create();
        $avance = $this->dossier($campagne);
        $avance->sections()->create([
            'section' => ApplicationSection::PROFILE->value,
            'answers' => [ProfileSection::BIRTH_PLACE => 'Zinder'],
            'completed_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/applications?sort=progression')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.id', $avance->getKey())
                ->where('applications.0.completedSections', 2)
                ->where('applications.1.id', $vide->getKey())
                ->where('applications.1.completedSections', 0));
    }

    public function test_le_tri_du_plus_ancien_inverse_la_liste(): void
    {
        $campagne = $this->campagne(ouverte: true);

        $ancien = $this->dossier($campagne);
        $ancien->forceFill(['updated_at' => now()->subDays(10)])->save();
        $recent = $this->dossier($campagne);
        $recent->forceFill(['updated_at' => now()])->save();

        $this->actingAs($this->admin())
            ->get('/admin/applications?sort=ancien')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('applications.0.id', $ancien->getKey()));

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('applications.0.id', $recent->getKey()));
    }

    /**
     * Un tri hors liste blanche retombe sur le tri par défaut.
     *
     * `sort` désigne une intention, jamais une colonne : aucune valeur venue de
     * l'URL n'atteint le SQL.
     */
    public function test_un_tri_hors_liste_blanche_est_ignore(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications?sort=candidate_id+desc--')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'recent')
                ->where('pagination.total', 1));
    }

    // — États vides ————————————————————————————————————————————————

    public function test_les_deux_etats_vides_sont_distingues(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totalWithoutFilters', 0)
                ->where('hasActiveFilters', false)
                ->has('applications', 0));

        $campagne = $this->campagne(ouverte: true);
        $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications?region='.NigerRegion::ZINDER->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('totalWithoutFilters', 1)
                ->where('hasActiveFilters', true)
                ->has('applications', 0));
    }

    // — Détail ——————————————————————————————————————————————————————

    public function test_le_detail_montre_le_dossier_et_ses_sections(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), ouverte: true);
        $dossier = $this->dossier($campagne);
        $dossier->sections()->create([
            'section' => ApplicationSection::PROFILE->value,
            'answers' => [
                ProfileSection::BIRTH_PLACE => 'Zinder',
                ProfileSection::PHONE_PRIMARY => '+22790123456',
            ],
            'completed_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Applications/Show')
                ->where('application.candidate.name', $dossier->candidate->name)
                ->where('application.campaign.code', $campagne->code)
                ->where('application.eligibility.outcome', EligibilityOutcome::ELIGIBLE->value)
                // Les neuf sections, toujours : la boucle porte sur l'enum, donc
                // une section ajoutée plus tard apparaîtra sans rien changer ici.
                ->has('application.sections', ApplicationSection::total())
                ->where('application.sections.0.key', ApplicationSection::ELIGIBILITY->value)
                ->where('application.sections.0.state', 'complete')
                ->where('application.sections.1.key', ApplicationSection::PROFILE->value)
                ->where('application.sections.1.state', 'incomplete')
                // L'etape 3 est developpee depuis l'ouverture de « Structure /
                // equipe » : ce dossier ne l'a simplement pas commencee.
                ->where('application.sections.2.key', ApplicationSection::TEAM->value)
                ->where('application.sections.2.state', 'non-commencee')
                ->where('application.sections.2.implemented', true)
                // Plus aucune etape n'est fermee : la neuvieme a son ecran
                // depuis le correctif de la relecture. Elle ressort donc
                // « non commencee » — elle n'enregistre rien, et n'aura jamais
                // de ligne à son nom.
                ->where('application.sections.8.key', ApplicationSection::REVIEW->value)
                ->where('application.sections.8.implemented', true)
                ->where('application.sections.8.state', 'non-commencee'));
    }

    /** Les réponses sortent en couples lisibles, jamais en JSON brut. */
    public function test_le_detail_traduit_les_reponses(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), ouverte: true);
        $dossier = $this->dossier($campagne, [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 4,
        ]);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.sections.0.fields.1.label', 'Nationalité nigérienne')
                ->where('application.sections.0.fields.1.value', 'Oui')
                ->where('application.sections.0.fields.3.value', NigerRegion::NIAMEY->label())
                ->where('application.sections.0.fields.4.value', CandidateType::TEAM->label()));
    }

    /**
     * Le dossier est jugé par SA campagne, jamais par la campagne active.
     *
     * L'édition sous laquelle le dossier a été déposé n'accepte pas les équipes ;
     * une seconde édition, ouverte plus tard, les accepte. Le verdict du dossier
     * ne doit pas bouger.
     */
    public function test_le_detail_juge_selon_la_campagne_du_dossier(): void
    {
        $sienne = $this->campagne([
            ...$this->reglesCompletes(),
            'candidate_types' => [CandidateType::INDIVIDUAL->value],
        ]);

        $dossier = $this->dossier($sienne, [
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 4,
        ]);

        // Une autre édition, ouverte, accepte les équipes : elle ne doit rien
        // changer au verdict de ce dossier.
        $this->campagne($this->reglesCompletes(), ouverte: true);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.campaign.code', $sienne->code)
                ->where('application.eligibility.outcome', EligibilityOutcome::INELIGIBLE->value));
    }

    /** Une campagne sans critères publiés laisse le dossier « sous réserve ». */
    public function test_une_campagne_sans_criteres_donne_a_confirmer(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $dossier = $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.eligibility.outcome', EligibilityOutcome::TO_CONFIRM->value)
                ->has('application.eligibility.findings', 5)
                ->where('application.eligibility.findings.0.rule', 'AGE')
                ->where('application.eligibility.findings.0.status', 'NOT_CONFIGURED'));
    }

    /** Les cinq règles sont rendues par le moteur, avec leur état et leur motif. */
    public function test_le_detail_montre_les_cinq_regles(): void
    {
        $campagne = $this->campagne([
            ...$this->reglesCompletes(),
            'regions' => [NigerRegion::AGADEZ->value],
        ], ouverte: true);

        $dossier = $this->dossier($campagne);

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.eligibility.outcome', EligibilityOutcome::INELIGIBLE->value)
                ->where('application.eligibility.findings.2.rule', 'ZONE')
                ->where('application.eligibility.findings.2.status', 'BLOCKING')
                ->where('application.eligibility.findings.2.message', fn (string $m): bool => str_contains($m, 'Agadez'))
                ->where('application.eligibility.findings.0.status', 'SATISFIED'));
    }

    // — Progression ————————————————————————————————————————————————

    /**
     * L'administration compte comme le candidat.
     *
     * Le nombre affiché est confronté à `ApplicationProgress`, la règle du
     * domaine : si l'un des deux dérivait, ce test le dirait.
     */
    public function test_la_progression_est_celle_du_domaine(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $dossier = $this->dossier($campagne);
        $dossier->sections()->create([
            'section' => ApplicationSection::PROFILE->value,
            'answers' => [ProfileSection::BIRTH_PLACE => 'Zinder'],
            'completed_at' => now(),
        ]);

        $attendu = app(ApplicationProgress::class)->percent($dossier->fresh());

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.completionPercent', $attendu)
                ->where('applications.0.completedSections', 2)
                ->where('applications.0.totalSections', ApplicationSection::total()));

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.completionPercent', $attendu));
    }

    /**
     * Une section hors du parcours ouvert ne gonfle pas le pourcentage — côté
     * administration comme côté candidat (ADR-009).
     *
     * Le contre-exemple change de section à chaque ouverture d'étape, et c'est
     * précisément ce que cette intégration doit suivre : « Défi » a servi tant
     * que l'étape 3 n'existait pas, « Solution » tant que l'étape 5 n'existait
     * pas. Depuis l'ouverture des étapes 5 à 7, la première section encore
     * fermée est « Pièces / déclarations », la huitième — des réponses qui s'y
     * trouveraient ne doivent rien ajouter.
     */
    /**
     * Une section commencée mais non achevée ne gonfle pas la progression.
     *
     * Ce test protégeait au départ un autre cas : une section **hors parcours
     * ouvert** ne devait pas compter. Ce cas n'existe plus, les neuf étapes
     * étant désormais sur le parcours. La garantie qui reste, et qui vaut
     * autant, est celle-ci : `completed_at` est la seule preuve qu'une section
     * est faite, et des réponses partielles n'en sont pas une.
     */
    public function test_une_section_inachevee_ne_gonfle_pas_la_progression(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $dossier = Application::factory()->for($campagne)->for(User::factory(), 'candidate')->create();
        $dossier->sections()->create([
            'section' => ApplicationSection::CHALLENGE->value,
            'answers' => ['esquisse' => 'Forage solaire'],
            'completed_at' => null,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.completedSections', 0)
                ->where('applications.0.completionPercent', 0));
    }

    /**
     * La progression affichée est **relue**, pas reprise du cache.
     *
     * `completion_percent` est écrit à la dernière sauvegarde du candidat. Un
     * écran de pilotage qui le recopierait afficherait un souvenir : ici la
     * colonne est volontairement fausse, et l'administration montre l'état réel.
     */
    public function test_la_progression_ne_recopie_pas_la_colonne_perimee(): void
    {
        $campagne = $this->campagne(ouverte: true);
        $dossier = $this->dossier($campagne);
        $dossier->forceFill(['completion_percent' => 99])->save();

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('applications.0.completionPercent', 11));
    }

    // — Performance ————————————————————————————————————————————————

    /**
     * Le nombre de requêtes ne dépend pas du nombre de lignes.
     *
     * C'est la seule formulation d'un test anti-N+1 qui ne se périme pas : cinq
     * dossiers et vingt-cinq dossiers doivent coûter le même nombre de
     * requêtes. Le verdict d'éligibilité, calculé pour chaque ligne visible, ne
     * doit en ajouter aucune — ni pour les réponses, ni pour la campagne, ni
     * pour chacune des cinq règles.
     */
    public function test_la_liste_ne_fait_pas_de_requete_par_ligne(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), ouverte: true);
        $administrateur = $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->dossier($campagne);
        }

        $petite = $this->compterLesRequetes(fn () => $this->actingAs($administrateur)->get('/admin/applications')->assertOk());

        for ($i = 0; $i < 20; $i++) {
            $this->dossier($campagne);
        }

        $grande = $this->compterLesRequetes(fn () => $this->actingAs($administrateur)->get('/admin/applications')->assertOk());

        $this->assertSame(
            $petite,
            $grande,
            "La page a coûté {$petite} requêtes pour 5 dossiers et {$grande} pour 25 : une requête suit les lignes."
        );
    }

    private function compterLesRequetes(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $action();

        $nombre = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $nombre;
    }
}
