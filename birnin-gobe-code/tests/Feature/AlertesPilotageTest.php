<?php

namespace Tests\Feature;

use App\Domain\Alerting\ComputeAlerts;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Verification\AdmissibilityDecision;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use App\Models\VerificationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les alertes de pilotage — §9.3.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace reste étanche.**
 *
 * 2. **Le silence est significatif.** Aucune alerte ne se déclenche sur une
 *    situation normale. Un écran qui alerte tout le temps n'alerte plus.
 *
 * 3. **Une alerte s'éteint quand sa cause disparaît.** C'est la conséquence
 *    directe du choix de ne rien persister : le test le vérifie en résolvant la
 *    cause et en rechargeant.
 *
 * 4. **Chaque alerte est chiffrée et mène quelque part.** Une alerte sans
 *    nombre ni destination n'est qu'une inquiétude.
 *
 * 5. **On n'alerte pas sur un seuil que personne n'a arrêté.** La
 *    sous-couverture d'évaluation reste muette tant que le minimum n'est pas
 *    fixé — même règle qu'ADR-007.
 */
final class AlertesPilotageTest extends TestCase
{
    use RefreshDatabase;

    private int $numero = 0;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    private function dossier(Campaign $campagne, ApplicationStatus $statut, ?string $depose = null): Application
    {
        return Application::factory()
            ->for($campagne, 'campaign')
            ->status($statut)
            ->create([
                'submission_number' => sprintf('BG-%06d', ++$this->numero),
                'submitted_at' => $depose ?? now()->subDay(),
            ]);
    }

    /** @return list<array<string, mixed>> */
    private function alertes(User $admin, string $url = '/admin/alerts'): array
    {
        $donnees = [];

        $this->actingAs($admin)->get($url)->assertOk()->assertInertia(
            function (AssertableInertia $page) use (&$donnees): void {
                $donnees = $page->toArray()['props']['alerts'];
            },
        );

        return $donnees;
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/alerts')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/alerts')->assertForbidden();
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

    // — Le silence est significatif ————————————————————————————————

    public function test_une_campagne_saine_ne_declenche_aucune_alerte(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create(); // Ouverte, clôture dans 30 jours.

        // Déposé hier : dans les délais de contrôle.
        $this->dossier($campagne, ApplicationStatus::SUBMITTED, now()->subDay()->toDateTimeString());

        $this->assertSame([], $this->alertes($admin));
    }

    // — Retards de contrôle ————————————————————————————————————————

    public function test_un_dossier_non_controle_trop_longtemps_est_signale(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier(
            $campagne,
            ApplicationStatus::SUBMITTED,
            now()->subDays(ComputeAlerts::JOURS_AVANT_RETARD_DE_CONTROLE + 1)->toDateTimeString(),
        );

        $alertes = collect($this->alertes($admin))->keyBy('key');

        $this->assertArrayHasKey('controle.retard', $alertes);
        $this->assertSame(1, $alertes['controle.retard']['count']);
        $this->assertSame('WARNING', $alertes['controle.retard']['severity']);
        $this->assertNotNull($alertes['controle.retard']['url']);
    }

    /** Juste sous le seuil, rien n'est signalé : le seuil est une frontière, pas une suggestion. */
    public function test_un_dossier_recent_ne_declenche_pas_le_retard(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier(
            $campagne,
            ApplicationStatus::SUBMITTED,
            now()->subDays(ComputeAlerts::JOURS_AVANT_RETARD_DE_CONTROLE - 1)->toDateTimeString(),
        );

        $this->assertArrayNotHasKey('controle.retard', collect($this->alertes($admin))->keyBy('key')->all());
    }

    /**
     * L'alerte s'éteint quand sa cause disparaît.
     *
     * Rien n'est persisté : contrôler le dossier suffit à faire taire l'alerte,
     * sans acquittement.
     */
    public function test_une_alerte_s_eteint_quand_sa_cause_disparait(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $dossier = $this->dossier(
            $campagne,
            ApplicationStatus::SUBMITTED,
            now()->subDays(ComputeAlerts::JOURS_AVANT_RETARD_DE_CONTROLE + 3)->toDateTimeString(),
        );

        $this->assertArrayHasKey('controle.retard', collect($this->alertes($admin))->keyBy('key')->all());

        $dossier->forceFill(['status' => ApplicationStatus::PENDING_REVIEW->value])->save();

        $this->assertArrayNotHasKey('controle.retard', collect($this->alertes($admin))->keyBy('key')->all());
    }

    // — Clarifications dépassées ————————————————————————————————————

    public function test_un_delai_de_clarification_depasse_est_critique(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $dossier = $this->dossier($campagne, ApplicationStatus::CLARIFICATION_REQUESTED);

        VerificationDecision::query()->create([
            'application_id' => $dossier->getKey(),
            'decision' => AdmissibilityDecision::CLARIFICATION->value,
            'candidate_message' => 'Merci de fournir votre justificatif.',
            'respond_by' => now()->subDays(2)->toDateString(),
            'previous_status' => ApplicationStatus::PENDING_REVIEW->value,
            'new_status' => ApplicationStatus::CLARIFICATION_REQUESTED->value,
            'actor_id' => $admin->getKey(),
            'created_at' => now()->subDays(10),
        ]);

        $alertes = collect($this->alertes($admin))->keyBy('key');

        $this->assertArrayHasKey('clarification.depassee', $alertes);
        $this->assertSame('CRITICAL', $alertes['clarification.depassee']['severity']);

        // La plus grave passe en tête de liste.
        $this->assertSame('clarification.depassee', $this->alertes($admin)[0]['key']);
    }

