<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Campaign\CampaignLifecycle;
use App\Domain\Campaign\CampaignStatus;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administration des campagnes (ADR-008).
 *
 * Fichier distinct des suites candidat et authentification : le périmètre est
 * séparé, les suites qui le couvrent aussi.
 */
final class AdministrationCampagnesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    /** @return array<string, mixed> */
    private function formulaire(array $remplacements = []): array
    {
        return array_merge([
            'code' => 'BG-2027',
            'name' => 'BIRNIN GOBE 2027',
            'status' => CampaignStatus::DRAFT->value,
            'timezone' => 'Africa/Niamey',
            'opens_at' => '2027-01-15T08:00',
            'closes_at' => '2027-04-30T23:59',
        ], $remplacements);
    }

    // — Accès ——————————————————————————————————————————————————————

    public function test_un_admin_liste_les_campagnes(): void
    {
        Campaign::factory()->create(['code' => 'BG-2026', 'name' => 'BIRNIN GOBE 2026']);

        $this->actingAs($this->admin())
            ->get('/admin/campaigns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Campaigns/Index')
                ->where('campaigns.0.code', 'BG-2026')
                ->where('campaigns.0.name', 'BIRNIN GOBE 2026'));
    }

    #[DataProvider('ecransAdmin')]
    public function test_un_candidat_ne_peut_pas_ouvrir_les_ecrans_de_campagne(string $url): void
    {
        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
    }

    /** @return array<string, array{string}> */
    public static function ecransAdmin(): array
    {
        return [
            'liste' => ['/admin/campaigns'],
            'création' => ['/admin/campaigns/create'],
        ];
    }

    public function test_un_candidat_ne_peut_pas_ecrire_une_campagne(): void
    {
        $campagne = Campaign::factory()->create();
        $candidat = User::factory()->create();

        $this->actingAs($candidat)->post('/admin/campaigns', $this->formulaire())->assertForbidden();
        $this->actingAs($candidat)->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire())->assertForbidden();

        $this->assertSame(1, Campaign::query()->count());
    }

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/campaigns')->assertRedirect('/admin/login');
    }

    // — Création ————————————————————————————————————————————————————

    public function test_un_admin_cree_une_campagne(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire())
            ->assertRedirect('/admin/campaigns');

        $campagne = Campaign::query()->where('code', 'BG-2027')->firstOrFail();

        $this->assertSame('BIRNIN GOBE 2027', $campagne->name);
        $this->assertSame(CampaignStatus::DRAFT, $campagne->status);
        $this->assertSame('Africa/Niamey', $campagne->timezone);
    }

    /**
     * L'heure saisie est une heure murale : c'est le fuseau de la campagne qui
     * lui donne un instant, pas celui du serveur.
     */
    public function test_les_dates_sont_lues_dans_le_fuseau_de_la_campagne(): void
    {
        $this->actingAs($this->admin())->post('/admin/campaigns', $this->formulaire([
            'timezone' => 'Africa/Niamey', // UTC+1, sans heure d'été
            'opens_at' => '2027-01-15T08:00',
        ]));

        $campagne = Campaign::query()->where('code', 'BG-2027')->firstOrFail();

        $this->assertSame('2027-01-15T07:00:00+00:00', $campagne->opens_at->setTimezone('UTC')->toIso8601String());
    }

    public function test_le_code_est_normalise_en_majuscules(): void
    {
        $this->actingAs($this->admin())->post('/admin/campaigns', $this->formulaire(['code' => 'bg-2027']));

        $this->assertNotNull(Campaign::query()->where('code', 'BG-2027')->first());
    }

    public function test_une_campagne_sans_dates_est_acceptee(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire(['opens_at' => '', 'closes_at' => '']))
            ->assertRedirect('/admin/campaigns');

        $campagne = Campaign::query()->where('code', 'BG-2027')->firstOrFail();

        $this->assertNull($campagne->opens_at);
        $this->assertNull($campagne->closes_at);
    }

    // — Validation ——————————————————————————————————————————————————

    public function test_le_code_est_unique(): void
    {
        Campaign::factory()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire())
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Campaign::query()->count());
    }

    #[DataProvider('formulairesInvalides')]
    public function test_un_formulaire_invalide_est_refuse(array $remplacements, string $champ): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire($remplacements))
            ->assertSessionHasErrors($champ);

        $this->assertSame(0, Campaign::query()->count());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function formulairesInvalides(): array
    {
        return [
            'nom vide' => [['name' => ''], 'name'],
            'code vide' => [['code' => ''], 'code'],
            'code avec espace' => [['code' => 'BG 2027'], 'code'],
            'fuseau inventé' => [['timezone' => 'Africa/Nulle-Part'], 'timezone'],
            'statut inconnu' => [['status' => 'PENDING'], 'status'],
            'clôture avant ouverture' => [['opens_at' => '2027-04-30T08:00', 'closes_at' => '2027-01-15T08:00'], 'closes_at'],
            'clôture égale à ouverture' => [['opens_at' => '2027-01-15T08:00', 'closes_at' => '2027-01-15T08:00'], 'closes_at'],
            'clôture sans ouverture' => [['opens_at' => '', 'closes_at' => '2027-04-30T23:59'], 'opens_at'],
            'date malformée' => [['opens_at' => '15/01/2027'], 'opens_at'],
        ];
    }

    public function test_une_campagne_ne_se_cree_pas_deja_close(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire(['status' => CampaignStatus::CLOSED->value]))
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Campaign::query()->count());
    }

    // — Modification ————————————————————————————————————————————————

    public function test_un_admin_modifie_une_campagne(): void
    {
        $campagne = Campaign::factory()->draft()->create(['code' => 'BG-2027', 'name' => 'Ancien nom']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['name' => 'Nouveau nom']))
            ->assertRedirect('/admin/campaigns');

        $this->assertSame('Nouveau nom', $campagne->fresh()->name);
    }

    public function test_une_campagne_garde_son_propre_code(): void
    {
        $campagne = Campaign::factory()->draft()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['code' => 'BG-2027']))
            ->assertSessionHasNoErrors();
    }

    /**
     * `settings` n'est pas exposé par cette phase : une modification ne doit pas
     * l'effacer au passage.
     */
    public function test_la_modification_preserve_les_parametres_non_exposes(): void
    {
        $campagne = Campaign::factory()->draft()->create([
            'code' => 'BG-2027',
            'settings' => ['grace_period_hours' => 48],
        ]);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['name' => 'Nouveau nom']));

        $this->assertSame(['grace_period_hours' => 48], $campagne->fresh()->settings);
    }

    // — Cycle de vie ————————————————————————————————————————————————

    #[DataProvider('transitionsLegales')]
    public function test_une_transition_legale_est_acceptee(CampaignStatus $depuis, CampaignStatus $vers): void
    {
        $campagne = Campaign::factory()->create(['code' => 'BG-2027', 'status' => $depuis]);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['status' => $vers->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame($vers, $campagne->fresh()->status);
    }

    /** @return array<string, array{CampaignStatus, CampaignStatus}> */
    public static function transitionsLegales(): array
    {
        return [
            'préparation → ouverte' => [CampaignStatus::DRAFT, CampaignStatus::OPEN],
            'préparation → archivée' => [CampaignStatus::DRAFT, CampaignStatus::ARCHIVED],
            'ouverte → close' => [CampaignStatus::OPEN, CampaignStatus::CLOSED],
            'close → ouverte' => [CampaignStatus::CLOSED, CampaignStatus::OPEN],
            'close → archivée' => [CampaignStatus::CLOSED, CampaignStatus::ARCHIVED],
        ];
    }

    #[DataProvider('transitionsInterdites')]
    public function test_une_transition_interdite_est_refusee(CampaignStatus $depuis, CampaignStatus $vers): void
    {
        $campagne = Campaign::factory()->create(['code' => 'BG-2027', 'status' => $depuis]);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['status' => $vers->value]))
            ->assertSessionHasErrors('status');

        $this->assertSame($depuis, $campagne->fresh()->status);
    }

    /** @return array<string, array{CampaignStatus, CampaignStatus}> */
    public static function transitionsInterdites(): array
    {
        return [
            'ouverte → préparation' => [CampaignStatus::OPEN, CampaignStatus::DRAFT],
            'ouverte → archivée' => [CampaignStatus::OPEN, CampaignStatus::ARCHIVED],
            'close → préparation' => [CampaignStatus::CLOSED, CampaignStatus::DRAFT],
            'archivée → ouverte' => [CampaignStatus::ARCHIVED, CampaignStatus::OPEN],
            'archivée → préparation' => [CampaignStatus::ARCHIVED, CampaignStatus::DRAFT],
        ];
    }

    /** Une transition refusée ne doit pas non plus enregistrer le reste du formulaire. */
    public function test_une_transition_refusee_n_enregistre_rien(): void
    {
        $campagne = Campaign::factory()->create(['code' => 'BG-2027', 'name' => 'Nom initial', 'status' => CampaignStatus::ARCHIVED]);

        $this->actingAs($this->admin())->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire([
            'name' => 'Nom modifié',
            'status' => CampaignStatus::OPEN->value,
        ]));

        $this->assertSame('Nom initial', $campagne->fresh()->name);
    }

    public function test_le_formulaire_ne_propose_que_des_transitions_legales(): void
    {
        $campagne = Campaign::factory()->create(['status' => CampaignStatus::ARCHIVED]);

        $this->actingAs($this->admin())
            ->get("/admin/campaigns/{$campagne->getKey()}/edit")
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Campaigns/Form')
                ->where('campaign.statusOptions', [
                    ['value' => 'ARCHIVED', 'label' => 'Archivée'],
                ]));
    }

    // — Une seule campagne ouverte ——————————————————————————————————

    public function test_une_seconde_campagne_ouverte_est_refusee_a_la_creation(): void
    {
        Campaign::factory()->create(['code' => 'BG-2026', 'name' => 'BIRNIN GOBE 2026']);

        $this->actingAs($this->admin())
            ->post('/admin/campaigns', $this->formulaire(['status' => CampaignStatus::OPEN->value]))
            ->assertSessionHasErrors('status');

        $this->assertSame(1, Campaign::query()->count());
    }

    public function test_une_seconde_campagne_ouverte_est_refusee_a_la_modification(): void
    {
        Campaign::factory()->create(['code' => 'BG-2026']);
        $suivante = Campaign::factory()->draft()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$suivante->getKey()}", $this->formulaire(['status' => CampaignStatus::OPEN->value]))
            ->assertSessionHasErrors('status');

        $this->assertSame(CampaignStatus::DRAFT, $suivante->fresh()->status);
    }

    /** La règle ne repose pas sur le code applicatif : la base la porte aussi. */
    public function test_la_base_refuse_une_seconde_campagne_ouverte(): void
    {
        Campaign::factory()->create(['code' => 'BG-2026']);

        $this->expectException(QueryException::class);

        Campaign::query()->create([
            'code' => 'BG-2027',
            'name' => 'Forcée',
            'status' => CampaignStatus::OPEN,
            'timezone' => 'Africa/Niamey',
        ]);
    }

    public function test_une_campagne_ouverte_peut_rester_ouverte_en_se_modifiant(): void
    {
        $campagne = Campaign::factory()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire([
                'name' => 'Nom corrigé',
                'status' => CampaignStatus::OPEN->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nom corrigé', $campagne->fresh()->name);
    }

    public function test_cloturer_puis_ouvrir_la_suivante_est_possible(): void
    {
        $courante = Campaign::factory()->create(['code' => 'BG-2026']);
        $suivante = Campaign::factory()->draft()->create(['code' => 'BG-2027']);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/campaigns/{$courante->getKey()}", $this->formulaire([
            'code' => 'BG-2026',
            'status' => CampaignStatus::CLOSED->value,
        ]))->assertSessionHasNoErrors();

        $this->actingAs($admin)->put("/admin/campaigns/{$suivante->getKey()}", $this->formulaire([
            'status' => CampaignStatus::OPEN->value,
        ]))->assertSessionHasNoErrors();

        $this->assertSame(CampaignStatus::OPEN, $suivante->fresh()->status);
    }

    // — Campagne active ————————————————————————————————————————————

    public function test_la_campagne_active_est_celle_ouverte_dans_sa_fenetre(): void
    {
        $active = Campaign::factory()->create(['code' => 'BG-2027']);
        Campaign::factory()->draft()->create(['code' => 'BG-2028']);

        $this->assertTrue($active->is(app(ActiveCampaign::class)->resolve()));
    }

    public function test_une_campagne_ouverte_hors_fenetre_n_est_pas_active(): void
    {
        Campaign::factory()->closed()->create(['code' => 'BG-2026']);

        $this->assertNull(app(ActiveCampaign::class)->resolve());
    }

    public function test_la_liste_signale_la_campagne_active(): void
    {
        $active = Campaign::factory()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->get('/admin/campaigns')
            ->assertInertia(fn ($page) => $page
                ->where('activeId', $active->getKey())
                ->where('campaigns.0.active', true)
                ->where('campaigns.0.window', 'en-cours'));
    }

    public function test_la_liste_distingue_une_fenetre_a_venir(): void
    {
        Campaign::factory()->draft()->create([
            'code' => 'BG-2028',
            'opens_at' => now()->addMonth(),
            'closes_at' => now()->addMonths(3),
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/campaigns')
            ->assertInertia(fn ($page) => $page
                ->where('activeId', null)
                ->where('campaigns.0.window', 'a-venir'));
    }

    // — Tableau de bord ————————————————————————————————————————————

    public function test_le_tableau_de_bord_affiche_la_campagne_ouverte(): void
    {
        Campaign::factory()->create(['code' => 'BG-2027', 'name' => 'BIRNIN GOBE 2027']);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->where('campaign.name', 'BIRNIN GOBE 2027')
                ->where('campaign.active', true)
                ->where('campaignsCount', 1));
    }

    public function test_le_tableau_de_bord_sans_campagne_ne_ment_pas(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('campaign', null)
                ->where('campaignsCount', 0));
    }

    /** Ouverte mais hors fenêtre : le tableau de bord doit le montrer, pas le taire. */
    public function test_le_tableau_de_bord_signale_une_campagne_hors_fenetre(): void
    {
        Campaign::factory()->closed()->create(['code' => 'BG-2026']);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('campaign.code', 'BG-2026')
                ->where('campaign.active', false)
                ->where('campaign.window', 'echue'));
    }

    // — Suppression ————————————————————————————————————————————————

    /**
     * `applications.campaign_id` est en cascade : supprimer une campagne
     * emporterait les dossiers déposés. Aucune route ne le permet.
     */
    public function test_aucune_route_ne_supprime_une_campagne(): void
    {
        $campagne = Campaign::factory()->create();

        $this->actingAs($this->admin())
            ->delete("/admin/campaigns/{$campagne->getKey()}")
            ->assertMethodNotAllowed();

        $this->assertSame(1, Campaign::query()->count());
    }

    /** Une campagne archivée conserve ses candidatures : rien n'est détruit. */
    public function test_archiver_conserve_les_candidatures(): void
    {
        $campagne = Campaign::factory()->create(['code' => 'BG-2026']);
        Application::factory()->create([
            'campaign_id' => $campagne->getKey(),
            'candidate_id' => User::factory()->create()->getKey(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire([
            'code' => 'BG-2026',
            'status' => CampaignStatus::CLOSED->value,
        ]));
        $this->actingAs($admin)->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire([
            'code' => 'BG-2026',
            'status' => CampaignStatus::ARCHIVED->value,
        ]));

        $this->assertSame(CampaignStatus::ARCHIVED, $campagne->fresh()->status);
        $this->assertSame(1, Application::query()->where('campaign_id', $campagne->getKey())->count());
    }

    // — Audit ——————————————————————————————————————————————————————

    public function test_la_creation_est_auditee(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/campaigns', $this->formulaire());

        $evenement = AuditEvent::query()->where('action', 'CAMPAIGN_CREATED')->firstOrFail();

        $this->assertSame($admin->getKey(), $evenement->actor_id);
        $this->assertSame(Campaign::class, $evenement->target_type);
        $this->assertNull($evenement->old_value);
        $this->assertSame('BG-2027', $evenement->new_value['code']);
    }

    public function test_une_modification_sans_changement_de_statut_est_auditee_comme_telle(): void
    {
        $campagne = Campaign::factory()->draft()->create(['code' => 'BG-2027', 'name' => 'Ancien nom']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['name' => 'Nouveau nom']));

        $evenement = AuditEvent::query()->where('action', 'CAMPAIGN_UPDATED')->firstOrFail();

        $this->assertSame('Ancien nom', $evenement->old_value['name']);
        $this->assertSame('Nouveau nom', $evenement->new_value['name']);
    }

    public function test_un_changement_de_statut_a_son_propre_evenement(): void
    {
        $campagne = Campaign::factory()->draft()->create(['code' => 'BG-2027']);

        $this->actingAs($this->admin())
            ->put("/admin/campaigns/{$campagne->getKey()}", $this->formulaire(['status' => CampaignStatus::OPEN->value]));

        $evenement = AuditEvent::query()->where('action', 'CAMPAIGN_STATUS_CHANGED')->firstOrFail();

        $this->assertSame('DRAFT', $evenement->old_value['status']);
        $this->assertSame('OPEN', $evenement->new_value['status']);
    }

    /** Consulter n'est pas agir : la lecture ne doit rien écrire dans le journal. */
    public function test_la_consultation_n_est_pas_auditee(): void
    {
        $campagne = Campaign::factory()->create();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/campaigns');
        $this->actingAs($admin)->get("/admin/campaigns/{$campagne->getKey()}/edit");
        $this->actingAs($admin)->get('/admin/dashboard');

        $this->assertSame(0, AuditEvent::query()->count());
    }

    // — Cycle de vie, unitaire —————————————————————————————————————

    public function test_le_cycle_inclut_le_statut_courant_dans_les_cibles(): void
    {
        $cycle = new CampaignLifecycle;

        foreach (CampaignStatus::cases() as $statut) {
            $this->assertContains($statut, $cycle->atteignablesDepuis($statut));
            $this->assertTrue($cycle->peutPasser($statut, $statut));
        }
    }

    public function test_archivee_est_terminal(): void
    {
        $cycle = new CampaignLifecycle;

        $this->assertSame([CampaignStatus::ARCHIVED], $cycle->atteignablesDepuis(CampaignStatus::ARCHIVED));
    }
}
