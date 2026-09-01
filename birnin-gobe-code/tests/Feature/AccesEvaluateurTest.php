<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Provisionnement et connexion des évaluateurs (ADR-021).
 *
 * Ce que cette suite protège, et pourquoi elle existe : l'espace évaluateur
 * était **protégé mais inatteignable**. `UserRole::EVALUATOR` existait depuis
 * ADR-004, les cinq routes de l'espace depuis ADR-015 — mais rien n'écrivait ce
 * rôle hors d'un seeder de démonstration, et aucun écran de connexion ne
 * l'admettait. Cinq routes gardées que personne ne pouvait franchir.
 *
 * Les garanties reprises d'ADR-006, qui valent ici mot pour mot :
 *
 *  1. **Aucune inscription interne.** Un formulaire public capable de produire
 *     un évaluateur est une élévation de privilège offerte.
 *  2. **Le rôle est imposé côté serveur**, jamais lu d'une saisie.
 *  3. **Le rôle est vérifié avant que la session existe.** Un candidat qui
 *     saisit ses bons identifiants ici ne doit à aucun instant être authentifié
 *     sur l'espace interne.
 *  4. **Le refus ne révèle rien** : même message pour une adresse inconnue, un
 *     mot de passe faux et un compte sans le rôle.
 *  5. **Un espace ne peut pas en bloquer un autre** : les limitations de débit
 *     sont cloisonnées.
 */