    public function test_un_delai_de_clarification_a_venir_ne_declenche_rien(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $dossier = $this->dossier($campagne, ApplicationStatus::CLARIFICATION_REQUESTED);

        VerificationDecision::query()->create([
            'application_id' => $dossier->getKey(),
            'decision' => AdmissibilityDecision::CLARIFICATION->value,
            'candidate_message' => 'Merci de fournir votre justificatif.',
            'respond_by' => now()->addDays(5)->toDateString(),
            'previous_status' => ApplicationStatus::PENDING_REVIEW->value,
            'new_status' => ApplicationStatus::CLARIFICATION_REQUESTED->value,
            'actor_id' => $admin->getKey(),
            'created_at' => now(),
        ]);

        $this->assertArrayNotHasKey('clarification.depassee', collect($this->alertes($admin))->keyBy('key')->all());
    }

    // — Évaluation ————————————————————————————————————————————————

    public function test_un_dossier_recevable_sans_evaluateur_est_signale(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier($campagne, ApplicationStatus::ADMISSIBLE);

        $alertes = collect($this->alertes($admin))->keyBy('key');

        $this->assertArrayHasKey('evaluation.sans_evaluateur', $alertes);
        $this->assertSame(1, $alertes['evaluation.sans_evaluateur']['count']);
    }

    /**
     * Sans minimum arrêté, aucune sous-couverture n'est signalée.
     *
     * Même règle qu'ADR-007 : alerter sur un seuil inventé ferait courir après
     * un objectif que personne n'a fixé.
     */
    public function test_sans_minimum_arrete_la_sous_couverture_est_muette(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier($campagne, ApplicationStatus::IN_EVALUATION);

        $this->assertArrayNotHasKey('evaluation.sous_couverture', collect($this->alertes($admin))->keyBy('key')->all());
    }

    public function test_avec_un_minimum_arrete_la_sous_couverture_est_signalee(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create(['settings' => ['evaluation' => ['min_evaluations' => 2]]]);

        $this->dossier($campagne, ApplicationStatus::IN_EVALUATION);

        $alertes = collect($this->alertes($admin, '/admin/alerts?campaign='.$campagne->getKey()))->keyBy('key');

        $this->assertArrayHasKey('evaluation.sous_couverture', $alertes);
        $this->assertSame(1, $alertes['evaluation.sous_couverture']['count']);
    }

    // — Calendrier ————————————————————————————————————————————————

    public function test_une_cloture_passee_avec_une_file_ouverte_est_critique(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->closed()->create();

        $this->dossier($campagne, ApplicationStatus::SUBMITTED, now()->subDays(2)->toDateTimeString());

        $alertes = collect($this->alertes($admin))->keyBy('key');

        $this->assertArrayHasKey('campagne.cloture_franchie', $alertes);
        $this->assertSame('CRITICAL', $alertes['campagne.cloture_franchie']['severity']);
    }

    public function test_une_cloture_imminente_est_une_information(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create([
            'opens_at' => now()->subDays(10),
            'closes_at' => now()->addDays(2),
        ]);

        $this->dossier($campagne, ApplicationStatus::SUBMITTED);

        $alertes = collect($this->alertes($admin))->keyBy('key');

        $this->assertArrayHasKey('campagne.cloture_imminente', $alertes);
        $this->assertSame('INFO', $alertes['campagne.cloture_imminente']['severity']);
    }

    // — Forme des alertes —————————————————————————————————————————

    public function test_chaque_alerte_est_chiffree_et_explique_quoi_faire(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->closed()->create();

        $this->dossier($campagne, ApplicationStatus::SUBMITTED, now()->subDays(30)->toDateTimeString());
        $this->dossier($campagne, ApplicationStatus::ADMISSIBLE);

        $alertes = $this->alertes($admin);

        $this->assertNotEmpty($alertes);

        foreach ($alertes as $alerte) {
            $this->assertGreaterThan(0, $alerte['count'], "{$alerte['key']} sans nombre");
            $this->assertNotSame('', trim((string) $alerte['action']), "{$alerte['key']} sans consigne");
            $this->assertNotSame('', trim((string) $alerte['detail']), "{$alerte['key']} sans détail");
        }
    }

    /** Aucune route n'écrit : une alerte ne s'acquitte pas. */
    public function test_aucune_route_n_acquitte_une_alerte(): void
    {
        $admin = $this->admin();

        foreach (['post', 'put', 'patch', 'delete'] as $verbe) {
            $reponse = $this->actingAs($admin)->{$verbe}('/admin/alerts');

            $this->assertContains(
                $reponse->getStatusCode(),
                [404, 405],
                "Le verbe {$verbe} ne doit pas être servi sur les alertes.",
            );
        }
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/alerts')->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }
}
