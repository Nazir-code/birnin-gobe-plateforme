<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Evaluation\AssignmentStatus;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'affectation des dossiers aux évaluateurs — §11.1.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace reste étanche.** Y compris pour les évaluateurs eux-mêmes :
 *    l'écran d'affectation est un écran de pilotage, pas un écran d'évaluation.
 *
 * 2. **Seul un dossier recevable s'affecte.** Le §11 vient après le §10. Un
 *    brouillon, un dossier soumis mais non contrôlé, un dossier irrecevable :
 *    aucun n'entre dans la file d'évaluation.
 *
 * 3. **Un conflit déclaré est définitif.** C'est la règle qui donne son sens à
 *    la récusation : reproposer un dossier à quelqu'un qui s'en est écarté la
 *    viderait de son contenu.
 *
 * 4. **Le lot part en entier ou pas du tout.** Un lot à moitié affecté
 *    laisserait une charge fausse sur l'écran qui sert à l'équilibrer.
 *
 * 5. **Rien n'est supprimé.** Une affectation levée reste lisible, avec son
 *    motif — le §13.1 demande de suivre « conflits et récusations ».
 */
final class AffectationEvaluateursTest extends TestCase
{
    use RefreshDatabase;

    private int $numero = 0;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    private function evaluateur(string $nom = 'Mouhamadou Kane'): User
    {
        return User::factory()->role(UserRole::EVALUATOR)->create(['name' => $nom]);
    }

    /** Un dossier déclaré recevable, seul état affectable. */
    private function dossierRecevable(?Campaign $campagne = null, ApplicationStatus $statut = ApplicationStatus::ADMISSIBLE): Application
    {
        $campagne ??= Campaign::factory()->create();

        return Application::factory()
            ->for($campagne, 'campaign')
            ->status($statut)
            ->create([
                'submission_number' => sprintf('BG-%06d', ++$this->numero),
                'submitted_at' => now()->subDays(3),
            ]);
    }

    /** Affecte par la vraie route, jamais par insertion directe. */
    private function affecter(User $admin, User $evaluateur, array $dossiers): TestResponse
    {
        return $this->actingAs($admin)->post('/admin/evaluators/assignments', [
            'evaluator_id' => $evaluateur->getKey(),
            'application_ids' => array_map(static fn (Application $d): int => $d->getKey(), $dossiers),
        ]);
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/evaluators')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/evaluators')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function rolesSansAcces(): array
    {
        return [
            'candidat' => [UserRole::CANDIDATE->value],
            // L'évaluateur non plus : affecter est un geste de pilotage.
            'évaluateur' => [UserRole::EVALUATOR->value],
            'jury' => [UserRole::JURY->value],
        ];
    }

    // — Seul un dossier recevable s'affecte ———————————————————————

