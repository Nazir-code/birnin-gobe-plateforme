<?php

namespace Tests\Feature;

use App\Domain\Administration\SettingsDomain;
use App\Domain\Administration\SettingsState;
use App\Domain\Auth\UserRole;
use App\Domain\Evaluation\EvaluationSettings;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les paramètres administrables — §9.2.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace reste étanche.**
 *
 * 2. **L'inventaire est complet et honnête.** Les neuf domaines du §9.2 sont
 *    rendus, chacun avec son état réel. Le test le vérifie parce que la
 *    tentation inverse — n'afficher que ce qui est outillé — ferait paraître le
 *    back-office complet.
 *
 * 3. **Le vide n'est pas une valeur.** Un champ laissé vide laisse le paramètre
 *    non arrêté ; il n'est écrit ni à zéro, ni à `null`. C'est la règle
 *    d'ADR-007, et elle décide de ce que l'affectation et les alertes
 *    affichent.
 *
 * 4. **Les autres clés de `settings` survivent.** Enregistrer l'évaluation ne
 *    doit pas effacer les critères d'éligibilité — ils vivent dans le même
 *    document JSON.
 *
 * 5. **Rien n'est journalisé quand rien ne change.**
 */
final class ParametresAdministrablesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/settings')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/settings')->assertForbidden();
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

    // — L'inventaire est complet ————————————————————————————————————

    public function test_les_neuf_domaines_du_cahier_des_charges_sont_rendus(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $domaines = collect($page->toArray()['props']['domains']);

                $this->assertCount(count(SettingsDomain::cases()), $domaines);
                $this->assertSame(9, $domaines->count());

                // Chaque domaine dit son périmètre et l'état de son outillage.
                foreach ($domaines as $domaine) {
                    $this->assertNotSame('', trim((string) $domaine['scope']));
                    $this->assertNotSame('', trim((string) $domaine['detail']));
                }
            });
    }

    /** Les domaines non outillés sont annoncés comme tels, jamais masqués. */
    public function test_les_domaines_non_outilles_sont_annonces(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertInertia(function (AssertableInertia $page): void {
                $domaines = collect($page->toArray()['props']['domains'])->keyBy('value');

                $this->assertSame(SettingsState::ADMINISTRABLE->value, $domaines['CAMPAGNE']['state']);
                $this->assertSame(SettingsState::ADMINISTRABLE->value, $domaines['ELIGIBILITE']['state']);
                $this->assertSame(SettingsState::PARTIEL->value, $domaines['EVALUATION']['state']);

                foreach (['THEMATIQUES', 'FORMULAIRE', 'COMMUNICATION', 'PUBLICATION', 'UTILISATEURS', 'CONSERVATION'] as $absent) {
                    $this->assertSame(SettingsState::ABSENT->value, $domaines[$absent]['state']);
                }
            });
    }

    // — Le vide n'est pas une valeur ———————————————————————————————

    public function test_sans_saisie_le_parametre_reste_non_arrete(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", [
                'min_evaluations' => '',
                'score_gap_threshold' => '',
            ])
            ->assertSessionHasNoErrors();

        $reglages = EvaluationSettings::fromCampaign($campagne->fresh());

        $this->assertNull($reglages->minEvaluations);
        $this->assertFalse($reglages->toArray()['configured'], 'La configuration ne doit pas être réputée arrêtée.');
        $this->assertArrayNotHasKey(EvaluationSettings::KEY, $campagne->fresh()->settings ?? []);
    }

    public function test_un_minimum_arrete_est_enregistre_et_relu(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", [
                'min_evaluations' => 3,
                'score_gap_threshold' => 1.5,
            ])
            ->assertRedirect();

        $reglages = EvaluationSettings::fromCampaign($campagne->fresh());

        $this->assertSame(3, $reglages->minEvaluations);
        $this->assertSame(1.5, $reglages->scoreGapThreshold);
    }

    public function test_une_saisie_aberrante_est_refusee(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", [
                'min_evaluations' => EvaluationSettings::MAX_EVALUATIONS + 1,
            ])
            ->assertSessionHasErrors('min_evaluations');

        // L'échelle du §11.3 va de 0 à 5 : un écart ne peut pas la dépasser.
        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", [
                'score_gap_threshold' => EvaluationSettings::MAX_SCORE_GAP + 1,
            ])
            ->assertSessionHasErrors('score_gap_threshold');

        $this->assertNull(EvaluationSettings::fromCampaign($campagne->fresh())->minEvaluations);
    }

    // — Les autres clés survivent ————————————————————————————————————

    public function test_enregistrer_l_evaluation_ne_touche_pas_aux_criteres_d_eligibilite(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create([
            'settings' => ['eligibility' => ['age' => ['min' => 18, 'max' => 35]]],
        ]);

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", ['min_evaluations' => 2])
            ->assertSessionHasNoErrors();

        $settings = $campagne->fresh()->settings;

        // `assertEqualsCanonicalizing` et non `assertSame` : `jsonb` ne conserve
        // pas l'ordre des clés, et l'ordre n'est pas ce que ce test protège.
        $this->assertEqualsCanonicalizing(['min' => 18, 'max' => 35], $settings['eligibility']['age']);
        $this->assertSame(2, $settings[EvaluationSettings::KEY]['min_evaluations']);
    }

    // — Le journal ————————————————————————————————————————————————

    public function test_un_changement_de_parametre_est_journalise(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", ['min_evaluations' => 2]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'CAMPAIGN_EVALUATION_SETTINGS_UPDATED',
            'target_id' => (string) $campagne->getKey(),
            'actor_id' => $admin->getKey(),
        ]);
    }

    /** Un enregistrement qui ne change rien n'est pas une décision. */
    public function test_un_enregistrement_sans_changement_n_ecrit_rien(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create(['settings' => ['evaluation' => ['min_evaluations' => 2]]]);

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)
            ->put("/admin/settings/campaigns/{$campagne->getKey()}/evaluation", ['min_evaluations' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame($avant, AuditEvent::query()->count());
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/settings')->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }

    /** Sans campagne, l'écran le dit plutôt que d'offrir un formulaire sans cible. */
    public function test_sans_campagne_active_l_ecran_reste_lisible(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/settings')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('campaign', null)
                ->has('domains', 9));
    }
}
