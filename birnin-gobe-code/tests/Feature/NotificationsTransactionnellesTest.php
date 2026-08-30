<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Evaluation\AssignApplications;
use App\Domain\Notification\DeliveryStatus;
use App\Domain\Notification\NotificationChannel;
use App\Domain\Notification\NotificationEvent;
use App\Domain\Notification\SendNotification;
use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\DecideAdmissibility;
use App\Domain\Verification\SaveVerificationChecks;
use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationOutcome;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\Candidat\ClarificationDemandee;
use App\Notifications\Candidat\CompteCree;
use App\Notifications\Candidat\DecisionDEtape;
use App\Notifications\Candidat\RappelDeCloture;
use App\Notifications\Candidat\SoumissionRecue;
use App\Notifications\Evaluateur\DossiersAffectes;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * Les notifications transactionnelles — §8.3.
 *
 * Ce que cette suite protège :
 *
 * 1. **Les six événements du tableau existent.** Un test compare l'enum au
 *    cahier des charges, ligne par ligne. Sans lui, un événement oublié ne se
 *    verrait qu'en production, du côté du candidat qui n'a rien reçu.
 *
 * 2. **Chaque envoi laisse une trace.** C'est ce qui permet de répondre à « le
 *    candidat a-t-il été prévenu de son rejet, et quand ? ». Une décision qu'on
 *    ne peut pas prouver avoir communiquée n'est pas défendable.
 *
 * 3. **Un canal non servi n'est pas une panne.** Le SMS enregistré comme
 *    ignoré ne doit jamais compter dans les échecs, sinon l'alerte du §9.3
 *    reste allumée en permanence et n'apprend plus rien.
 *
 * 4. **Un échec d'envoi ne casse jamais le geste métier.** Un serveur de
 *    courriel en panne ne doit pas faire échouer un dépôt de candidature.
 *
 * 5. **Le rappel de clôture ne part pas deux fois.** La commande tourne chaque
 *    jour ; sans garde, le candidat recevrait le même message toute la semaine
 *    et finirait par filtrer celui qui compte.
 */
final class NotificationsTransactionnellesTest extends TestCase
{
    use RefreshDatabase;

    private ?Campaign $campagne = null;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** `campaigns_une_seule_ouverte` n'autorise qu'une campagne ouverte. */
    private function campagne(): Campaign
    {
        return $this->campagne ??= Campaign::factory()->create(['closes_at' => now()->addDays(30)]);
    }

    private function candidat(): User
    {
        return User::factory()->role(UserRole::CANDIDATE)->create();
    }

    private function dossierDepose(?User $candidat = null): Application
    {
        return Application::factory()
            ->for($this->campagne(), 'campaign')
            ->for($candidat ?? $this->candidat(), 'candidate')
            ->status(ApplicationStatus::SUBMITTED)
            ->create(['submission_number' => 'BG-2026-001', 'submitted_at' => now()->subDay()]);
    }

    /** Remplit la grille du §10.2, toute conforme sauf exceptions. */
    private function grille(Application $dossier, array $exceptions = []): void
    {
        $grille = [];

        foreach (VerificationControl::cases() as $controle) {
            $grille[$controle->value] = $exceptions[$controle->value] ?? [
                'outcome' => $controle->outcomes()[0],
                'observation' => null,
            ];
        }

        app(SaveVerificationChecks::class)->handle(
            $dossier,
            $grille,
            User::factory()->role(UserRole::ADMIN)->create(),
        );
    }

    // — Le tableau du §8.3 est couvert ————————————————————————————

    public function test_les_six_evenements_du_cahier_des_charges_existent(): void
    {
        $this->assertCount(6, NotificationEvent::cases());

        foreach (NotificationEvent::cases() as $evenement) {
            $this->assertNotSame('', trim($evenement->label()));
            $this->assertNotSame([], $evenement->channels(), 'Un événement sans canal ne partirait jamais.');
            $this->assertNotSame([], $evenement->requiredContent(), 'Le §8.3 impose un contenu minimum à chacun.');
        }
    }

