<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Evaluation\AssignmentStatus;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Domain\Evaluation\EvaluationRecommendation;
use App\Domain\Evaluation\EvaluationStatus;
use App\Domain\Evaluation\ScoreAnchor;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'espace évaluateur — §11.1, §11.2, §11.3.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace de chacun est étanche.** Un évaluateur ne voit que ses
 *    affectations, et un dossier qui n'est pas le sien rend 404 — pas 403, qui
 *    confirmerait son existence.
 *
 * 2. **La charte est une porte.** Tant qu'elle n'est pas acceptée, le dossier
 *    n'est pas rendu, et aucune note ne peut être postée. C'est la seule façon
 *    que la déclaration d'impartialité soit signée avant d'avoir lu.
 *
 * 3. **La grille vaut 100 points.** Une faute de frappe dans un poids rendrait
 *    fausse toute note affichée au comité, sans que rien ne le signale.
 *
 * 4. **Le brouillon ne perd rien et n'exige rien.** Une feuille à moitié
 *    remplie s'enregistre ; les exigences du §11.3 sont opposées au
 *    verrouillage, pas avant.
 *
 * 5. **Le §11.3 est exécutable.** Huit critères notés, notes extrêmes
 *    justifiées, recommandation portée, rejet et short-list motivés.
 *
 * 6. **Le verrou tient.** Aucune route ne rouvre une évaluation verrouillée —
 *    c'est ce qui donne son sens à l'indépendance des notations.
 *
 * 7. **Le statut du dossier ne bouge que sur un minimum arrêté.** Sans nombre
 *    minimal d'évaluations, aucun dossier ne sort de l'évaluation : la règle
 *    d'ADR-007 poussée jusqu'à sa conséquence.
 */
final class EspaceEvaluateurTest extends TestCase
{
    use RefreshDatabase;

    private int $numero = 0;

    private ?Campaign $campagne = null;

    private function evaluateur(string $nom = 'Mouhamadou Kane'): User
    {
        return User::factory()->role(UserRole::EVALUATOR)->create(['name' => $nom]);
    }

    /**
     * L'édition du test, créée une seule fois.
     *
     * `campaigns_une_seule_ouverte` est un index unique partiel : une seule
     * campagne peut être ouverte à la fois. Un helper qui en créerait une par
     * dossier ferait échouer tout test qui en manipule deux — et l'échec
     * porterait sur la contrainte, pas sur ce que le test protège.
     */
    private function campagne(): Campaign
    {
        return $this->campagne ??= Campaign::factory()->create();
    }

    private function dossier(?Campaign $campagne = null): Application
    {
        $campagne ??= $this->campagne();

        return Application::factory()
            ->for($campagne, 'campaign')
            ->status(ApplicationStatus::IN_EVALUATION)
            ->create([
                'submission_number' => sprintf('BG-%06d', ++$this->numero),
                'submitted_at' => now()->subDays(5),
            ]);
    }

    private function affectation(User $evaluateur, ?Application $dossier = null): EvaluationAssignment
    {
        $dossier ??= $this->dossier();

        return EvaluationAssignment::query()->create([
            'application_id' => $dossier->getKey(),
            'evaluator_id' => $evaluateur->getKey(),
            'status' => AssignmentStatus::ASSIGNED->value,
            'assigned_at' => now()->subDay(),
        ]);
    }

    /** Accepte la charte par la vraie route, jamais par insertion directe. */
    private function accepter(User $evaluateur, EvaluationAssignment $affectation): TestResponse
    {
        return $this->actingAs($evaluateur)
            ->post("/evaluator/assignments/{$affectation->getKey()}/charter");
    }

    /**
     * Une saisie de grille.
     *
     * @param  array<string, int|null>  $notes  indexé par critère ; les critères
     *                                          absents restent non notés
     * @param  array<string, string>  $justifications
     * @return array<string, mixed>
     */
    private function saisie(array $notes, array $justifications = [], ?string $recommandation = null, string $commentaire = ''): array
    {
        return [
            'scores' => array_map(
                static fn (EvaluationCriterion $critere): array => [
                    'criterion' => $critere->value,
                    'score' => $notes[$critere->value] ?? null,
                    'comment' => $justifications[$critere->value] ?? null,
                ],
                EvaluationCriterion::cases(),
            ),
            'recommendation' => $recommandation,
            'comment' => $commentaire,
        ];
    }

