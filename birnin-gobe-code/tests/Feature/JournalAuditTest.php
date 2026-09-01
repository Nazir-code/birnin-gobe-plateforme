<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditIndexQuery;
use App\Domain\Audit\AuditWriter;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le journal d'audit consulté par l'administration (§13).
 *
 * Ce que cette suite protège, dans l'ordre :
 *
 * 1. **L'espace reste étanche.** Candidat, évaluateur et jury n'entrent pas ;
 *    un visiteur est renvoyé vers l'accès interne.
 *
 * 2. **Le journal reste un journal.** Aucune route n'écrit, ne corrige ni ne
 *    supprime — et la consultation elle-même n'y ajoute pas de ligne. Un
 *    journal qui grossit de sa propre lecture noierait les décisions.
 *
 * 3. **Rien n'est masqué.** Une action que le domaine ne connaît pas s'affiche
 *    telle qu'elle est stockée ; un acteur dont le compte a été supprimé est
 *    nommé comme tel. Ce sont les deux cas où un écran naïf montrerait du vide,
 *    et le vide se lit comme une action sans auteur — ce qui est faux.
 *
 * 4. **Les filtres se réduisent, ils ne refusent pas.** Un paramètre tronqué
 *    ouvre le journal sans ce filtre, jamais une page d'erreur : un lien collé
 *    dans un compte rendu d'incident doit rester utilisable.
 */