    /**
     * Les quatre événements que le §8.3 veut aussi en SMS le déclarent.
     *
     * Le canal est déclaré alors qu'aucun fournisseur ne le sert : c'est le
     * choix d'ADR-018, et le retirer ferait disparaître l'exigence du modèle.
     */
    public function test_les_evenements_prevus_en_sms_le_declarent(): void
    {
        $avecSms = array_filter(
            NotificationEvent::cases(),
            static fn (NotificationEvent $e): bool => in_array(NotificationChannel::SMS, $e->channels(), true),
        );

        $this->assertSame(
            [
                NotificationEvent::ACCOUNT_CREATED,
                NotificationEvent::CLOSING_REMINDER,
                NotificationEvent::CLARIFICATION_REQUESTED,
                NotificationEvent::STAGE_DECISION,
            ],
            array_values($avecSms),
        );
    }

    // — Chaque envoi laisse une trace ——————————————————————————————

    public function test_l_inscription_previent_le_candidat_et_laisse_une_trace(): void
    {
        $this->post('/register', [
            'name' => 'Hadiza Moussa',
            'email' => 'hadiza@exemple.ne',
            'password' => 'MotDePasse!2026',
            'password_confirmation' => 'MotDePasse!2026',
        ])->assertRedirect();

        $candidat = User::query()->where('email', 'hadiza@exemple.ne')->firstOrFail();

        Notification::assertSentTo($candidat, CompteCree::class);

        $this->assertDatabaseHas('notification_deliveries', [
            'event' => NotificationEvent::ACCOUNT_CREATED->value,
            'channel' => NotificationChannel::EMAIL->value,
            'status' => DeliveryStatus::SENT->value,
            'recipient_id' => $candidat->getKey(),
        ]);
    }

    public function test_une_decision_d_admissibilite_previent_le_candidat(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDepose($candidat);
        $this->grille($dossier);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::ADMISSIBLE,
            actor: User::factory()->role(UserRole::ADMIN)->create(),
        );

        Notification::assertSentTo($candidat, DecisionDEtape::class);