    /** Toute la grille à la même note. */
    private function toutes(int $note): array
    {
        return array_fill_keys(EvaluationCriterion::values(), $note);
    }

    // — L'espace de chacun est étanche ——————————————————————————————

    public function test_un_visiteur_ne_voit_pas_le_plan_de_travail(): void
    {
        $this->get('/evaluator/assignments')->assertRedirect();
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/evaluator/assignments')->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function rolesSansAcces(): array
    {
        return [
            'candidat' => [UserRole::CANDIDATE->value],
            'administrateur' => [UserRole::ADMIN->value],
            'jury' => [UserRole::JURY->value],
        ];
    }

    public function test_le_plan_de_travail_ne_montre_que_ses_propres_dossiers(): void
    {
        $moi = $this->evaluateur();
        $autre = $this->evaluateur('Fatouma Issa');

        $this->affectation($moi);
        $this->affectation($autre);

        $this->actingAs($moi)
            ->get('/evaluator/assignments')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('assignments', 1));
    }

    /**
     * 404 et non 403 : un 403 confirmerait qu'un dossier a été confié à
     * quelqu'un, ce que l'indépendance des notations interdit de laisser
     * deviner.
     */
    public function test_le_dossier_d_un_autre_evaluateur_est_introuvable(): void
    {
        $moi = $this->evaluateur();
        $autre = $this->evaluateur('Fatouma Issa');
        $affectation = $this->affectation($autre);

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertNotFound();
    }