final class AccesEvaluateurTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseSolide!2026';

    private function evaluateur(string $email = 'evaluateur@exemple.test'): User
    {
        return User::factory()->role(UserRole::EVALUATOR)->create([
            'name' => 'Ibrahim Yacouba',
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

    // — Provisionnement en ligne de commande ————————————————————————

    /** Saisie interactive complète et valide, comme pour `admin:create`. */
    private function creer(
        string $nom = 'Ibrahim Yacouba',
        string $email = 'ibrahim@exemple.ne',
        string $motDePasse = self::MOT_DE_PASSE,
    ): PendingCommand {
        return $this->artisan('evaluator:create')
            ->expectsQuestion('Nom complet', $nom)
            ->expectsQuestion('Adresse e-mail', $email)
            ->expectsQuestion('Mot de passe', $motDePasse)
            ->expectsQuestion('Confirmer le mot de passe', $motDePasse);
    }

    public function test_la_commande_cree_un_evaluateur(): void
    {
        $this->creer()->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'ibrahim@exemple.ne',
            'name' => 'Ibrahim Yacouba',
        ]);
    }

    public function test_le_role_cree_est_evaluateur(): void
    {
        $this->creer()->assertSuccessful();

        $this->assertSame(
            UserRole::EVALUATOR,
            User::query()->where('email', 'ibrahim@exemple.ne')->sole()->role,
        );
    }

    /**
     * Le rôle n'est pas une option de la commande.
     *
     * S'il l'était, le privilège dépendrait d'une saisie, et la distinction
     * entre `admin:create` et `evaluator:create` deviendrait un argument qu'on
     * peut se tromper de taper. L'assertion porte sur la **définition** de la
     * commande : c'est la seule formulation qu'un futur `--role` ferait échouer.
     */
    public function test_le_role_n_est_pas_une_option(): void
    {
        foreach (['evaluator:create', 'admin:create'] as $commande) {
            $definition = $this->app->make(Kernel::class)->all()[$commande]->getDefinition();

            $this->assertFalse(
                $definition->hasOption('role'),
                "La commande {$commande} ne doit pas laisser choisir le rôle.",
            );
        }
    }

    public function test_le_mot_de_passe_est_hache(): void
    {
        $this->creer()->assertSuccessful();

        $evaluateur = User::query()->where('email', 'ibrahim@exemple.ne')->sole();

        $this->assertNotSame(self::MOT_DE_PASSE, $evaluateur->password);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $evaluateur->password));
    }

    /** Le mot de passe n'est jamais réaffiché, pas même à l'opérateur qui l'a saisi. */
    public function test_le_mot_de_passe_n_est_jamais_reaffiche(): void
    {
        $this->creer()->doesntExpectOutputToContain(self::MOT_DE_PASSE)->assertSuccessful();
    }

    /** La commande crée ; elle ne promeut pas un compte existant. */
    public function test_un_candidat_existant_n_est_pas_promu(): void
    {
        $candidat = $this->candidat('deja@exemple.ne');

        $this->creer(email: 'deja@exemple.ne')->assertFailed();

        $this->assertSame(UserRole::CANDIDATE, $candidat->fresh()->role);
    }

    /** L'inscription publique ne produit jamais autre chose qu'un candidat. */
    public function test_aucune_inscription_ne_produit_un_evaluateur(): void
    {
        $this->post('/register', [
            'name' => 'Ibrahim Yacouba',
            'email' => 'ibrahim@exemple.ne',
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
            'role' => UserRole::EVALUATOR->value,
        ])->assertRedirect();

        $this->assertSame(
            UserRole::CANDIDATE,
            User::query()->where('email', 'ibrahim@exemple.ne')->sole()->role,
        );
    }

    /** Il n'existe pas d'inscription évaluateur, et il ne doit jamais en exister. */
    #[DataProvider('methodesDInscription')]
    public function test_il_n_existe_pas_d_inscription_evaluateur(string $methode): void
    {
        $this->call($methode, '/evaluator/register')->assertNotFound();
    }

    /** @return array<string, list<string>> */
    public static function methodesDInscription(): array
    {
        return ['affichage' => ['GET'], 'envoi' => ['POST']];
    }

    // — Connexion ——————————————————————————————————————————————————

    public function test_un_evaluateur_peut_se_connecter(): void
    {
        $evaluateur = $this->evaluateur();

        $this->post('/evaluator/login', [
            'email' => $evaluateur->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/evaluator/assignments');

        $this->assertAuthenticatedAs($evaluateur);
    }

    public function test_l_acces_evaluateur_est_joignable_par_un_visiteur(): void
    {
        $this->get('/evaluator/login')->assertOk();
    }

    public function test_un_evaluateur_connecte_ouvre_son_plan_de_travail(): void
    {
        $this->actingAs($this->evaluateur())
            ->get('/evaluator/assignments')
            ->assertOk();
    }

    /**
     * Un candidat ne franchit pas la porte, même avec ses bons identifiants.
     *
     * Le rôle est contrôlé *avant* qu'une session existe : l'assertion porte
     * donc sur `assertGuest()`, et non sur une redirection. Un `Auth::attempt`
     * suivi d'un `logout()` laisserait une session valide, même brève.
     */
    public function test_un_candidat_ne_peut_pas_se_connecter_sur_l_espace_evaluateur(): void
    {
        $candidat = $this->candidat();

        $this->post('/evaluator/login', [
            'email' => $candidat->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** Un administrateur non plus : l'espace n'admet qu'un rôle. */
    public function test_un_admin_ne_peut_pas_se_connecter_sur_l_espace_evaluateur(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create(['password' => self::MOT_DE_PASSE]);

        $this->post('/evaluator/login', [
            'email' => $admin->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** Et un évaluateur n'entre pas par la porte de l'administration. */
    public function test_un_evaluateur_ne_peut_pas_se_connecter_sur_l_espace_admin(): void
    {
        $evaluateur = $this->evaluateur();

        $this->post('/admin/login', [
            'email' => $evaluateur->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Le refus ne dit pas laquelle des trois causes s'applique.
     *
     * Distinguer « adresse inconnue » de « compte existant sans le rôle »
     * reviendrait à confirmer qui travaille sur le concours.
     */
    public function test_le_refus_ne_revele_pas_le_role_du_compte(): void
    {
        $this->candidat('connu@exemple.test');

        $refusCandidat = $this->post('/evaluator/login', [
            'email' => 'connu@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $refusInconnu = $this->post('/evaluator/login', [
            'email' => 'inconnu@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        $this->assertSame(
            $refusCandidat->getSession()->get('errors')?->first('email'),
            $refusInconnu->getSession()->get('errors')?->first('email'),
        );
    }

    // — Redirection des visiteurs ——————————————————————————————————

    /**
     * Un visiteur anonyme de l'espace évaluateur est envoyé à *sa* connexion.
     *
     * Il atterrissait sur `/login`, l'écran candidat, qui ne peut pas
     * l'authentifier : une porte fermée dont la sonnette mène chez le voisin.
     */
    public function test_un_visiteur_est_renvoye_vers_l_acces_evaluateur(): void
    {
        $this->get('/evaluator/assignments')->assertRedirect('/evaluator/login');
    }

    public function test_un_visiteur_de_l_admin_reste_renvoye_vers_l_acces_admin(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
    }

    public function test_un_visiteur_du_candidat_reste_renvoye_vers_la_connexion_candidat(): void
    {
        $this->get('/candidate/dashboard')->assertRedirect('/login');
    }

    public function test_un_evaluateur_connecte_est_renvoye_vers_son_plan_de_travail(): void
    {
        $this->actingAs($this->evaluateur())
            ->get('/evaluator/login')
            ->assertRedirect('/evaluator/assignments');
    }

    /**
     * Une autre identité voit l'écran, avec le moyen d'en sortir.
     *
     * Le renvoi muet vers l'accueil était un cul-de-sac : rien n'expliquait
     * l'obstacle, et rien ne permettait de le lever.
     */
    public function test_un_candidat_connecte_voit_l_ecran_et_le_moyen_d_en_sortir(): void
    {
        $this->actingAs($this->candidat())
            ->get('/evaluator/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sessionEnCours.logoutUrl', url('/evaluator/logout'))
                ->has('sessionEnCours.name'));
    }

    /** Et le geste proposé fonctionne : il ferme la session et ramène ici. */
    public function test_fermer_la_session_en_cours_ramene_a_l_ecran_evaluateur(): void
    {
        $this->actingAs($this->candidat())
            ->post('/evaluator/logout')
            ->assertRedirect('/evaluator/login');

        $this->assertGuest();
        $this->get('/evaluator/login')->assertOk();
    }

    // — Déconnexion ————————————————————————————————————————————————

    /** La déconnexion ramène à l'accès interne, jamais à un écran candidat. */
    public function test_un_evaluateur_peut_se_deconnecter(): void
    {
        $this->actingAs($this->evaluateur())
            ->post('/evaluator/logout')
            ->assertRedirect('/evaluator/login');

        $this->assertGuest();
    }

    public function test_la_deconnexion_retire_l_acces(): void
    {
        $evaluateur = $this->evaluateur();

        $this->actingAs($evaluateur)->post('/evaluator/logout');

        $this->get('/evaluator/assignments')->assertRedirect('/evaluator/login');
    }

    // — Cloisonnement des limitations ——————————————————————————————

    /**
     * Marteler un espace ne ferme pas l'autre.
     *
     * Sans clé de limitation propre à chaque espace, saturer le formulaire
     * candidat avec l'adresse d'un évaluateur suffirait à lui interdire son
     * plan de travail : un déni de service à un seul paramètre.
     */
    public function test_le_blocage_d_un_espace_ne_ferme_pas_l_evaluateur(): void
    {
        $evaluateur = $this->evaluateur();

        for ($i = 0; $i < 6; $i++) {
            $this->post('/login', [
                'email' => $evaluateur->email,
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        $this->post('/evaluator/login', [
            'email' => $evaluateur->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/evaluator/assignments');
    }

    /** Et saturer l'accès évaluateur ne ferme pas l'administration. */
    public function test_le_blocage_de_l_evaluateur_ne_ferme_pas_l_admin(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create([
            'email' => 'admin@exemple.test',
            'password' => self::MOT_DE_PASSE,
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/evaluator/login', [
                'email' => $admin->email,
                'password' => 'mauvais-mot-de-passe',
            ]);
        }

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/admin/dashboard');
    }
}