final class JournalAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    /** Écrit un événement par le vrai chemin, jamais par insertion directe. */
    private function ecrire(int $acteur, string $action, string $type, string $cible, ?array $avant = null, ?array $apres = null, ?string $motif = null): void
    {
        app(AuditWriter::class)->write($acteur, $action, $type, $cible, $avant, $apres, $motif);
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/audit')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/audit')->assertForbidden();
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

    // — Le journal reste un journal ———————————————————————————————

    /**
     * Consulter n'écrit pas.
     *
     * C'est la règle qui garde le journal lisible : y consigner chaque ouverture
     * le ferait grossir de sa propre lecture, et les décisions disparaîtraient
     * sous les consultations.
     */
    public function test_la_consultation_n_ajoute_aucune_ligne(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/audit')->assertOk();
        $this->actingAs($admin)->get('/admin/audit?action='.AuditAction::CAMPAIGN_CREATED->value)->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }

    /** Aucun verbe d'écriture n'est servi sur cette adresse. */
    public function test_aucune_route_n_ecrit_dans_le_journal(): void
    {
        $admin = $this->admin();

        foreach (['post', 'put', 'patch', 'delete'] as $verbe) {
            $reponse = $this->actingAs($admin)->{$verbe}('/admin/audit');

            $this->assertContains(
                $reponse->getStatusCode(),
                [404, 405],
                "Le verbe {$verbe} ne doit pas être servi sur le journal.",
            );
        }
    }

    // — Rien n'est masqué —————————————————————————————————————————

    public function test_la_liste_rend_les_evenements_mis_en_forme(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->ecrire(
            $admin->getKey(),
            AuditAction::CAMPAIGN_STATUS_CHANGED->value,
            Campaign::class,
            (string) $campagne->getKey(),
            ['status' => 'DRAFT'],
            ['status' => 'OPEN'],
            'Ouverture décidée en comité.',
        );

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Audit/Index')
                ->has('events', 1)
                ->where('events.0.actionLabel', AuditAction::CAMPAIGN_STATUS_CHANGED->label())
                ->where('events.0.weight', 'DECISIVE')
                ->where('events.0.actor.name', 'Aïcha Diallo')
                ->where('events.0.actor.known', true)
                ->where('events.0.target.typeLabel', 'Campagne')
                ->where('events.0.reason', 'Ouverture décidée en comité.')
                // Le changement est nommé, avec son avant et son après.
                ->has('events.0.changes', 1)
                ->where('events.0.changes.0.field', 'status')
                ->where('events.0.changes.0.before', 'DRAFT')
                ->where('events.0.changes.0.after', 'OPEN'));
    }

    /**
     * Une action inconnue du domaine reste lisible.
     *
     * `audit_events.action` est du texte libre, et le restera : un événement
     * écrit hier ne doit pas devenir illisible parce qu'une classe a changé
     * aujourd'hui.
     */
    public function test_une_action_inconnue_s_affiche_telle_quelle(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), 'JURY_DELIBERATION_CLOSED', 'App\\Models\\Deliberation', '7');

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.actionLabel', 'JURY_DELIBERATION_CLOSED')
                ->where('events.0.weight', 'ROUTINE')
                // Le type inconnu perd son lien, pas son nom.
                ->where('events.0.target.typeLabel', 'Deliberation')
                ->where('events.0.target.url', null));
    }

    /**
     * Un compte supprimé garde ses événements, et l'écran le dit.
     *
     * `actor_id` n'a volontairement pas de clé étrangère : c'est ce qui permet
     * de supprimer un compte sans effacer la trace de ce qu'il a fait. L'écran
     * doit alors nommer l'absence, plutôt que de laisser une case vide qui se
     * lirait comme une action sans auteur.
     */
    public function test_un_acteur_supprime_est_nomme_comme_tel(): void
    {
        $admin = $this->admin();
        $parti = User::factory()->role(UserRole::ADMIN)->create();
        $identifiant = $parti->getKey();

        $this->ecrire($identifiant, AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');
        $parti->delete();

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.actor.known', false)
                ->where('events.0.actor.id', $identifiant)
                ->where('events.0.actor.name', 'Compte supprimé (#'.$identifiant.')'));
    }

    /** Un dossier visé reste atteignable depuis son événement. */
    public function test_une_candidature_visee_porte_son_lien(): void
    {
        $admin = $this->admin();
        $dossier = Application::factory()->for(Campaign::factory())->for(User::factory(), 'candidate')->create();

        $this->ecrire($admin->getKey(), AuditAction::APPLICATION_SUBMITTED->value, Application::class, (string) $dossier->getKey());

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.target.typeLabel', 'Candidature')
                ->where('events.0.target.url', route('admin.applications.show', $dossier->getKey())));
    }

    // — Les filtres ———————————————————————————————————————————————

    public function test_le_filtre_par_action_s_applique_dans_la_base(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');
        $this->ecrire($admin->getKey(), AuditAction::APPLICATION_SUBMITTED->value, Application::class, '2');

        $this->actingAs($admin)
            ->get('/admin/audit?action='.AuditAction::APPLICATION_SUBMITTED->value)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('events.0.action', AuditAction::APPLICATION_SUBMITTED->value)
                ->where('pagination.total', 1)
                // Le total sans filtre distingue « journal vide » de « aucun
                // résultat » : les deux écrans ne disent pas la même chose.
                ->where('totalWithoutFilters', 2)
                ->where('hasActiveFilters', true));
    }

    public function test_le_filtre_par_objet_vise_isole_l_histoire_d_un_dossier(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::APPLICATION_CREATED->value, Application::class, '11');
        $this->ecrire($admin->getKey(), AuditAction::APPLICATION_SUBMITTED->value, Application::class, '11');
        $this->ecrire($admin->getKey(), AuditAction::APPLICATION_CREATED->value, Application::class, '22');

        $this->actingAs($admin)
            ->get('/admin/audit?target='.urlencode(Application::class).'&id=11')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('events', 2));
    }

    /**
     * Un intervalle saisi à l'envers est retourné, pas rendu stérile.
     *
     * Même politique que le reste des filtres de consultation : on comprend
     * l'intention plutôt que de punir la saisie.
     */
    public function test_un_intervalle_inverse_est_remis_dans_l_ordre(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');

        $aujourdhui = now()->format('Y-m-d');
        $demain = now()->addDay()->format('Y-m-d');

        $this->actingAs($admin)
            ->get("/admin/audit?since={$demain}&until={$aujourdhui}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('filters.since', $aujourdhui)
                ->where('filters.until', $demain));
    }

    /** Un filtre illisible est ignoré, et le formulaire le montre vide. */
    public function test_un_filtre_illisible_ouvre_le_journal_sans_lui(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');

        $this->actingAs($admin)
            ->get('/admin/audit?action=CE_QUI_N_EXISTE_PAS&since=32-13-2026&actor=abc&sort=alphabetique')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 1)
                ->where('filters.action', '')
                ->where('filters.since', '')
                ->where('filters.actor', '')
                ->where('filters.sort', 'recent')
                ->where('hasActiveFilters', false));
    }

    /** Les deux sens de lecture, et l'identifiant qui départage l'ex æquo. */
    public function test_le_tri_suit_le_temps_dans_les_deux_sens(): void
    {
        $admin = $this->admin();
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');
        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_UPDATED->value, Campaign::class, '1');

        $this->actingAs($admin)
            ->get('/admin/audit?sort=ancien')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.action', AuditAction::CAMPAIGN_CREATED->value)
                ->where('events.1.action', AuditAction::CAMPAIGN_UPDATED->value));

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.0.action', AuditAction::CAMPAIGN_UPDATED->value)
                ->where('events.1.action', AuditAction::CAMPAIGN_CREATED->value));
    }

    /** Le découpage vient de PostgreSQL, jamais d'un filtrage après coup. */
    public function test_la_pagination_decoupe_sans_perdre_le_total(): void
    {
        $admin = $this->admin();

        foreach (range(1, AuditIndexQuery::PER_PAGE + 5) as $rang) {
            $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_UPDATED->value, Campaign::class, (string) $rang);
        }

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', AuditIndexQuery::PER_PAGE)
                ->where('pagination.total', AuditIndexQuery::PER_PAGE + 5)
                ->where('pagination.lastPage', 2));
    }

    /** Un journal vide et un filtre trop étroit sont deux écrans distincts. */
    public function test_le_journal_vide_se_distingue_d_un_filtre_sans_resultat(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('totalWithoutFilters', 0)
                ->where('hasActiveFilters', false));

        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');

        $this->actingAs($admin)
            ->get('/admin/audit?action='.AuditAction::APPLICATION_SUBMITTED->value)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events', 0)
                ->where('totalWithoutFilters', 1)
                ->where('hasActiveFilters', true));
    }

    /**
     * Les acteurs proposés au filtre viennent des événements, pas des comptes.
     *
     * Un administrateur qui n'a rien fait n'encombre pas la liste ; un compte
     * supprimé en sort, ses événements restant lisibles par ailleurs.
     */
    public function test_le_filtre_par_auteur_ne_propose_que_ceux_qui_ont_agi(): void
    {
        $admin = $this->admin();
        User::factory()->role(UserRole::ADMIN)->create(['name' => 'Jamais agi']);

        $this->ecrire($admin->getKey(), AuditAction::CAMPAIGN_CREATED->value, Campaign::class, '1');

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('options.actors', 1)
                ->where('options.actors.0.label', 'Aïcha Diallo'));
    }

    /** Une valeur identique des deux côtés n'est pas un changement. */
    public function test_une_valeur_inchangee_n_encombre_pas_la_ligne(): void
    {
        $admin = $this->admin();

        $this->ecrire(
            $admin->getKey(),
            AuditAction::CAMPAIGN_UPDATED->value,
            Campaign::class,
            '1',
            ['name' => 'BIRNIN GOBE 2026', 'status' => 'DRAFT'],
            ['name' => 'BIRNIN GOBE 2026', 'status' => 'OPEN'],
        );

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('events.0.changes', 1)
                ->where('events.0.changes.0.field', 'status'));
    }
}