    public function test_seuls_les_dossiers_recevables_apparaissent(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        Application::factory()->for($campagne, 'campaign')->create(); // DRAFT
        $soumis = Application::factory()->for($campagne, 'campaign')
            ->status(ApplicationStatus::SUBMITTED)->create(['submission_number' => 'BG-900001']);
        $recevable = $this->dossierRecevable($campagne);

        $this->actingAs($admin)
            ->get('/admin/evaluators')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $recevable->getKey()));

        $this->assertSame(ApplicationStatus::SUBMITTED, $soumis->fresh()->status);
    }

    public function test_un_dossier_non_recevable_ne_s_affecte_pas(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $campagne = Campaign::factory()->create();

        $soumis = Application::factory()->for($campagne, 'campaign')
            ->status(ApplicationStatus::SUBMITTED)->create(['submission_number' => 'BG-900002']);

        $this->affecter($admin, $evaluateur, [$soumis])->assertSessionHasErrors('application_ids');

        $this->assertDatabaseCount('evaluation_assignments', 0);
    }

    public function test_la_premiere_affectation_ouvre_l_evaluation(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasNoErrors();

        $this->assertSame(ApplicationStatus::IN_EVALUATION, $dossier->fresh()->status);
        $this->assertDatabaseHas('evaluation_assignments', [
            'application_id' => $dossier->getKey(),
            'evaluator_id' => $evaluateur->getKey(),
            'status' => AssignmentStatus::ASSIGNED->value,
        ]);
    }

    /** Le second évaluateur ne retransitionne rien : le dossier y est déjà. */
    public function test_une_seconde_affectation_ne_change_pas_le_statut(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();
        $dossier = $this->dossierRecevable($campagne);

        $this->affecter($admin, $this->evaluateur('Premier'), [$dossier])->assertSessionHasNoErrors();
        $this->affecter($admin, $this->evaluateur('Second'), [$dossier])->assertSessionHasNoErrors();

        $this->assertSame(ApplicationStatus::IN_EVALUATION, $dossier->fresh()->status);
        $this->assertSame(2, EvaluationAssignment::query()->where('application_id', $dossier->getKey())->count());
    }

    public function test_un_compte_qui_n_est_pas_evaluateur_est_refuse(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierRecevable();

        $this->actingAs($admin)->post('/admin/evaluators/assignments', [
            'evaluator_id' => $admin->getKey(),
            'application_ids' => [$dossier->getKey()],
        ])->assertSessionHasErrors('evaluator_id');

        $this->assertDatabaseCount('evaluation_assignments', 0);
    }

    // — Le lot part en entier ou pas du tout ——————————————————————

    public function test_un_lot_dont_un_dossier_est_invalide_n_affecte_rien(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $campagne = Campaign::factory()->create();

        $bon = $this->dossierRecevable($campagne);
        $mauvais = Application::factory()->for($campagne, 'campaign')
            ->status(ApplicationStatus::INADMISSIBLE)->create(['submission_number' => 'BG-900003']);

        $this->affecter($admin, $evaluateur, [$bon, $mauvais])->assertSessionHasErrors('application_ids');

        // La transaction a tout annulé, y compris le dossier valide du lot.
        $this->assertDatabaseCount('evaluation_assignments', 0);
        $this->assertSame(ApplicationStatus::ADMISSIBLE, $bon->fresh()->status);
    }

    public function test_le_meme_dossier_ne_s_affecte_pas_deux_fois_a_la_meme_personne(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasNoErrors();
        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasErrors('application_ids');

        $this->assertSame(1, EvaluationAssignment::query()->count());
    }

    // — Un conflit déclaré est définitif ——————————————————————————

    public function test_un_conflit_declare_interdit_la_reaffectation(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasNoErrors();
        $affectation = EvaluationAssignment::query()->firstOrFail();

        $this->actingAs($admin)->delete("/admin/evaluators/assignments/{$affectation->getKey()}", [
            'status' => AssignmentStatus::CONFLICT->value,
            'reason' => 'Lien familial avec un membre de l’équipe.',
        ])->assertSessionHasNoErrors();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasErrors('application_ids');

        $this->assertSame(1, EvaluationAssignment::query()->count());
    }

    /** Un simple retrait, lui, laisse le dossier réaffectable à la même personne. */
    public function test_un_retrait_laisse_le_dossier_reaffectable(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasNoErrors();
        $affectation = EvaluationAssignment::query()->firstOrFail();

        $this->actingAs($admin)->delete("/admin/evaluators/assignments/{$affectation->getKey()}", [
            'status' => AssignmentStatus::WITHDRAWN->value,
            'reason' => 'Rééquilibrage de la charge.',
        ])->assertSessionHasNoErrors();

        $this->affecter($admin, $evaluateur, [$dossier])->assertSessionHasNoErrors();

        $this->assertSame(2, EvaluationAssignment::query()->count());
        $this->assertSame(1, EvaluationAssignment::query()->whereNull('released_at')->count());
    }

    // — Rien n'est supprimé ———————————————————————————————————————

    public function test_une_levee_exige_un_motif_ecrit(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier]);
        $affectation = EvaluationAssignment::query()->firstOrFail();

        $this->actingAs($admin)->delete("/admin/evaluators/assignments/{$affectation->getKey()}", [
            'status' => AssignmentStatus::WITHDRAWN->value,
            'reason' => '   ',
        ])->assertSessionHasErrors('reason');

        $this->assertNull($affectation->fresh()->released_at);
    }

    public function test_un_motif_qui_ne_leve_pas_est_refuse(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier]);
        $affectation = EvaluationAssignment::query()->firstOrFail();

        // « Pris en charge » est un état d'affectation en vigueur, pas une levée.
        $this->actingAs($admin)->delete("/admin/evaluators/assignments/{$affectation->getKey()}", [
            'status' => AssignmentStatus::ACCEPTED->value,
            'reason' => 'Tentative.',
        ])->assertSessionHasErrors('status');

        $this->assertNull($affectation->fresh()->released_at);
    }

    public function test_une_affectation_levee_reste_lisible_avec_son_motif(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $dossier = $this->dossierRecevable();

        $this->affecter($admin, $evaluateur, [$dossier]);
        $affectation = EvaluationAssignment::query()->firstOrFail();

        $this->actingAs($admin)->delete("/admin/evaluators/assignments/{$affectation->getKey()}", [
            'status' => AssignmentStatus::CONFLICT->value,
            'reason' => 'Ancien associé du porteur.',
        ])->assertSessionHasNoErrors();

        $levee = $affectation->fresh();

        $this->assertNotNull($levee->released_at);
        $this->assertSame(AssignmentStatus::CONFLICT, $levee->status);
        $this->assertSame('Ancien associé du porteur.', $levee->release_reason);
    }

    // — Le journal et la charge ————————————————————————————————————

    /** Le §13.3 veut que le journal couvre les affectations, dossier par dossier. */
    public function test_chaque_dossier_affecte_laisse_sa_ligne_de_journal(): void
    {
        $admin = $this->admin();
        $evaluateur = $this->evaluateur();
        $campagne = Campaign::factory()->create();

        $premier = $this->dossierRecevable($campagne);
        $second = $this->dossierRecevable($campagne);

        $this->affecter($admin, $evaluateur, [$premier, $second])->assertSessionHasNoErrors();

        foreach ([$premier, $second] as $dossier) {
            $this->assertDatabaseHas('audit_events', [
                'action' => 'EVALUATION_ASSIGNED',
                'target_id' => (string) $dossier->getKey(),
                'actor_id' => $admin->getKey(),
            ]);
        }
    }

    public function test_la_charge_de_chaque_evaluateur_est_comptee(): void
    {
        $admin = $this->admin();
        $charge = $this->evaluateur('Chargé');
        $libre = $this->evaluateur('Libre');
        $campagne = Campaign::factory()->create();

        $this->affecter($admin, $charge, [$this->dossierRecevable($campagne), $this->dossierRecevable($campagne)]);

        $this->actingAs($admin)
            ->get('/admin/evaluators')
            ->assertInertia(function (AssertableInertia $page) use ($charge, $libre): void {
                $evaluateurs = collect($page->toArray()['props']['evaluators']);

                $this->assertSame(2, $evaluateurs->firstWhere('id', $charge->getKey())['load']);
                $this->assertSame(0, $evaluateurs->firstWhere('id', $libre->getKey())['load']);
            });
    }

    /**
     * Sans seuil arrêté, la couverture est inconnue — jamais « insuffisante ».
     *
     * C'est la même règle qu'ADR-007 : un paramètre que le comité n'a pas fixé
     * ne doit pas produire un verdict qui pousse à agir.
     */
    public function test_sans_minimum_arrete_la_couverture_est_inconnue(): void
    {
        $admin = $this->admin();
        $this->dossierRecevable();

        $this->actingAs($admin)
            ->get('/admin/evaluators')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.configured', false)
                ->where('applications.0.covered', null));
    }

    public function test_avec_un_minimum_arrete_la_couverture_est_calculee(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create(['settings' => ['evaluation' => ['min_evaluations' => 2]]]);
        $dossier = $this->dossierRecevable($campagne);

        $this->affecter($admin, $this->evaluateur('Un'), [$dossier]);

        $this->actingAs($admin)
            ->get('/admin/evaluators?campaign='.$campagne->getKey())
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.minEvaluations', 2)
                ->where('applications.0.assignmentCount', 1)
                ->where('applications.0.covered', false));
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        $this->dossierRecevable();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/evaluators')->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }
}
