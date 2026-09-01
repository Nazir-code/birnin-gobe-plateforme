<?php

namespace Tests\Feature;

use App\Domain\Auth\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\InvitationCompteInterne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Création d'un évaluateur depuis le back-office — ADR-022.
 *
 * Ce que cette suite protège, et pourquoi chaque garantie existe :
 *
 *  1. **Le compte naît sans mot de passe utilisable.** C'est ce qui fait qu'un
 *     compte notant des candidatures n'a qu'un seul détenteur : ni
 *     l'administrateur qui l'a créé, ni personne d'autre ne peut y entrer.
 *  2. **Le rôle ne vient jamais du formulaire.** Un champ `role` transmis avec
 *     la requête ne doit produire aucun effet.
 *  3. **L'habilitation est journalisée.** Créer un compte qui notera des
 *     dossiers est une décision du §13.3, pas une écriture technique.
 *  4. **L'écran ne prétend pas avoir envoyé ce qui n'est pas parti.** C'est le
 *     défaut qui a bloqué un utilisateur réel : le message annonçait une
 *     invitation partie alors que `MAIL_MAILER=log` l'écrivait dans un fichier.
 *  5. **Un compte jamais activé se distingue d'un compte actif**, sans quoi on
 *     lui affecte des dossiers qu'il n'ouvrira jamais.
 */