    public function test_une_affectation_levee_devient_introuvable(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $affectation->forceFill([
            'status' => AssignmentStatus::WITHDRAWN->value,
            'released_at' => now(),
            'release_reason' => 'Rééquilibrage de la charge.',
        ])->save();

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertNotFound();

        $this->actingAs($moi)
            ->get('/evaluator/assignments')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('assignments', 0));
    }

    // — La charte est une porte ————————————————————————————————————

    public function test_sans_charte_acceptee_le_dossier_n_est_pas_rendu(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Evaluator/Charter')
                // La charte ne montre du dossier que de quoi savoir si l'on a
                // un lien avec lui : ni sections, ni pièces.
                ->missing('sections')
                ->has('engagements'));
    }

    public function test_l_acceptation_ouvre_le_dossier_et_la_feuille(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->accepter($moi, $affectation)->assertRedirect();

        $affectation->refresh();

        $this->assertNotNull($affectation->accepted_at);
        $this->assertSame(AssignmentStatus::ACCEPTED, $affectation->status);

        $evaluation = Evaluation::query()->where('evaluation_assignment_id', $affectation->getKey())->first();

        $this->assertNotNull($evaluation);
        $this->assertSame(EvaluationStatus::DRAFT, $evaluation->status);
        $this->assertCount(count(EvaluationCriterion::cases()), $evaluation->scores);
        $this->assertNull($evaluation->total_score, 'Un brouillon n’a pas de note sur 100.');

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Evaluator/Evaluate'));
    }

    /** Un double clic ne doit ni échouer, ni réécrire la date d'engagement. */
    public function test_accepter_deux_fois_ne_change_pas_la_date(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->accepter($moi, $affectation);
        $premiere = $affectation->fresh()->accepted_at;

        $this->travel(2)->minutes();
        $this->accepter($moi, $affectation)->assertRedirect();

        $this->assertEquals($premiere, $affectation->fresh()->accepted_at);
        $this->assertSame(1, Evaluation::query()->count());
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'EVALUATION_CHARTER_ACCEPTED')->count(),
            'Une seule acceptation, un seul événement.',
        );
    }

    public function test_l_acceptation_est_journalisee(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->accepter($moi, $affectation);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'EVALUATION_CHARTER_ACCEPTED',
            'actor_id' => $moi->getKey(),
            'target_id' => (string) $affectation->application_id,
        ]);
    }

    /** Poster une note sans avoir signé contournerait le §11.1. */
    public function test_on_ne_peut_pas_noter_sans_avoir_accepte_la_charte(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->actingAs($moi)
            ->put("/evaluator/assignments/{$affectation->getKey()}/evaluation", $this->saisie($this->toutes(3)))
            ->assertNotFound();

        $this->assertSame(0, Evaluation::query()->count());
    }

    // — La grille vaut 100 points ————————————————————————————————

    public function test_les_poids_du_cahier_des_charges_totalisent_cent(): void
    {
        $this->assertSame(
            EvaluationCriterion::TOTAL_WEIGHT,
            EvaluationCriterion::totalWeight(),
            'Le score pondéré est présenté comme une note sur 100 : la somme des poids doit valoir 100.',
        );
    }

    public function test_les_huit_criteres_du_paragraphe_11_2_sont_rendus(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertInertia(function (AssertableInertia $page): void {
                $criteres = collect($page->toArray()['props']['criteria']);

                $this->assertCount(8, $criteres);
                $this->assertSame(100, $criteres->sum('weight'));

                // Chaque critère porte ses éléments d'appréciation : ce sont
                // eux qui font que deux évaluateurs notent la même chose.
                foreach ($criteres as $critere) {
                    $this->assertNotSame('', trim((string) $critere['elements']));
                }
            });
    }

    /** Les six ancres du §11.3, dont les deux extrêmes signalées comme telles. */
    public function test_l_echelle_porte_ses_ancres(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertInertia(function (AssertableInertia $page): void {
                $ancres = collect($page->toArray()['props']['anchors']);

                $this->assertCount(6, $ancres);
                $this->assertSame([0, 5], $ancres->where('extreme', true)->pluck('value')->all());
            });
    }

    // — Le brouillon ne perd rien et n'exige rien ———————————————————

    public function test_une_feuille_incomplete_s_enregistre(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->put("/evaluator/assignments/{$affectation->getKey()}/evaluation", $this->saisie([
                EvaluationCriterion::RELEVANCE->value => 4,
                EvaluationCriterion::INNOVATION->value => 3,
            ]))
            ->assertSessionHasNoErrors();

        $evaluation = Evaluation::query()->firstOrFail();

        $this->assertSame(4, $evaluation->scores()->where('criterion', EvaluationCriterion::RELEVANCE->value)->value('score'));
        $this->assertNull($evaluation->scores()->where('criterion', EvaluationCriterion::TEAM->value)->value('score'));
        $this->assertNull($evaluation->total_score, 'Une feuille incomplète n’a pas de note sur 100.');
        $this->assertSame(EvaluationStatus::DRAFT, $evaluation->status);
    }

    /** Vider une note est un geste légitime : on revient sur un critère. */
    public function test_une_note_effacee_redevient_non_notee(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->put(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation",
            $this->saisie([EvaluationCriterion::TEAM->value => 4]),
        );

        $this->actingAs($moi)->put(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation",
            $this->saisie([EvaluationCriterion::TEAM->value => '']),
        )->assertSessionHasNoErrors();

        $evaluation = Evaluation::query()->firstOrFail();

        $this->assertNull($evaluation->scores()->where('criterion', EvaluationCriterion::TEAM->value)->value('score'));
    }

    public function test_une_note_hors_de_l_echelle_est_refusee(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->put("/evaluator/assignments/{$affectation->getKey()}/evaluation", $this->saisie([
                EvaluationCriterion::RELEVANCE->value => ScoreAnchor::EXCELLENT->value + 1,
            ]))
            ->assertSessionHasErrors('scores.0.score');
    }

    /** Un brouillon n'est pas une décision : il n'entre pas au journal. */
    public function test_un_brouillon_n_ecrit_aucun_evenement_d_audit(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $avant = AuditEvent::query()->count();

        $this->actingAs($moi)->put(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation",
            $this->saisie($this->toutes(3)),
        );

        $this->assertSame($avant, AuditEvent::query()->count());
    }

    // — Le §11.3 est exécutable ————————————————————————————————————

    public function test_une_feuille_incomplete_ne_se_verrouille_pas(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie(
                [EvaluationCriterion::RELEVANCE->value => 4],
                recommandation: EvaluationRecommendation::RESERVE->value,
            ))
            ->assertSessionHasErrors('lock');

        $this->assertSame(EvaluationStatus::DRAFT, Evaluation::query()->firstOrFail()->status);
    }

    /** La saisie envoyée avec le verrouillage refusé n'est pas perdue. */
    public function test_un_verrouillage_refuse_conserve_la_saisie(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie([EvaluationCriterion::RELEVANCE->value => 4]),
        );

        $evaluation = Evaluation::query()->firstOrFail();

        $this->assertSame(4, $evaluation->scores()->where('criterion', EvaluationCriterion::RELEVANCE->value)->value('score'));
    }

    #[DataProvider('notesExtremes')]
    public function test_une_note_extreme_sans_justification_bloque_le_verrouillage(int $note): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $notes = $this->toutes(3);
        $notes[EvaluationCriterion::INNOVATION->value] = $note;

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie(
                $notes,
                recommandation: EvaluationRecommendation::RESERVE->value,
            ))
            ->assertSessionHasErrors('lock');

        $this->assertSame(EvaluationStatus::DRAFT, Evaluation::query()->firstOrFail()->status);
    }

    /** @return array<string, array{int}> */
    public static function notesExtremes(): array
    {
        return ['zéro' => [0], 'cinq' => [5]];
    }

    public function test_une_note_extreme_justifiee_passe(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $notes = $this->toutes(3);
        $notes[EvaluationCriterion::INNOVATION->value] = 5;

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie(
                $notes,
                [EvaluationCriterion::INNOVATION->value => 'Approche inédite au Niger, démontrée sur trois communes.'],
                EvaluationRecommendation::RESERVE->value,
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(EvaluationStatus::LOCKED, Evaluation::query()->firstOrFail()->status);
    }

    public function test_le_verrouillage_exige_une_recommandation(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie($this->toutes(3)))
            ->assertSessionHasErrors('lock');
    }

    #[DataProvider('recommandationsAJustifier')]
    public function test_un_rejet_et_une_short_list_doivent_etre_motives(string $recommandation): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie(
                $this->toutes(3),
                recommandation: $recommandation,
            ))
            ->assertSessionHasErrors('lock');

        $this->assertSame(EvaluationStatus::DRAFT, Evaluation::query()->firstOrFail()->status);
    }

    /** @return array<string, array{string}> */
    public static function recommandationsAJustifier(): array
    {
        return [
            'short-list' => [EvaluationRecommendation::SHORTLIST->value],
            'rejet' => [EvaluationRecommendation::REJECT->value],
        ];
    }

    /** L'avis réservé ne tranche rien : il n'a pas à être motivé (§11.3). */
    public function test_un_avis_reserve_se_verrouille_sans_commentaire(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $this->saisie(
                $this->toutes(3),
                recommandation: EvaluationRecommendation::RESERVE->value,
            ))
            ->assertSessionHasNoErrors();

        $this->assertSame(EvaluationStatus::LOCKED, Evaluation::query()->firstOrFail()->status);
    }

    /** Le total est calculé par le serveur, jamais reçu de l'écran. */
    public function test_le_score_pondere_est_calcule_au_verrouillage(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        // Toute la grille à 3 sur 5 : 3/5 de 100 points.
        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value),
        );

        $evaluation = Evaluation::query()->firstOrFail();

        $this->assertSame(60.0, $evaluation->total_score);
        $this->assertNotNull($evaluation->locked_at);
    }

    public function test_le_verrouillage_est_journalise_avec_la_note(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie($this->toutes(4), recommandation: EvaluationRecommendation::RESERVE->value),
        );

        $evenement = AuditEvent::query()->where('action', 'EVALUATION_LOCKED')->firstOrFail();

        $this->assertSame($moi->getKey(), $evenement->actor_id);
        $this->assertSame((string) $affectation->application_id, $evenement->target_id);
        $this->assertSame(80.0, (float) $evenement->new_value['total_score']);
    }

    // — Le verrou tient ————————————————————————————————————————————

    public function test_une_evaluation_verrouillee_ne_se_modifie_plus(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value),
        );

        $this->actingAs($moi)
            ->put("/evaluator/assignments/{$affectation->getKey()}/evaluation", $this->saisie($this->toutes(5)))
            ->assertSessionHasErrors('evaluation');

        $evaluation = Evaluation::query()->firstOrFail();

        $this->assertSame(60.0, $evaluation->total_score);
        $this->assertSame(3, $evaluation->scores()->where('criterion', EvaluationCriterion::TEAM->value)->value('score'));
    }

    public function test_une_evaluation_verrouillee_ne_se_reverrouille_pas(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $charge = $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value);

        $this->actingAs($moi)->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $charge);
        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/evaluation/lock", $charge)
            ->assertSessionHasErrors('lock');

        $this->assertSame(1, AuditEvent::query()->where('action', 'EVALUATION_LOCKED')->count());
    }

    public function test_l_ecran_d_une_evaluation_verrouillee_le_dit(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value),
        );

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertInertia(function (AssertableInertia $page): void {
                $evaluation = $page->toArray()['props']['evaluation'];

                $this->assertTrue($evaluation['locked']);

                // Comparaison numérique et non stricte : `json_encode(60.0)`
                // rend `60`, et la note relue est donc un entier. C'est la
                // valeur qui est protégée ici, pas son type de transport.
                $this->assertEqualsWithDelta(60.0, $evaluation['totalScore'], 0.001);
            });
    }

    // — La récusation ——————————————————————————————————————————————

    public function test_une_recusation_libere_le_dossier_et_l_interdit_ensuite(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/conflict", [
                'reason' => 'Je suis membre du conseil d’administration de la structure porteuse.',
            ])
            ->assertRedirect('/evaluator/assignments');

        $affectation->refresh();

        $this->assertSame(AssignmentStatus::CONFLICT, $affectation->status);
        $this->assertNotNull($affectation->released_at);
        $this->assertNotNull($affectation->release_reason);

        $this->actingAs($moi)
            ->get("/evaluator/assignments/{$affectation->getKey()}")
            ->assertNotFound();
    }

    public function test_une_recusation_sans_motif_est_refusee(): void
    {
        $moi = $this->evaluateur();
        $affectation = $this->affectation($moi);

        $this->actingAs($moi)
            ->post("/evaluator/assignments/{$affectation->getKey()}/conflict", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertNull($affectation->fresh()->released_at);
    }

    // — Le statut du dossier ————————————————————————————————————————

    /**
     * Sans minimum arrêté, le dossier ne sort pas de l'évaluation.
     *
     * C'est la règle d'ADR-007 poussée jusqu'à sa conséquence : le conclure sur
     * un minimum inventé le ferait sortir avec une seule notation, alors que le
     * comité en attendait peut-être trois.
     */
    public function test_sans_minimum_arrete_le_dossier_reste_en_evaluation(): void
    {
        $moi = $this->evaluateur();
        $campagne = Campaign::factory()->create(['settings' => []]);
        $dossier = $this->dossier($campagne);
        $affectation = $this->affectation($moi, $dossier);
        $this->accepter($moi, $affectation);

        $this->actingAs($moi)->post(
            "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
            $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value),
        );

        $this->assertSame(ApplicationStatus::IN_EVALUATION, $dossier->fresh()->status);
    }

    public function test_le_dossier_passe_en_evalue_quand_la_couverture_est_atteinte(): void
    {
        $campagne = Campaign::factory()->create(['settings' => ['evaluation' => ['min_evaluations' => 2]]]);
        $dossier = $this->dossier($campagne);

        $premier = $this->evaluateur();
        $second = $this->evaluateur('Fatouma Issa');

        foreach ([$premier, $second] as $rang => $evaluateur) {
            $affectation = $this->affectation($evaluateur, $dossier);
            $this->accepter($evaluateur, $affectation);

            $this->actingAs($evaluateur)->post(
                "/evaluator/assignments/{$affectation->getKey()}/evaluation/lock",
                $this->saisie($this->toutes(3), recommandation: EvaluationRecommendation::RESERVE->value),
            );

            // Après la première : la couverture n'est pas atteinte.
            $this->assertSame(
                $rang === 0 ? ApplicationStatus::IN_EVALUATION : ApplicationStatus::EVALUATED,
                $dossier->fresh()->status,
            );
        }
    }
}
