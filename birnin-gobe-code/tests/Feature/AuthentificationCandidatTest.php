<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fondation d'authentification candidat et cloisonnement des espaces (ADR-003).
 */
final class AuthentificationCandidatTest extends TestCase
{
    use RefreshDatabase;

    // — Inscription ————————————————————————————————————————————————

    public function test_un_visiteur_peut_creer_un_compte_candidat(): void
    {
        $reponse = $this->post('/register', [
            'name' => 'Amina Issa',
            'email' => 'amina@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
        ]);

        $reponse->assertRedirect('/candidate/dashboard');
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['email' => 'amina@example.test', 'name' => 'Amina Issa']);
    }

    public function test_l_email_doit_etre_unique(): void
    {
        User::factory()->create(['email' => 'deja@example.test']);

        $reponse = $this->post('/register', [
            'name' => 'Autre Personne',
            'email' => 'deja@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
        ]);

        $reponse->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'deja@example.test')->count());
    }

    public function test_le_mot_de_passe_est_hache(): void
    {
        $this->post('/register', [
            'name' => 'Amina Issa',
            'email' => 'amina@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
        ]);

        $user = User::where('email', 'amina@example.test')->firstOrFail();

        $this->assertNotSame('MotDePasseSolide!2026', $user->password);
        $this->assertTrue(Hash::check('MotDePasseSolide!2026', $user->password));
    }

    public function test_le_mot_de_passe_doit_etre_confirme(): void
    {
        $reponse = $this->post('/register', [
            'name' => 'Amina Issa',
            'email' => 'amina@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'autre-chose',
        ]);

        $reponse->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_le_role_cree_est_candidate(): void
    {
        $this->post('/register', [
            'name' => 'Amina Issa',
            'email' => 'amina@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
        ]);

        $user = User::where('email', 'amina@example.test')->firstOrFail();

        $this->assertSame(UserRole::CANDIDATE, $user->role);
    }

    public function test_le_role_ne_peut_pas_etre_injecte_depuis_le_formulaire(): void
    {
        $this->post('/register', [
            'name' => 'Attaquant',
            'email' => 'attaquant@example.test',
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
            'role' => 'admin',
        ]);

        $user = User::where('email', 'attaquant@example.test')->firstOrFail();

        $this->assertSame(UserRole::CANDIDATE, $user->role, "L'inscription publique ne doit jamais produire autre chose qu'un candidat.");

        // Et l'accès reste refusé, ce que le rôle seul ne prouve pas.
        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
    }

    // — Connexion ——————————————————————————————————————————————————

    public function test_un_candidat_peut_se_connecter(): void
    {
        $user = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $reponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'MotDePasseSolide!2026',
        ]);

        $reponse->assertRedirect('/candidate/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        $user = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $reponse = $this->post('/login', [
            'email' => $user->email,
            'password' => 'mauvais-mot-de-passe',
        ]);

        $reponse->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    // — Accès à l'espace candidat ——————————————————————————————————

    public function test_un_visiteur_est_redirige_vers_la_connexion(): void
    {
        $this->get('/candidate/dashboard')->assertRedirect('/login');
    }

    public function test_un_candidat_accede_a_son_espace(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/candidate/dashboard')->assertOk();

        // Les ecrans de candidature vivent desormais sous l'identifiant du
        // dossier ; l'entree de navigation redirige vers celui du candidat, ou
        // vers le tableau de bord tant qu'aucun dossier n'existe. Le detail du
        // parcours est couvert par CandidatureCandidatTest.
        $this->actingAs($user)->get('/candidate/application')->assertRedirect('/candidate/dashboard');
    }

    // — Cloisonnement des espaces internes (ADR-003) ————————————————

    /**
     * @return array<string, array{string}>
     */
    public static function espacesInternes(): array
    {
        return [
            'administration' => ['/admin/dashboard'],
            'evaluation' => ['/evaluator/assignments'],
            'jury' => ['/jury/dashboard'],
        ];
    }

    /**
     * Chaque espace interne rend bien l'écran qui lui appartient.
     *
     * Les deux tests suivants vérifient qui est refusé ; celui-ci vérifie ce
     * qui est servi. Sans lui, une route peut emprunter l'écran d'un autre
     * espace sans que rien ne le signale — c'est ce que faisait `/jury/dashboard`,
     * qui rendait la file de l'évaluateur.
     */
    public function test_chaque_espace_interne_rend_son_propre_ecran(): void
    {
        $jure = User::factory()->role(UserRole::JURY)->create();

        $this->actingAs($jure)
            ->get('/jury/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Jury/Dashboard'));
    }

    #[DataProvider('espacesInternes')]
    public function test_un_candidat_ne_peut_pas_ouvrir_un_espace_interne(string $url): void
    {
        $candidat = User::factory()->create();

        $this->actingAs($candidat)->get($url)->assertForbidden();
    }

    #[DataProvider('espacesInternes')]
    public function test_un_visiteur_ne_peut_pas_ouvrir_un_espace_interne(string $url): void
    {
        // Chaque espace interne renvoie vers *sa* connexion : l'administration
        // depuis ADR-006, l'évaluation depuis ADR-021. Y envoyer le visiteur
        // plutôt que vers la connexion candidat, qui ne pourrait de toute façon
        // pas l'y connecter. Le jury n'a pas encore d'accès interne — il n'a pas
        // non plus d'écran derrière la porte — et retombe donc sur /login.
        $attendu = match (true) {
            str_starts_with($url, '/admin/') => '/admin/login',
            str_starts_with($url, '/evaluator/') => '/evaluator/login',
            default => '/login',
        };

        $this->get($url)->assertRedirect($attendu);
    }

    public function test_chaque_role_interne_n_accede_qu_a_son_espace(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $evaluateur = User::factory()->role(UserRole::EVALUATOR)->create();

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin)->get('/evaluator/assignments')->assertForbidden();

        $this->actingAs($evaluateur)->get('/evaluator/assignments')->assertOk();
        $this->actingAs($evaluateur)->get('/admin/dashboard')->assertForbidden();

        // Un rôle interne n'est pas non plus un candidat.
        $this->actingAs($admin)->get('/candidate/dashboard')->assertForbidden();
    }

    // — Déconnexion ————————————————————————————————————————————————

    public function test_la_deconnexion_retire_l_acces(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/candidate/dashboard')->assertOk();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
        $this->assertGuest();

        $this->get('/candidate/dashboard')->assertRedirect('/login');
    }

    public function test_on_peut_se_reconnecter_avec_le_meme_compte(): void
    {
        $user = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $user->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_un_utilisateur_connecte_ne_revoit_pas_les_ecrans_d_authentification(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/login')->assertRedirect();
        $this->actingAs($user)->get('/register')->assertRedirect();
    }
}
