<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Provisionnement des comptes internes (ADR-006).
 *
 * Le seul chemin d'existence d'un administrateur est `php artisan admin:create`.
 * Ces tests vérifient à la fois qu'il fonctionne et qu'aucun autre n'existe.
 */
final class ProvisionnementAdministrateurTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseSolide!2026';

    /** Saisie interactive complète et valide. */
    private function creer(
        string $nom = 'Aïcha Diallo',
        string $email = 'aicha@exemple.test',
        string $motDePasse = self::MOT_DE_PASSE,
        ?string $confirmation = null,
    ): PendingCommand {
        return $this->artisan('admin:create')
            ->expectsQuestion('Nom complet', $nom)
            ->expectsQuestion('Adresse e-mail', $email)
            ->expectsQuestion('Mot de passe', $motDePasse)
            ->expectsQuestion('Confirmer le mot de passe', $confirmation ?? $motDePasse);
    }

    // — La commande ————————————————————————————————————————————————

    public function test_la_commande_cree_un_utilisateur(): void
    {
        $this->creer()->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'aicha@exemple.test',
            'name' => 'Aïcha Diallo',
        ]);
    }

    public function test_le_role_cree_est_admin(): void
    {
        $this->creer()->assertSuccessful();

        $admin = User::where('email', 'aicha@exemple.test')->firstOrFail();

        $this->assertSame(UserRole::ADMIN, $admin->role);
    }

    public function test_le_mot_de_passe_est_hache(): void
    {
        $this->creer()->assertSuccessful();

        $admin = User::where('email', 'aicha@exemple.test')->firstOrFail();

        $this->assertNotSame(self::MOT_DE_PASSE, $admin->password);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $admin->password));
    }

    public function test_le_mot_de_passe_n_est_jamais_reaffiche(): void
    {
        $this->creer()->assertSuccessful()->doesntExpectOutputToContain(self::MOT_DE_PASSE);
    }

    public function test_l_email_est_normalise_en_minuscules(): void
    {
        $this->creer(email: 'Aicha@Exemple.Test')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'aicha@exemple.test']);
    }

    public function test_les_options_evitent_les_invites(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ousmane Bâ', '--email' => 'ousmane@exemple.test'])
            ->expectsQuestion('Mot de passe', self::MOT_DE_PASSE)
            ->expectsQuestion('Confirmer le mot de passe', self::MOT_DE_PASSE)
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'ousmane@exemple.test']);
    }

    // — Refus ——————————————————————————————————————————————————————

    public function test_un_email_deja_utilise_est_refuse(): void
    {
        User::factory()->create(['email' => 'deja@exemple.test']);

        $this->creer(email: 'deja@exemple.test')->assertFailed();

        $this->assertSame(1, User::where('email', 'deja@exemple.test')->count());
    }

    /**
     * Un compte candidat existant ne doit pas pouvoir être « repris » en admin
     * par la commande : elle crée, elle ne promeut pas.
     */
    public function test_un_candidat_existant_n_est_pas_promu(): void
    {
        $candidat = User::factory()->create(['email' => 'candidat@exemple.test']);

        $this->creer(email: 'candidat@exemple.test')->assertFailed();

        $this->assertSame(UserRole::CANDIDATE, $candidat->fresh()->role);
    }

    #[DataProvider('emailsInvalides')]
    public function test_un_email_invalide_est_refuse(string $email): void
    {
        $this->creer(email: $email)->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** @return array<string, array{string}> */
    public static function emailsInvalides(): array
    {
        return [
            'vide' => [''],
            'sans arobase' => ['aicha.exemple.test'],
            'sans domaine' => ['aicha@'],
            'avec espace' => ['ai cha@exemple.test'],
        ];
    }

    public function test_un_mot_de_passe_trop_court_est_refuse(): void
    {
        $this->creer(motDePasse: 'court')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_un_nom_vide_est_refuse(): void
    {
        $this->creer(nom: '')->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_une_confirmation_differente_est_refusee(): void
    {
        $this->creer(confirmation: 'UnAutreMotDePasse!2026')->assertFailed();

        $this->assertSame(0, User::count());
    }

    // — Aucun autre chemin de création ————————————————————————————

    public function test_il_n_existe_aucune_inscription_admin(): void
    {
        $this->get('/admin/register')->assertNotFound();
        $this->post('/admin/register', [
            'name' => 'Intrus',
            'email' => 'intrus@exemple.test',
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
        ])->assertNotFound();

        $this->assertSame(0, User::count());
    }

    /**
     * Le compte provisionné est réellement utilisable : la commande n'est pas
     * qu'une écriture en base, elle produit un accès.
     */
    public function test_le_compte_provisionne_ouvre_l_espace_interne(): void
    {
        $this->creer()->assertSuccessful();

        $this->post('/admin/login', [
            'email' => 'aicha@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/admin/dashboard');

        $this->get('/admin/dashboard')->assertOk();
    }
}