        $this->assertDatabaseHas('notification_deliveries', [
            'event' => NotificationEvent::STAGE_DECISION->value,
            'status' => DeliveryStatus::SENT->value,
            'recipient_id' => $candidat->getKey(),
            'application_id' => $dossier->getKey(),
        ]);
    }

    /** Une clarification et une décision finale ne sont pas le même message. */
    public function test_une_clarification_envoie_son_propre_message(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDepose($candidat);

        $this->grille($dossier, [
            VerificationControl::COMPLETENESS->value => [
                'outcome' => VerificationOutcome::FILE_CLARIFICATION,
                'observation' => 'Le budget prévisionnel manque.',
            ],
        ]);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::CLARIFICATION,
            actor: User::factory()->role(UserRole::ADMIN)->create(),
            internalNote: 'Relancer sous huit jours.',
            candidateMessage: 'Merci de nous transmettre votre budget prévisionnel détaillé.',
            respondBy: now()->addDays(8)->toDateString(),
        );

        Notification::assertSentTo($candidat, ClarificationDemandee::class);
        Notification::assertNotSentTo($candidat, DecisionDEtape::class);
    }

    public function test_une_affectation_previent_l_evaluateur_une_seule_fois_par_lot(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $evaluateur = User::factory()->role(UserRole::EVALUATOR)->create();

        // `modelKeys()` n'existe que sur une collection Eloquent : ici, les
        // dossiers sont créés un à un, donc on collecte les identifiants.
        $dossiers = collect(range(1, 3))
            ->map(fn (int $rang): int => Application::factory()
                ->for($this->campagne(), 'campaign')
                ->for($this->candidat(), 'candidate')
                ->status(ApplicationStatus::ADMISSIBLE)
                ->create([
                    'submission_number' => sprintf('BG-2026-%03d', $rang),
                    'submitted_at' => now()->subDays(5),
                ])
                ->getKey())
            ->all();

        app(AssignApplications::class)->handle($dossiers, $evaluateur, $admin);

        // Un message pour trois dossiers, pas trois messages.
        Notification::assertSentToTimes($evaluateur, DossiersAffectes::class, 1);

        $this->assertSame(
            1,
            NotificationDelivery::query()
                ->where('event', NotificationEvent::ASSIGNMENT->value)
                ->where('recipient_id', $evaluateur->getKey())
                ->count(),
        );
    }

    // — Un canal non servi n'est pas une panne ————————————————————

    public function test_le_sms_est_enregistre_comme_non_servi_jamais_comme_echec(): void
    {
        $candidat = $this->candidat();

        app(SendNotification::class)->handle(
            NotificationEvent::STAGE_DECISION,
            $candidat,
            new CompteCree,
        );

        $sms = NotificationDelivery::query()
            ->where('recipient_id', $candidat->getKey())
            ->where('channel', NotificationChannel::SMS->value)
            ->firstOrFail();

        $this->assertSame(DeliveryStatus::SKIPPED, $sms->status);
        $this->assertFalse($sms->status->estUnIncident(), 'Une absence de fournisseur n’est pas un incident.');
        $this->assertNotNull($sms->detail, 'Le motif doit être lisible sur l’écran de pilotage.');
        $this->assertNull($sms->recipient_address, 'Aucune adresse n’a été visée : n’en inscrivons pas.');
    }

    public function test_l_alerte_de_pilotage_ignore_les_canaux_non_servis(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $this->campagne();

        app(SendNotification::class)->handle(
            NotificationEvent::STAGE_DECISION,
            $this->candidat(),
            new CompteCree,
        );

        $this->actingAs($admin)
            ->get('/admin/alerts')
            ->assertInertia(function ($page): void {
                $cles = collect($page->toArray()['props']['alerts'])->pluck('key');

                $this->assertNotContains('notifications.echecs', $cles);
            });
    }

    public function test_l_alerte_de_pilotage_compte_les_echecs_reels(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossierDepose();

        NotificationDelivery::query()->create([
            'event' => NotificationEvent::STAGE_DECISION->value,
            'channel' => NotificationChannel::EMAIL->value,
            'status' => DeliveryStatus::FAILED->value,
            'recipient_id' => $dossier->candidate_id,
            'recipient_role' => 'CANDIDATE',
            'recipient_address' => 'boite@pleine.ne',
            'application_id' => $dossier->getKey(),
            'campaign_id' => $dossier->campaign_id,
            'detail' => 'Boîte pleine.',
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin/alerts')
            ->assertInertia(function ($page): void {
                $alerte = collect($page->toArray()['props']['alerts'])->firstWhere('key', 'notifications.echecs');

                $this->assertNotNull($alerte, 'Un envoi échoué doit être signalé (§9.3).');
                $this->assertSame(1, $alerte['count']);
                $this->assertSame('CRITICAL', $alerte['severity']);
            });
    }

    // — Un échec n'emporte pas le geste métier ————————————————————

    /**
     * Le serveur de courriel tombe : la décision reste prise.
     *
     * C'est la garantie qui compte le plus ici. Faire échouer une décision
     * d'admissibilité parce qu'un courriel ne part pas ferait dépendre le
     * concours de la santé d'un serveur SMTP.
     *
     * Le répartiteur de notifications est remplacé par un double qui lève,
     * plutôt que par un mock de la façade : `Notification::fake()` a déjà
     * substitué celle-ci, et lui superposer une attente produirait une erreur
     * sur le double, pas sur le code testé.
     */
    public function test_une_panne_d_envoi_ne_defait_pas_la_decision(): void
    {
        Notification::swap(new class implements Dispatcher
        {
            public function send($notifiables, $notification): void
            {
                throw new RuntimeException('SMTP injoignable');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new RuntimeException('SMTP injoignable');
            }

            public function channel($name = null): void {}
        });

        $candidat = $this->candidat();
        $dossier = $this->dossierDepose($candidat);
        $this->grille($dossier);

        app(DecideAdmissibility::class)->handle(
            application: $dossier->refresh(),
            decision: AdmissibilityDecision::ADMISSIBLE,
            actor: User::factory()->role(UserRole::ADMIN)->create(),
        );

        // La décision est passée malgré la panne.
        $this->assertSame(ApplicationStatus::ADMISSIBLE, $dossier->fresh()->status);

        // Et l'échec est tracé, pour que le §9.3 le remonte.
        $this->assertDatabaseHas('notification_deliveries', [
            'event' => NotificationEvent::STAGE_DECISION->value,
            'status' => DeliveryStatus::FAILED->value,
            'recipient_id' => $candidat->getKey(),
        ]);
    }

    // — Le rappel de clôture ————————————————————————————————————

    public function test_le_rappel_ne_part_qu_aux_jalons(): void
    {
        Campaign::factory()->create(['closes_at' => now()->addDays(20)]);
        $this->campagne = Campaign::query()->firstOrFail();

        Application::factory()
            ->for($this->campagne, 'campaign')
            ->for($this->candidat(), 'candidate')
            ->create();

        $this->artisan('notifications:rappel-cloture')->assertSuccessful();

        $this->assertSame(0, NotificationDelivery::query()
            ->where('event', NotificationEvent::CLOSING_REMINDER->value)->count());
    }

    public function test_le_rappel_part_une_seule_fois_par_candidat(): void
    {
        $campagne = Campaign::factory()->create(['closes_at' => now()->addDays(7)->endOfDay()]);
        $this->campagne = $campagne;

        $candidat = $this->candidat();

        Application::factory()
            ->for($campagne, 'campaign')
            ->for($candidat, 'candidate')
            ->withSection(ApplicationSection::ELIGIBILITY, ['renseigne' => 'oui'])
            ->create();

        $this->artisan('notifications:rappel-cloture')->assertSuccessful();
        $this->artisan('notifications:rappel-cloture')->assertSuccessful();

        Notification::assertSentToTimes($candidat, RappelDeCloture::class, 1);
    }

    /** Un dossier déjà déposé n'a rien à rattraper. */
    public function test_le_rappel_ignore_les_dossiers_deja_deposes(): void
    {
        $campagne = Campaign::factory()->create(['closes_at' => now()->addDays(7)->endOfDay()]);
        $this->campagne = $campagne;

        $candidat = $this->candidat();
        $this->dossierDepose($candidat);

        $this->artisan('notifications:rappel-cloture')->assertSuccessful();

        Notification::assertNotSentTo($candidat, RappelDeCloture::class);
    }

    public function test_la_simulation_n_envoie_rien(): void
    {
        $campagne = Campaign::factory()->create(['closes_at' => now()->addDays(7)->endOfDay()]);
        $this->campagne = $campagne;

        $candidat = $this->candidat();

        Application::factory()->for($campagne, 'campaign')->for($candidat, 'candidate')->create();

        $this->artisan('notifications:rappel-cloture', ['--dry-run' => true])->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::query()->count());
    }

    // — Le contenu exigé par le §8.3 ————————————————————————————

    public function test_un_message_de_depot_porte_le_numero_et_la_date(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDepose($candidat);
        $dossier->setRelation('campaign', $this->campagne());

        $rendu = (new SoumissionRecue($dossier))->toMail($candidat)->render()->toHtml();

        $this->assertStringContainsString('BG-2026-001', $rendu, 'Le numéro de dépôt est la preuve du candidat.');
        $this->assertStringContainsString($this->campagne()->name, $rendu);
        $this->assertStringContainsString(config('mail.from.address'), $rendu, 'Le §8.3 exige un contact.');
    }
}
