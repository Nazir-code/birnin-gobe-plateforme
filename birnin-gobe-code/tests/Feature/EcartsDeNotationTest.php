<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Evaluation\AssignmentStatus;
use App\Domain\Evaluation\DivergenceReviewOutcome;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\EvaluationStatus;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationReview;
use App\Models\EvaluationScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La revue d'écart entre évaluateurs — §11.3.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace reste étanche.** Y compris pour les évaluateurs : comparer les
 *    notations est un geste de pilotage, et le §11.3 veut les évaluations
 *    indépendantes.
 *
 * 2. **Sans seuil arrêté, rien n'est déclaré divergent.** C'est la règle
 *    d'ADR-007 appliquée à un verdict : un écart non comparé n'est ni
 *    acceptable ni excessif. L'écran le dit au lieu d'afficher une file vide,
 *    et l'alerte du §9.3 reste muette.
 *
 * 3. **Il faut deux avis arrêtés pour qu'un écart existe.** Un dossier qui n'en
 *    porte qu'un n'a pas d'écart nul, il a une notation en cours.
 *
 * 4. **Aucune note n'est modifiable depuis l'administration.** C'est la forme
 *    exécutable de « le gestionnaire voit l'avancement mais pas une
 *    modification silencieuse des notes ».
 *
 * 5. **Une revue vaut pour l'état qu'elle a vu.** Une évaluation de plus, et
 *    elle redevient due — c'est ce qui empêche l'acquittement définitif.
 */
final class EcartsDeNotationTest extends TestCase
{
    use RefreshDatabase;

    private int $numero = 0;

    private ?Campaign $campagne = null;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    private function evaluateur(string $nom): User
    {
        return User::factory()->role(UserRole::EVALUATOR)->create(['name' => $nom]);
    }

    /** Une seule campagne ouverte : `campaigns_une_seule_ouverte` l'impose. */
    private function campagne(?float $seuil = null): Campaign
    {
        if ($this->campagne === null) {
            $this->campagne = Campaign::factory()->create([
                'settings' => $seuil === null ? [] : ['evaluation' => ['score_gap_threshold' => $seuil]],
            ]);
        }

        return $this->campagne;
    }

    private function dossier(?Campaign $campagne = null): Application
    {
        return Application::factory()
            ->for($campagne ?? $this->campagne(), 'campaign')
            ->status(ApplicationStatus::IN_EVALUATION)
            ->create([
                'submission_number' => sprintf('BG-%06d', ++$this->numero),
                'submitted_at' => now()->subDays(10),
            ]);
    }

    /**
     * Une évaluation verrouillée, écrite directement.
     *
     * Le parcours réel — charte, brouillon, verrouillage — est couvert par
     * `EspaceEvaluateurTest`. Le rejouer ici pour poser un décor ferait porter
     * chaque test de cette suite sur deux sujets à la fois : c'est la revue
     * d'écart qui est protégée, pas la façon d'arriver à une note.
     *
     * @param  array<string, int>  $notes  indexé par critère ; les absents valent 3
     */
    private function notationVerrouillee(Application $dossier, User $evaluateur, array $notes = []): Evaluation
    {
        $affectation = EvaluationAssignment::query()->create([
            'application_id' => $dossier->getKey(),
            'evaluator_id' => $evaluateur->getKey(),
            'status' => AssignmentStatus::ACCEPTED->value,
            'assigned_at' => now()->subDays(3),
            'accepted_at' => now()->subDays(2),
        ]);

        $total = 0.0;

        $evaluation = Evaluation::query()->create([
            'evaluation_assignment_id' => $affectation->getKey(),
            'application_id' => $dossier->getKey(),
            'evaluator_id' => $evaluateur->getKey(),
            'status' => EvaluationStatus::LOCKED->value,
            'recommendation' => EvaluationRecommendation::RESERVE->value,
            'locked_at' => now()->subDay(),
        ]);

        foreach (EvaluationCriterion::cases() as $critere) {
            $note = $notes[$critere->value] ?? 3;
            $total += $critere->weightedScore($note);

            EvaluationScore::query()->create([
                'evaluation_id' => $evaluation->getKey(),
                'criterion' => $critere->value,
                'score' => $note,
            ]);
        }

        $evaluation->forceFill(['total_score' => round($total, 2)])->save();

        return $evaluation;
    }

    /** Un dossier noté par deux évaluateurs qui divergent de `$ecart` sur l'innovation. */
    private function dossierDivergent(int $ecart = 4): Application
    {
        $dossier = $this->dossier();

        $this->notationVerrouillee($dossier, $this->evaluateur('Mouhamadou Kane'), [
            EvaluationCriterion::INNOVATION->value => 1,
        ]);
        $this->notationVerrouillee($dossier, $this->evaluateur('Fatouma Issa'), [
            EvaluationCriterion::INNOVATION->value => 1 + $ecart,
        ]);

        return $dossier;
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/divergences')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/divergences')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function rolesSansAcces(): array
    {
        return [
            'candidat' => [UserRole::CANDIDATE->value],
            // Comparer les notations est un geste de pilotage : un évaluateur
            // qui verrait celles des autres cesserait d'être indépendant.
            'évaluateur' => [UserRole::EVALUATOR->value],
            'jury' => [UserRole::JURY->value],
        ];
    }

