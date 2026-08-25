<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ReinitialisationMotDePasse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Mot de passe oublié, puis réinitialisé.
 *
 * C'est le seul courriel que la plateforme envoie, et le seul chemin de retour
 * pour un candidat qui a perdu l'accès à son dossier. Ce que cette suite
 * protège, dans l'ordre d'importance :
 *
 * 1. **Le formulaire ne dit jamais qui est inscrit.** Adresse connue, adresse
 *    inconnue, demande trop rapprochée : même réponse, même message, même code
 *    HTTP. Une plateforme de candidatures individuelles ne doit pas offrir un
 *    moyen public d'établir la liste de ses inscrits.
 *
 * 2. **Le jeton est un vrai jeton.** Haché en base, à usage unique, périmé
 *    après le délai de `config/auth.php`, et refusé pour une autre adresse que
 *    la sienne.
 *
 * 3. **Réinitialiser coupe les connexions persistantes.** Le jeton « rester
 *    connecté » change, donc les cookies émis avant le changement cessent de
 *    valoir. Les sessions serveur déjà ouvertes ailleurs, elles, ne sont pas
 *    révoquées par ce seul geste — voir `NewPasswordController`.
 *
 * Aucun serveur SMTP n'est nécessaire : `phpunit.xml` impose le transport
 * `array`, et les notifications sont interceptées.
 */
final class ReinitialisationMotDePasseTest extends TestCase
{
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'MotDePasseSolide!2026';

    private const NOUVEAU = 'UnAutreMotDePasse!2027';

    protected function setUp(): void
    {
        parent::setUp();

        // Les compteurs de limitation vivent dans le cache et survivent d'un
        // test à l'autre : sans cela, l'ordre d'exécution changerait le
        // résultat.
        RateLimiter::clear('mot-de-passe-oublie|candidat@example.test|127.0.0.1');
        cache()->clear();
    }