final class CreationEvaluateurTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseSolide!2026';

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['password' => self::MOT_DE_PASSE]);
    }

    /** @param array<string, mixed> $donnees */
    private function creer(array $donnees = []): TestResponse
    {
        return $this->actingAs($this->admin())->post('/admin/evaluators', array_merge([
            'name' => 'Ibrahim Yacouba',
            'email' => 'ibrahim@exemple.ne',
        ], $donnees));
    }

    private function table(): string
    {
        return (string) config('auth.passwords.invitations.table');
    }

    // — Le compte créé ——————————————————————————————————————————————

    public function test_l_administrateur_cree_un_evaluateur(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();

        $this->assertDatabaseHas('users', [
            'email' => 'ibrahim@exemple.ne',
            'role' => UserRole::EVALUATOR->value,
        ]);
    }

    /**
     * Le compte existe, et personne ne peut y entrer.
     *
     * L'assertion porte sur le mot de passe que l'administrateur pourrait
     * croire avoir choisi, et sur la connexion elle-même : un compte dont on
     * devine le mot de passe n'a pas un seul détenteur.
     */
    public function test_le_compte_cree_n_a_aucun_mot_de_passe_utilisable(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();

        $evaluateur = User::query()->where('email', 'ibrahim@exemple.ne')->sole();

        $this->assertFalse(Hash::check('', $evaluateur->password));
        $this->assertFalse(Hash::check(self::MOT_DE_PASSE, $evaluateur->password));

        $this->post('/evaluator/login', [
            'email' => 'ibrahim@exemple.ne',
            'password' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');

        // Pas `assertGuest()` : la session agit comme l'administrateur qui
        // vient de créer le compte. Ce qui compte est que ses identifiants
        // supposés n'ouvrent pas la session de l'évaluateur.
        $this->assertNotSame($evaluateur->getKey(), auth()->id());
    }

    /** Le rôle est imposé par l'action, jamais transmis avec la requête. */
    public function test_le_role_transmis_par_le_formulaire_est_sans_effet(): void
    {
        Notification::fake();

        $this->creer(['role' => UserRole::ADMIN->value])->assertRedirect();

        $this->assertSame(
            UserRole::EVALUATOR,
            User::query()->where('email', 'ibrahim@exemple.ne')->sole()->role,
        );
    }

    /** La création ne promeut pas un compte existant, pas plus ici qu'en ligne de commande. */
    public function test_une_adresse_deja_utilisee_est_refusee(): void
    {
        Notification::fake();

        $candidat = User::factory()->create(['email' => 'deja@exemple.ne']);

        $this->creer(['email' => 'deja@exemple.ne'])->assertSessionHasErrors('email');

        $this->assertSame(UserRole::CANDIDATE, $candidat->fresh()->role);
    }

    public function test_un_candidat_ne_peut_pas_creer_d_evaluateur(): void
    {
        Notification::fake();

        $this->actingAs(User::factory()->create())
            ->post('/admin/evaluators', ['name' => 'X', 'email' => 'x@exemple.ne'])
            ->assertForbidden();

        Notification::assertNothingSent();
    }

    // — L'habilitation est journalisée ——————————————————————————————

    public function test_la_creation_est_inscrite_au_journal_d_audit(): void
    {
        Notification::fake();

        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/evaluators', [
            'name' => 'Ibrahim Yacouba',
            'email' => 'ibrahim@exemple.ne',
        ])->assertRedirect();

        $evenement = AuditEvent::query()->where('action', 'INTERNAL_USER_CREATED')->sole();

        $this->assertSame($admin->getKey(), $evenement->actor_id);
        $this->assertSame(UserRole::EVALUATOR->value, $evenement->new_value['role']);
        $this->assertTrue($evenement->new_value['par_invitation']);
    }

    // — L'invitation ————————————————————————————————————————————————

    public function test_une_invitation_est_emise_et_envoyee(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();

        $evaluateur = User::query()->where('email', 'ibrahim@exemple.ne')->sole();

        Notification::assertSentTo($evaluateur, InvitationCompteInterne::class);
        $this->assertDatabaseHas($this->table(), ['email' => 'ibrahim@exemple.ne']);
    }

    /**
     * Sans transport de courriel, l'écran donne le lien et le dit.
     *
     * C'est le défaut qui a réellement bloqué quelqu'un : le message annonçait
     * « une invitation vient de partir » alors que `MAIL_MAILER=log`
     * l'écrivait dans un fichier de journal. L'administrateur croyait avoir
     * prévenu, l'évaluateur ne recevait rien, et le compte restait inaccessible
     * sans que rien ne le dise.
     */
    public function test_sans_transport_de_courriel_le_lien_est_rendu_a_l_administrateur(): void
    {
        Notification::fake();
        config()->set('mail.default', 'log');

        $this->creer()
            ->assertSessionHas('invitationLink', fn (?string $lien): bool => is_string($lien) && str_contains($lien, '/invitation/'))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Aucun service d’envoi de courriel'));
    }

    /** Avec un transport réel, le lien n'est pas affiché : il finirait dans une capture d'écran. */
    public function test_avec_un_transport_reel_le_lien_n_est_pas_affiche(): void
    {
        Notification::fake();
        config()->set('mail.default', 'smtp');

        $this->creer()
            ->assertSessionMissing('invitationLink')
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'vient de partir'));
    }

    // — La relance ——————————————————————————————————————————————————

    public function test_l_invitation_peut_etre_relancee(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();
        $evaluateur = User::query()->where('email', 'ibrahim@exemple.ne')->sole();

        $premier = DB::table($this->table())->where('email', $evaluateur->email)->value('token');

        $this->actingAs($this->admin())
            ->post("/admin/evaluators/{$evaluateur->getKey()}/invitation")
            ->assertRedirect();

        $second = DB::table($this->table())->where('email', $evaluateur->email)->value('token');

        Notification::assertSentToTimes($evaluateur, InvitationCompteInterne::class, 2);
        $this->assertNotSame($premier, $second, 'Relancer émet un nouveau jeton, qui invalide le précédent.');
        $this->assertSame(1, DB::table($this->table())->where('email', $evaluateur->email)->count());
    }

    /**
     * Relancer un compte déjà actif est refusé.
     *
     * Envoyer un lien de définition de mot de passe à quelqu'un qui n'a rien
     * demandé ressemblerait à une usurpation.
     */
    public function test_on_ne_relance_pas_un_compte_deja_actif(): void
    {
        Notification::fake();

        $evaluateur = User::factory()->role(UserRole::EVALUATOR)->create(['password' => self::MOT_DE_PASSE]);

        $this->actingAs($this->admin())
            ->post("/admin/evaluators/{$evaluateur->getKey()}/invitation")
            ->assertSessionHasErrors('status');

        Notification::assertNothingSent();
    }

    /** La relance ne vise que des évaluateurs : un administrateur n'est pas une cible. */
    public function test_la_relance_ne_vise_pas_un_autre_role(): void
    {
        Notification::fake();

        $autre = User::factory()->role(UserRole::ADMIN)->create();

        $this->actingAs($this->admin())
            ->post("/admin/evaluators/{$autre->getKey()}/invitation")
            ->assertNotFound();
    }

    // — L'état affiché ——————————————————————————————————————————————

    /**
     * Un compte jamais activé se distingue d'un compte actif.
     *
     * Sans ce signal, un responsable affecte des dossiers à quelqu'un qui
     * n'ouvrira jamais la plateforme, et rien ne le lui dit.
     */
    public function test_l_ecran_distingue_un_compte_jamais_active(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();
        $actif = User::factory()->role(UserRole::EVALUATOR)->create([
            'name' => 'Déjà actif',
            'password' => self::MOT_DE_PASSE,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/evaluators')
            ->assertOk()
            ->assertInertia(function ($page) use ($actif) {
                $evaluateurs = collect($page->toArray()['props']['evaluators']);

                $this->assertTrue($evaluateurs->firstWhere('email', 'ibrahim@exemple.ne')['invitationPending']);
                $this->assertFalse($evaluateurs->firstWhere('id', $actif->getKey())['invitationPending']);
            });
    }

    // — Le parcours d'activation ————————————————————————————————————

    /**
     * L'invité définit son mot de passe, puis atteint son espace.
     *
     * Le retour se fait vers `/evaluator/login`, et c'est ce qui manquait au
     * parcours de réinitialisation existant : celui-ci renvoie tout le monde
     * vers l'écran candidat, incapable de connecter un interne.
     */
    public function test_l_invite_definit_son_mot_de_passe_et_rejoint_son_espace(): void
    {
        Notification::fake();

        $this->creer()->assertRedirect();
        $evaluateur = User::query()->where('email', 'ibrahim@exemple.ne')->sole();

        $jeton = Password::broker('invitations')->createToken($evaluateur);

        $this->get("/invitation/{$jeton}?email={$evaluateur->email}")->assertOk();

        $this->post('/invitation', [
            'token' => $jeton,
            'email' => $evaluateur->email,
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
        ])->assertRedirect('/evaluator/login');

        // Le jeton est consommé : le même lien ne sert pas deux fois.
        $this->assertDatabaseMissing($this->table(), ['email' => $evaluateur->email]);

        $this->post('/evaluator/login', [
            'email' => $evaluateur->email,
            'password' => self::MOT_DE_PASSE,
        ])->assertRedirect('/evaluator/assignments');

        $this->assertAuthenticatedAs($evaluateur->fresh());
    }

    /** Un jeton inconnu ne dit pas laquelle des causes s'applique. */
    public function test_une_invitation_invalide_est_refusee_sans_explication(): void
    {
        $this->post('/invitation', [
            'token' => 'jeton-qui-n-existe-pas',
            'email' => 'inconnu@exemple.ne',
            'password' => self::MOT_DE_PASSE,
            'password_confirmation' => self::MOT_DE_PASSE,
        ])->assertSessionHasErrors('email');
    }
}
