<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Accès interne à l'administration (ADR-003, ADR-006).
 *
 * Fichier distinct d'`AuthentificationCandidatTest` : les deux espaces ont des
 * flux séparés, et les suites qui les couvrent le sont aussi.
 */
final class AdministrationAuthentificationTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseSolide!2026';

    private function admin(string $email = 'admin@exemple.test'): User
    {
        return User::factory()->role(UserRole::ADMIN)->create([
            'name' => 'Aïcha Diallo',
            'email' => $email,
            'password' => self::MOT_DE_PASSE,
        ]);
    }

    private function candidat(string $email = 'candidat@exemple.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::MOT_DE_PASSE,
        ]);
    }

    // — Connexion ——————————————————————————————————————————————————

    public function test_un_admin_peut_se_connecter(): void
    {
        $admin = $this->admin();

        $reponse = $this->post('/admin/login', [
            'email' => 'admin@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $reponse->assertRedirect('/admin/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_un_admin_connecte_ouvre_son_tableau_de_bord(): void
    {
        $this->actingAs($this->admin())->get('/admin/dashboard')->assertOk();
    }

    /**
     * L'identité affichée vient du compte, pas d'un nom de démonstration : le
     * nom réel doit être partagé à la page.
     */
    public function test_le_tableau_de_bord_recoit_l_identite_reelle(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboard')
                ->where('auth.user.name', 'Aïcha Diallo')
                ->where('auth.user.role', 'admin'));
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        $this->admin();

        $reponse = $this->post('/admin/login', [
            'email' => 'admin@exemple.test',
            'password' => 'mauvais-mot-de-passe',
        ]);

        $reponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_un_email_inconnu_est_refuse(): void
    {
        $this->post('/admin/login', [
            'email' => 'personne@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // — Le cœur de la séparation ————————————————————————————————————

    /**
     * Un candidat qui connaît /admin/login et fournit ses **bons** identifiants
     * ne doit obtenir aucune session : le rôle est vérifié avant l'ouverture de
     * session, pas après.
     */
    public function test_un_candidat_ne_peut_pas_se_connecter_sur_l_espace_interne(): void
    {
        $this->candidat();

        $reponse = $this->post('/admin/login', [
            'email' => 'candidat@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $reponse->assertSessionHasErrors('email');
        // Aucune session, même fugace : c'est ce qui distingue une garde d'un
        // `attempt` suivi d'un `logout`.
        $this->assertGuest();
    }

    public function test_le_refus_ne_revele_pas_le_role_du_compte(): void
    {
        $this->candidat();

        $refusCandidat = $this->post('/admin/login', [
            'email' => 'candidat@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $refusInconnu = $this->post('/admin/login', [
            'email' => 'inconnu@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $this->assertSame(
            $refusInconnu->getSession()->get('errors')->get('email'),
            $refusCandidat->getSession()->get('errors')->get('email'),
            'Le message doit être identique, sinon il confirme qui est administrateur.',
        );
    }

    #[DataProvider('rolesNonAdmin')]
    public function test_un_role_non_admin_recoit_403(UserRole $role): void
    {
        $utilisateur = User::factory()->role($role)->create();

        $this->actingAs($utilisateur)->get('/admin/dashboard')->assertForbidden();
    }

    /** @return array<string, array{UserRole}> */
    public static function rolesNonAdmin(): array
    {
        return [
            'candidat' => [UserRole::CANDIDATE],
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    public function test_l_inscription_publique_cree_toujours_un_candidat(): void
    {
        $this->post('/register', [
            'name' => 'Intrus',
            'email' => 'intrus@exemple.test',
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
            // Tentative d'injection : ignorée, `role` est hors de `$fillable`.
            'role' => 'admin',
        ])->assertRedirect('/candidate/dashboard');

        $this->assertSame(UserRole::CANDIDATE, User::where('email', 'intrus@exemple.test')->firstOrFail()->role);
        $this->get('/admin/dashboard')->assertForbidden();
    }

    // — Visiteur anonyme ————————————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
    }

    /** Non-régression : l'espace candidat garde sa propre redirection. */
    public function test_un_visiteur_du_candidat_reste_renvoye_vers_la_connexion_candidat(): void
    {
        $this->get('/candidate/dashboard')->assertRedirect('/login');
    }

    public function test_l_acces_interne_est_joignable_par_un_visiteur(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Login'));
    }

    // — Utilisateur déjà connecté ————————————————————————————————————

    public function test_un_admin_connecte_est_renvoye_vers_son_tableau_de_bord(): void
    {
        $this->actingAs($this->admin())->get('/admin/login')->assertRedirect('/admin/dashboard');
    }

    public function test_un_candidat_connecte_est_sorti_de_l_acces_interne(): void
    {
        $this->actingAs($this->candidat())->get('/admin/login')->assertRedirect('/');

        // Et il reste candidat : consulter l'écran ne change rien.
        $this->get('/admin/dashboard')->assertForbidden();
    }

    // — Déconnexion ————————————————————————————————————————————————

    public function test_un_admin_peut_se_deconnecter(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/logout')
            ->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_la_deconnexion_retire_l_acces(): void
    {
        $this->actingAs($this->admin());

        $this->get('/admin/dashboard')->assertOk();
        $this->post('/admin/logout');

        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
    }

    public function test_on_peut_se_reconnecter_apres_deconnexion(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/logout');
        $this->assertGuest();

        $this->post('/admin/login', [
            'email' => 'admin@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/admin/dashboard');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_un_candidat_ne_peut_pas_utiliser_la_deconnexion_interne(): void
    {
        $this->actingAs($this->candidat())->post('/admin/logout')->assertForbidden();
    }

    // — Limitation des tentatives ————————————————————————————————————

    public function test_les_tentatives_sont_limitees(): void
    {
        $this->admin();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', [
                'email' => 'admin@exemple.test',
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        // La sixième est bloquée avant même d'être vérifiée : le bon mot de
        // passe ne passe plus.
        $this->post('/admin/login', [
            'email' => 'admin@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Marteler le formulaire candidat avec l'adresse d'un administrateur ne doit
     * pas lui fermer l'accès interne : les compteurs sont cloisonnés par espace.
     */
    public function test_le_blocage_d_un_espace_ne_ferme_pas_l_autre(): void
    {
        $this->admin();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => 'admin@exemple.test',
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        $this->post('/admin/login', [
            'email' => 'admin@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/admin/dashboard');
    }
}