    private function candidat(string $email = 'candidat@example.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => self::MOT_DE_PASSE,
        ]);
    }

    // — Les écrans ————————————————————————————————————————————————

    public function test_les_deux_ecrans_sont_accessibles_a_un_visiteur(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ForgotPassword'));

        $this->get('/reset-password/un-jeton?email=candidat@example.test')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/ResetPassword')
                // Le lien porte les deux valeurs : on ne les redemande pas.
                ->where('token', 'un-jeton')
                ->where('email', 'candidat@example.test'));
    }

    /**
     * Réservés aux visiteurs non connectés.
     *
     * Quelqu'un déjà authentifié n'a pas besoin d'un lien par courriel, et lui
     * laisser cette porte ouverte offrirait un moyen de forger un lien de
     * réinitialisation pour une adresse choisie.
     */
    #[DataProvider('ecransDeReinitialisation')]
    public function test_un_visiteur_connecte_est_redirige(string $url): void
    {
        $this->actingAs($this->candidat())->get($url)->assertRedirect();
    }

    /** @return array<string, array{string}> */
    public static function ecransDeReinitialisation(): array
    {
        return [
            'demande' => ['/forgot-password'],
            'choix du mot de passe' => ['/reset-password/un-jeton'],
        ];
    }

    // — La demande ————————————————————————————————————————————————

    public function test_une_adresse_connue_recoit_un_lien(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        $this->post('/forgot-password', ['email' => $candidat->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        Notification::assertSentTo($candidat, ReinitialisationMotDePasse::class);
    }

    /**
     * Le cœur de la non-divulgation.
     *
     * Une adresse inconnue produit exactement la même réponse qu'une adresse
     * connue : même redirection, même message. Seule diffère la présence d'un
     * courriel, que le visiteur ne peut pas observer.
     */
    public function test_une_adresse_inconnue_produit_la_meme_reponse(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        $connue = $this->post('/forgot-password', ['email' => $candidat->email]);
        cache()->clear();
        $inconnue = $this->post('/forgot-password', ['email' => 'personne@example.test']);

        $this->assertSame($connue->getStatusCode(), $inconnue->getStatusCode());
        $this->assertSame(
            session()->get('status'),
            $inconnue->getSession()->get('status'),
        );
        $inconnue->assertSessionHasNoErrors();

        Notification::assertCount(1);
    }

    /**
     * Une demande trop rapprochée ne se distingue pas non plus.
     *
     * C'est le piège de cette fonctionnalité : le broker de Laravel refuse une
     * seconde demande dans la minute avec un verdict `RESET_THROTTLED`, qui ne
     * peut venir que d'une adresse existante. L'afficher tel quel suffirait à
     * énumérer les comptes. Le message reste donc le même.
     */
    public function test_une_demande_trop_rapprochee_ne_revele_rien(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        $this->post('/forgot-password', ['email' => $candidat->email])->assertSessionHas('status');
        $premier = session()->get('status');

        $second = $this->post('/forgot-password', ['email' => $candidat->email]);

        $second->assertSessionHasNoErrors();
        $this->assertSame($premier, $second->getSession()->get('status'));

        // Le broker a bien refusé le second envoi : une seule notification.
        Notification::assertCount(1);
    }

    public function test_une_adresse_mal_formee_est_refusee(): void
    {
        $this->post('/forgot-password', ['email' => 'pas-une-adresse'])
            ->assertSessionHasErrors('email');
    }

    /**
     * La limitation compte **toutes** les demandes, abouties ou non.
     *
     * Elle protège le formulaire lui-même ; celle du broker, dans
     * `config/auth.php`, protège une boîte donnée. Les deux sont nécessaires et
     * ne visent pas la même chose.
     */
    public function test_les_demandes_repetees_sont_limitees(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => $candidat->email])->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => $candidat->email])
            ->assertSessionHasErrors('email');
    }

    /** Le compteur vaut aussi pour une adresse inconnue : sinon il suffirait d'en changer. */
    public function test_la_limitation_s_applique_aussi_a_une_adresse_inconnue(): void
    {
        Notification::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'personne@example.test'])->assertSessionHasNoErrors();
        }

        $this->post('/forgot-password', ['email' => 'personne@example.test'])
            ->assertSessionHasErrors('email');
    }

    // — Le jeton ——————————————————————————————————————————————————

    /** Le jeton est haché en base : voler la table ne donne pas de lien utilisable. */
    public function test_le_jeton_n_est_pas_stocke_en_clair(): void
    {
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $enregistre = \DB::table('password_reset_tokens')->where('email', $candidat->email)->value('token');

        $this->assertNotSame($jeton, $enregistre);
        $this->assertTrue(Hash::check($jeton, $enregistre));
    }

    public function test_un_jeton_valide_change_le_mot_de_passe(): void
    {
        Event::fake([PasswordReset::class]);
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertTrue(Hash::check(self::NOUVEAU, $candidat->fresh()->password));
        Event::assertDispatched(PasswordReset::class);

        // Et l'ancien mot de passe ne vaut plus rien.
        $this->post('/login', ['email' => $candidat->email, 'password' => self::MOT_DE_PASSE])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    /** Le lien ne sert qu'une fois : le jeton est consommé. */
    public function test_un_jeton_ne_sert_qu_une_fois(): void
    {
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $charge = [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ];

        $this->post('/reset-password', $charge)->assertSessionHas('status');
        $this->post('/reset-password', $charge)->assertSessionHasErrors('email');
    }

    /** Passé le délai de `config/auth.php`, le lien ne vaut plus rien. */
    public function test_un_jeton_expire_est_refuse(): void
    {
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $minutes = (int) config('auth.passwords.users.expire');
        $this->travel($minutes + 1)->minutes();

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $candidat->fresh()->password));
    }

    /** Un jeton émis pour un compte ne vaut pas pour un autre. */
    public function test_un_jeton_ne_vaut_que_pour_son_compte(): void
    {
        $victime = $this->candidat('victime@example.test');
        $attaquant = $this->candidat('attaquant@example.test');

        $jeton = Password::createToken($attaquant);

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $victime->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $victime->fresh()->password));
    }

    /**
     * Tous les échecs rendent le même message.
     *
     * Jeton inventé, jeton périmé, adresse inconnue : distinguer les trois
     * renseignerait sur l'existence du compte visé.
     */
    public function test_tous_les_echecs_rendent_le_meme_message(): void
    {
        $candidat = $this->candidat();

        $messages = [];

        foreach ([
            ['token' => 'jeton-invente', 'email' => $candidat->email],
            ['token' => 'jeton-invente', 'email' => 'personne@example.test'],
        ] as $cas) {
            $reponse = $this->post('/reset-password', [
                ...$cas,
                'password' => self::NOUVEAU,
                'password_confirmation' => self::NOUVEAU,
            ]);

            $messages[] = $reponse->getSession()->get('errors')->first('email');
        }

        $this->assertCount(2, array_filter($messages));
        $this->assertSame($messages[0], $messages[1]);
    }

    // — Le nouveau mot de passe ————————————————————————————————————

    public function test_un_mot_de_passe_trop_court_est_refuse(): void
    {
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => 'court',
            'password_confirmation' => 'court',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check(self::MOT_DE_PASSE, $candidat->fresh()->password));
    }

    public function test_une_confirmation_differente_est_refusee(): void
    {
        $candidat = $this->candidat();
        $jeton = Password::createToken($candidat);

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => 'AutreChose!2027',
        ])->assertSessionHasErrors('password');
    }

    /**
     * Le jeton « rester connecté » change : les cookies persistants émis avant
     * la réinitialisation ne valent plus rien.
     *
     * Ce test ne prouve que cela, et c'est tout ce que le code fait : une
     * session serveur déjà ouverte ailleurs n'est pas révoquée par ce geste.
     */
    public function test_la_reinitialisation_invalide_les_sessions_persistantes(): void
    {
        $candidat = $this->candidat();
        $candidat->forceFill(['remember_token' => 'ancien-jeton-de-session'])->save();

        $jeton = Password::createToken($candidat);

        $this->post('/reset-password', [
            'token' => $jeton,
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ])->assertSessionHas('status');

        $this->assertNotSame('ancien-jeton-de-session', $candidat->fresh()->remember_token);
    }

    // — Le courriel ————————————————————————————————————————————————

    /**
     * Le message porte un lien exploitable et ne dit rien du compte.
     *
     * Un courriel peut se retrouver sous d'autres yeux que ceux de son
     * destinataire : il n'y a donc ni nom, ni état du dossier, ni mot de passe.
     */
    public function test_le_courriel_porte_un_lien_utilisable_et_rien_de_plus(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        $this->post('/forgot-password', ['email' => $candidat->email]);

        Notification::assertSentTo($candidat, ReinitialisationMotDePasse::class, function ($notification) use ($candidat) {
            $message = $notification->toMail($candidat);
            $lien = $message->actionUrl;

            $this->assertStringContainsString('/reset-password/', $lien);
            $this->assertStringContainsString(urlencode($candidat->email), $lien);
            $this->assertStringContainsString('Réinitialisation', $message->subject);

            // Le nom du titulaire n'apparaît nulle part dans le message.
            $corps = implode(' ', [...$message->introLines, ...$message->outroLines]);
            $this->assertStringNotContainsString($candidat->name, $corps);
            $this->assertStringContainsString('60 minutes', $corps);

            return true;
        });
    }

    /**
     * Le lien du courriel mène réellement à l'écran, et le jeton qu'il porte
     * fonctionne. C'est le parcours complet, de bout en bout.
     */
    public function test_le_lien_recu_mene_a_un_ecran_qui_accepte_le_jeton(): void
    {
        Notification::fake();
        $candidat = $this->candidat();

        $this->post('/forgot-password', ['email' => $candidat->email]);

        $lien = null;

        Notification::assertSentTo($candidat, ReinitialisationMotDePasse::class, function ($notification) use ($candidat, &$lien) {
            $lien = $notification->toMail($candidat)->actionUrl;

            return true;
        });

        $this->get($lien)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/ResetPassword'));

        // Le jeton extrait du lien change effectivement le mot de passe.
        preg_match('#/reset-password/([^?]+)#', (string) $lien, $trouve);

        $this->post('/reset-password', [
            'token' => $trouve[1],
            'email' => $candidat->email,
            'password' => self::NOUVEAU,
            'password_confirmation' => self::NOUVEAU,
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::NOUVEAU, $candidat->fresh()->password));
    }

    /** Le transport est configuré : sans `config/mail.php`, rien ne partirait. */
    public function test_le_transport_mail_est_configure(): void
    {
        $this->assertSame('array', config('mail.default'), 'Les tests n’envoient jamais de vrai courriel.');
        $this->assertNotEmpty(config('mail.from.address'));
        $this->assertArrayHasKey('smtp', config('mail.mailers'));
        $this->assertArrayHasKey('log', config('mail.mailers'));
    }
}