    // — Sans seuil arrêté, rien n'est divergent ————————————————————

    public function test_sans_seuil_l_ecran_dit_pourquoi_il_ne_signale_rien(): void
    {
        $admin = $this->admin();
        $this->campagne(); // aucun seuil
        $this->dossierDivergent();

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=tous')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertNull($props['threshold'], 'Aucun seuil ne doit être inventé.');
                $this->assertSame(0, $props['totalDue']);

                // Le dossier reste visible — il est comparable — mais son
                // verdict est « non comparé », jamais « conforme ».
                $this->assertCount(1, $props['divergences']);
                $this->assertNull($props['divergences'][0]['reviewDue']);
                $this->assertSame([], $props['divergences'][0]['divergentCriteria']);
            });
    }

    public function test_sans_seuil_aucune_alerte_de_pilotage_n_est_levee(): void
    {
        $admin = $this->admin();
        $this->campagne();
        $this->dossierDivergent();

        $this->actingAs($admin)
            ->get('/admin/alerts')
            ->assertInertia(function (AssertableInertia $page): void {
                $cles = collect($page->toArray()['props']['alerts'])->pluck('key');

                $this->assertNotContains('evaluation.ecarts_a_revoir', $cles);
            });
    }

    public function test_sans_seuil_une_revue_est_refusee(): void
    {
        $admin = $this->admin();
        $this->campagne();
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)
            ->post("/admin/divergences/{$dossier->getKey()}/reviews", [
                'outcome' => DivergenceReviewOutcome::DIVERGENCE_ACCEPTED->value,
                'reason' => 'Les deux lectures se défendent, le comité tranchera.',
            ])
            ->assertSessionHasErrors('review');

        $this->assertSame(0, EvaluationReview::query()->count());
    }

    // — Il faut deux avis arrêtés ————————————————————————————————

    public function test_un_dossier_a_une_seule_notation_n_apparait_pas(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossier();
        $this->notationVerrouillee($dossier, $this->evaluateur('Mouhamadou Kane'));

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=tous')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('divergences', 0));

        $this->actingAs($admin)
            ->get("/admin/divergences/{$dossier->getKey()}")
            ->assertNotFound();
    }

    /** Un brouillon n'est pas un avis : il ne compte pas dans la comparaison. */
    public function test_un_brouillon_ne_compte_pas_comme_second_avis(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossier();

        $this->notationVerrouillee($dossier, $this->evaluateur('Mouhamadou Kane'));

        $brouillon = $this->notationVerrouillee($dossier, $this->evaluateur('Fatouma Issa'));
        $brouillon->forceFill([
            'status' => EvaluationStatus::DRAFT->value,
            'locked_at' => null,
            'total_score' => null,
        ])->save();

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=tous')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('divergences', 0));
    }

    // — L'écart est mesuré critère par critère ————————————————————

    public function test_l_ecart_est_calcule_sur_l_echelle_du_critere(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent(ecart: 4);

        $this->actingAs($admin)
            ->get("/admin/divergences/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                $this->assertSame(4, $props['maxGap']);
                $this->assertTrue($props['reviewDue']);

                $divergents = collect($props['criteria'])->where('divergent', true);

                // Un seul critère diverge : les sept autres sont à 3 partout.
                $this->assertCount(1, $divergents);
                $this->assertSame(EvaluationCriterion::INNOVATION->value, $divergents->first()['criterion']);
            });
    }

    public function test_un_ecart_sous_le_seuil_n_appelle_pas_de_revue(): void
    {
        $admin = $this->admin();
        $this->campagne(2.0);
        $this->dossierDivergent(ecart: 2); // 2 n'est pas > 2

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=tous')
            ->assertInertia(function (AssertableInertia $page): void {
                $ligne = $page->toArray()['props']['divergences'][0];

                $this->assertFalse($ligne['reviewDue']);
                $this->assertSame([], $ligne['divergentCriteria']);
            });
    }

    /** Aucune moyenne, aucune médiane : la règle d'agrégation n'est pas arrêtée (§11.3). */
    public function test_aucune_note_consolidee_n_est_produite(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)
            ->get("/admin/divergences/{$dossier->getKey()}")
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                foreach (['consolidatedScore', 'average', 'median', 'consensus', 'rank'] as $interdit) {
                    $this->assertArrayNotHasKey($interdit, $props);
                }

                // Les notes individuelles, elles, sont bien là, nominatives.
                $this->assertCount(2, $props['evaluators']);
            });
    }

    // — L'administration ne touche pas aux notes ————————————————————

    public function test_aucune_route_d_administration_n_ecrit_une_note(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $avant = EvaluationScore::query()
            ->orderBy('id')
            ->pluck('score', 'id')
            ->all();

        $this->actingAs($admin)
            ->post("/admin/divergences/{$dossier->getKey()}/reviews", [
                'outcome' => DivergenceReviewOutcome::DIVERGENCE_ACCEPTED->value,
                'reason' => 'Désaccord assumé sur le caractère innovant, les deux lectures se défendent.',
            ])
            ->assertRedirect();

        $this->assertSame(
            $avant,
            EvaluationScore::query()->orderBy('id')->pluck('score', 'id')->all(),
            'Une revue ne doit jamais modifier une note.',
        );
    }

    // — La revue ————————————————————————————————————————————————

    public function test_une_revue_motivee_est_enregistree_et_journalisee(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)
            ->post("/admin/divergences/{$dossier->getKey()}/reviews", [
                'outcome' => DivergenceReviewOutcome::ADDITIONAL_EVALUATION->value,
                'reason' => 'Écart de quatre points sur l’innovation : un troisième avis est nécessaire.',
            ])
            ->assertRedirect('/admin/divergences');

        $revue = EvaluationReview::query()->firstOrFail();

        $this->assertSame(DivergenceReviewOutcome::ADDITIONAL_EVALUATION, $revue->outcome);
        $this->assertSame(2, $revue->covered_evaluations, 'L’état vu doit être figé sur la ligne.');
        $this->assertSame(4.0, $revue->observed_gap);
        $this->assertSame($admin->getKey(), $revue->reviewed_by);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'EVALUATION_DIVERGENCE_REVIEWED',
            'target_id' => (string) $dossier->getKey(),
            'actor_id' => $admin->getKey(),
        ]);
    }

    public function test_une_revue_sans_motif_serieux_est_refusee(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)
            ->post("/admin/divergences/{$dossier->getKey()}/reviews", [
                'outcome' => DivergenceReviewOutcome::DIVERGENCE_ACCEPTED->value,
                'reason' => 'ok',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, EvaluationReview::query()->count());
    }

    public function test_une_revue_sans_issue_est_refusee(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)
            ->post("/admin/divergences/{$dossier->getKey()}/reviews", [
                'reason' => 'Les deux lectures se défendent parfaitement bien.',
            ])
            ->assertSessionHasErrors('outcome');
    }

    public function test_une_fois_arbitre_le_dossier_sort_de_la_file(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)->post("/admin/divergences/{$dossier->getKey()}/reviews", [
            'outcome' => DivergenceReviewOutcome::DIVERGENCE_ACCEPTED->value,
            'reason' => 'Désaccord assumé sur le caractère innovant, les deux lectures se défendent.',
        ]);

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=a_revoir')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('divergences', 0));

        // Mais il reste consultable, avec son historique.
        $this->actingAs($admin)
            ->get('/admin/divergences?scope=revues')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('divergences', 1));
    }

    /**
     * Une revue ne vaut que pour l'état qu'elle a vu.
     *
     * C'est la propriété centrale de cet incrément : elle interdit
     * l'acquittement définitif qu'ADR-014 refuse déjà pour les alertes.
     */
    public function test_une_notation_de_plus_rouvre_la_revue(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $this->actingAs($admin)->post("/admin/divergences/{$dossier->getKey()}/reviews", [
            'outcome' => DivergenceReviewOutcome::ADDITIONAL_EVALUATION->value,
            'reason' => 'Écart de quatre points sur l’innovation : un troisième avis est nécessaire.',
        ]);

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=a_revoir')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('divergences', 0));

        // Le troisième avis arrive : le désaccord n'est plus le même.
        $this->notationVerrouillee($dossier, $this->evaluateur('Ibrahim Sani'), [
            EvaluationCriterion::INNOVATION->value => 5,
        ]);

        $this->actingAs($admin)
            ->get('/admin/divergences?scope=a_revoir')
            ->assertInertia(function (AssertableInertia $page): void {
                $lignes = $page->toArray()['props']['divergences'];

                $this->assertCount(1, $lignes);
                $this->assertTrue($lignes[0]['reviewDue']);
                $this->assertTrue($lignes[0]['lastReview']['stale']);
            });
    }

    public function test_l_alerte_de_pilotage_compte_les_ecarts_a_revoir(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $this->dossierDivergent();

        $this->actingAs($admin)
            ->get('/admin/alerts')
            ->assertInertia(function (AssertableInertia $page): void {
                $alerte = collect($page->toArray()['props']['alerts'])
                    ->firstWhere('key', 'evaluation.ecarts_a_revoir');

                $this->assertNotNull($alerte, 'L’alerte du §9.3 doit signaler les écarts à revoir.');
                $this->assertSame(1, $alerte['count']);
                $this->assertNotNull($alerte['url'], 'Une alerte sans destination fait chercher soi-même.');
            });
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        $this->campagne(1.0);
        $dossier = $this->dossierDivergent();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/divergences')->assertOk();
        $this->actingAs($admin)->get("/admin/divergences/{$dossier->getKey()}")->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }
}
