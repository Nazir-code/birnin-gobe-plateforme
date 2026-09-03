<?php

namespace Tests\Feature;

use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Application\DocumentType;
use App\Domain\Application\Scanning\ScanVerdict;
use App\Domain\Application\Scanning\UnavailableScanner;
use App\Domain\Application\Scanning\VirusScanner;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Auth\UserRole;
use App\Jobs\ScanAttachment;
use App\Models\Application;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * L'analyse antivirus des pièces — §15.1.
 *
 * Ce que cette suite protège :
 *
 * 1. **Une pièce ne va à un tiers que si elle est analysée et saine.** C'est la
 *    garantie centrale, et elle vaut pour les espaces qui reçoivent le fichier
 *    de quelqu'un d'autre : le vérificateur du §10, l'évaluateur du §11.2.
 *
 * 1 bis. **Mais elle revient à son déposant.** Le candidat qui retélécharge son
 *    propre fichier ne reçoit rien qu'il n'ait déjà envoyé ; lui fermer la porte
 *    n'aurait rien protégé et lui aurait interdit de relire son dossier. Seule
 *    la quarantaine ferme aussi ce chemin, parce qu'on ne sert pas un binaire
 *    dont on sait qu'il porte une menace.
 *
 * 2. **Une panne d'analyseur n'accuse personne, et n'ouvre rien.** Les deux
 *    erreurs symétriques sont interdites : traiter l'indisponibilité comme une
 *    menace écarterait un candidat innocent, la traiter comme un feu vert
 *    livrerait un fichier que personne n'a lu.
 *
 * 3. **Le dépôt n'attend pas l'analyse.** La pièce est acceptée, marquée
 *    `PENDING`, et le verdict tombe ensuite. Un `clamd` lent ne doit pas faire
 *    échouer un dépôt sur un réseau mobile.
 *
 * 4. **Sans analyseur configuré, rien ne s'ouvre.** L'interrupteur du §15.1 ne
 *    relâche pas la protection quand il est sur « off ».
 *
 * L'analyseur est toujours remplacé par un double : fabriquer un vrai virus de
 * test ferait dépendre la CI d'une base de signatures à jour, alors que les
 * règles vérifiées ici sont applicatives.
 */
final class AnalyseAntivirusTest extends TestCase
{
    use RefreshDatabase;

    private ?Campaign $campagne = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StoreApplicationDocument::diskName());
    }

    private function candidat(): User
    {
        return User::factory()->role(UserRole::CANDIDATE)->create();
    }

    /**
     * L'édition du test, créée une seule fois.
     *
     * `campaigns_une_seule_ouverte` est un index unique partiel : une seule
     * campagne peut être ouverte à la fois. Un helper qui en créerait une par
     * dossier ferait échouer tout test qui en manipule plusieurs — et l'échec
     * porterait sur la contrainte, pas sur ce que le test protège.
     */
    private function campagne(): Campaign
    {
        return $this->campagne ??= Campaign::factory()->create();
    }

    private function dossier(?User $candidat = null): Application
    {
        return Application::factory()
            ->for($this->campagne(), 'campaign')
            ->for($candidat ?? $this->candidat(), 'candidate')
            ->create();
    }

    /** Une pièce déposée par le vrai cas d'usage, dans l'état voulu. */
    private function piece(Application $dossier, AttachmentScanStatus $etat): Attachment
    {
        Queue::fake();

        $piece = app(StoreApplicationDocument::class)->handle(
            $dossier,
            DocumentType::cases()[0],
            UploadedFile::fake()->create('presentation.pdf', 120, 'application/pdf'),
            $dossier->candidate_id,
        );

        $piece->forceFill(['scan_status' => $etat->value])->save();

        return $piece->refresh();
    }

    private function urlCandidat(Application $dossier, Attachment $piece): string
    {
        return route('candidate.application.attachments.documents.download', [$dossier, $piece->type->value]);
    }

    // — Le dépôt n'attend pas l'analyse ——————————————————————————

    public function test_une_piece_deposee_part_en_attente_d_analyse(): void
    {
        Queue::fake();

        $dossier = $this->dossier();

        $piece = app(StoreApplicationDocument::class)->handle(
            $dossier,
            DocumentType::cases()[0],
            UploadedFile::fake()->create('presentation.pdf', 120, 'application/pdf'),
            $dossier->candidate_id,
        );

        $this->assertSame(AttachmentScanStatus::PENDING, $piece->scan_status);
        $this->assertNull($piece->scanned_at);

        Queue::assertPushed(ScanAttachment::class, fn (ScanAttachment $job): bool => $job->attachmentId === $piece->getKey());
    }

    /**
     * Une pièce insérée sans passer par le cas d'usage attend un verdict.
     *
     * Le défaut de la colonne était `QUARANTINE` : prudent en apparence, faux
     * en réalité — il faisait naître la ligne en affirmant qu'une analyse avait
     * eu lieu et trouvé une menace. Le piège était dormant, puisque le cas
     * d'usage écrit toujours l'état explicitement ; il n'attendait qu'une
     * reprise de données ou une correction en base.
     *
     * Ce test insère donc **volontairement** sans le cas d'usage : c'est le
     * seul chemin par lequel le défaut se voit.
     */
    public function test_une_piece_inseree_sans_etat_attend_un_verdict(): void
    {
        $dossier = $this->dossier();

        $piece = Attachment::query()->create([
            'application_id' => $dossier->getKey(),
            'type' => DocumentType::cases()[0]->value,
            'storage_key' => 'applications/reprise.pdf',
            'original_filename' => 'reprise.pdf',
            'mime_type' => 'application/pdf',
            'size' => 1024,
            'checksum' => str_repeat('0', 64),
        ]);

        $etat = $piece->refresh()->scan_status;

        $this->assertSame(AttachmentScanStatus::PENDING, $etat, 'Le défaut ne doit accuser personne.');
        $this->assertFalse($etat->autoriseLaRedistribution(), 'Et il ne doit rien ouvrir à un tiers.');
        $this->assertTrue($etat->seRejoue(), 'Le rattrapage doit pouvoir la reprendre.');
    }

    // — Seule une pièce saine se télécharge ————————————————————————

    /**
     * Le déposant relit son dossier, quel que soit l'avancement de l'analyse.
     *
     * Une première version de cet incrément fermait aussi ce chemin, et trois
     * tests du parcours candidat l'ont signalé. Ils avaient raison : sans
     * analyseur configuré, plus aucun candidat n'aurait pu relire ce qu'il
     * venait de déposer — un contrôle qui coûte cher sans rien protéger, parce
     * que le fichier vient de sa propre machine.
     */
    #[DataProvider('etatsRendusAuDeposant')]
    public function test_le_deposant_relit_toujours_sa_piece(string $etat): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossier($candidat);
        $piece = $this->piece($dossier, AttachmentScanStatus::from($etat));

        $this->actingAs($candidat)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function etatsRendusAuDeposant(): array
    {
        $cas = [];

        foreach (AttachmentScanStatus::cases() as $etat) {
            if ($etat->autoriseLeRetourAuDeposant()) {
                $cas[mb_strtolower($etat->label())] = [$etat->value];
            }
        }

        return $cas;
    }

    /** La quarantaine, elle, ferme aussi la porte du déposant. */
    public function test_le_deposant_ne_recupere_pas_une_piece_en_quarantaine(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossier($candidat);
        $piece = $this->piece($dossier, AttachmentScanStatus::QUARANTINE);

        $this->actingAs($candidat)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertStatus(423);
    }

    /**
     * Un seul état permet de servir la pièce à un tiers.
     *
     * Le test porte sur l'énumération plutôt que sur une liste recopiée : un
     * état ajouté demain sans décision explicite sur la redistribution fera
     * échouer ce test, ce qui est exactement le rappel qu'on veut.
     */
    public function test_un_seul_etat_ouvre_la_redistribution(): void
    {
        $ouverts = array_filter(
            AttachmentScanStatus::cases(),
            static fn (AttachmentScanStatus $etat): bool => $etat->autoriseLaRedistribution(),
        );

        $this->assertSame([AttachmentScanStatus::CLEAN], array_values($ouverts));
    }

    public function test_le_verificateur_ne_telecharge_pas_une_piece_en_quarantaine(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::QUARANTINE);

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertStatus(423);
    }

    // — La dérogation du §15.1 ————————————————————————————————————

    /**
     * Fermée par défaut : rien ne s'ouvre sans décision explicite.
     *
     * C'est la propriété qui rend la dérogation acceptable. Un réglage dont la
     * valeur par défaut relâche une protection finit toujours par être trouvé
     * relâché.
     */
    public function test_la_derogation_est_fermee_par_defaut(): void
    {
        $this->assertFalse(config('scanning.allow_unscanned_internal'));
        $this->assertFalse(AttachmentScanStatus::derogationActive());
        $this->assertFalse(AttachmentScanStatus::UNAVAILABLE->autoriseLaRedistribution());
    }

    #[DataProvider('etatsSansVerdict')]
    public function test_la_derogation_ouvre_les_pieces_sans_verdict_aux_tiers(string $etat): void
    {
        config()->set('scanning.allow_unscanned_internal', true);

        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::from($etat));

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function etatsSansVerdict(): array
    {
        $cas = [];

        foreach (AttachmentScanStatus::cases() as $etat) {
            if ($etat->seRejoue()) {
                $cas[mb_strtolower($etat->label())] = [$etat->value];
            }
        }

        return $cas;
    }

    /**
     * La quarantaine ne s'ouvre sous aucune dérogation.
     *
     * C'est la limite qui ne bouge pas : une menace détectée ne se sert à
     * personne, quelle que soit la configuration. Sans cette garde, la
     * dérogation ne serait plus une tolérance sur l'incertitude mais une
     * autorisation de distribuer un fichier vérolé.
     */
    public function test_la_derogation_n_ouvre_jamais_la_quarantaine(): void
    {
        config()->set('scanning.allow_unscanned_internal', true);

        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::QUARANTINE);

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertStatus(423);

        // Ni pour son déposant.
        $this->actingAs($dossier->candidate)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertStatus(423);
    }

    /**
     * Chaque ouverture dérogatoire laisse une trace nominative.
     *
     * C'est ce qui distingue un écart assumé d'un trou de sécurité : on peut
     * dire qui a ouvert quelle pièce, sur quel dossier, et dans quel état elle
     * était. Le §15.1 réclame par ailleurs cette traçabilité pour tout accès aux
     * pièces — elle manquait.
     */
    public function test_une_ouverture_derogatoire_est_journalisee(): void
    {
        config()->set('scanning.allow_unscanned_internal', true);

        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::UNAVAILABLE);

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'APPLICATION_DOCUMENT_SERVED_UNSCANNED',
            'actor_id' => $admin->getKey(),
            'target_id' => (string) $dossier->getKey(),
        ]);
    }

    /** Une pièce saine ne produit aucune trace dérogatoire : ce n'est pas un écart. */
    public function test_une_piece_saine_n_est_pas_journalisee_comme_derogation(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::CLEAN);

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertOk();

        $this->assertDatabaseMissing('audit_events', [
            'action' => 'APPLICATION_DOCUMENT_SERVED_UNSCANNED',
        ]);
    }

    // — Le job et les verdicts ————————————————————————————————————

    public function test_un_fichier_sain_devient_telechargeable(): void
    {
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::PENDING);

        $this->analyseurRend(ScanVerdict::clean());
        (new ScanAttachment($piece->getKey()))->handle(app(VirusScanner::class));

        $piece->refresh();

        $this->assertSame(AttachmentScanStatus::CLEAN, $piece->scan_status);
        $this->assertNotNull($piece->scanned_at);
        $this->assertNull($piece->scan_signature);
    }

    public function test_un_fichier_infecte_est_mis_en_quarantaine_avec_sa_signature(): void
    {
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::PENDING);

        $this->analyseurRend(ScanVerdict::infected('Eicar-Test-Signature'));
        (new ScanAttachment($piece->getKey()))->handle(app(VirusScanner::class));

        $piece->refresh();

        $this->assertSame(AttachmentScanStatus::QUARANTINE, $piece->scan_status);
        $this->assertSame('Eicar-Test-Signature', $piece->scan_signature);
    }

    /**
     * Une panne d'analyseur n'accuse personne.
     *
     * C'est la moitié la plus importante de la règle : un candidat écarté pour
     * un fichier prétendument vérolé n'a aucun moyen de se défendre, et une
     * panne de conteneur ne doit jamais produire ce verdict.
     */
    public function test_une_panne_d_analyseur_ne_met_rien_en_quarantaine(): void
    {
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::PENDING);

        $this->analyseurRend(ScanVerdict::unavailable('clamd injoignable'));
        (new ScanAttachment($piece->getKey()))->handle(app(VirusScanner::class));

        $piece->refresh();

        $this->assertSame(AttachmentScanStatus::UNAVAILABLE, $piece->scan_status);
        $this->assertNull($piece->scan_signature, 'Une panne ne nomme aucune menace.');
        $this->assertFalse($piece->scan_status->autoriseLaRedistribution(), 'Et elle ne s’ouvre à aucun tiers.');
        $this->assertTrue($piece->scan_status->seRejoue(), 'Une panne se répare : la pièce doit être reprise.');
    }

    /** Une pièce remplacée entre le dépôt et l'analyse : le job s'arrête sans bruit. */
    public function test_une_piece_disparue_n_est_pas_une_erreur(): void
    {
        $this->analyseurRend(ScanVerdict::clean());

        (new ScanAttachment(999_999))->handle(app(VirusScanner::class));

        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * Le job est abandonné après son dernier essai — ADR-019.
     *
     * `handle()` traite les cas prévus ; `failed()` traite l'imprévu — base
     * injoignable, disque objet en panne, mémoire épuisée. Sans lui la pièce
     * resterait `PENDING`, c'est-à-dire « analyse en cours », pour toujours :
     * un état qui promet un verdict imminent qui ne viendra jamais, et une
     * ligne dans `failed_jobs` que personne ne rapproche du fichier.
     */
    public function test_un_job_abandonne_ferme_la_piece_au_lieu_de_la_laisser_en_attente(): void
    {
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::PENDING);

        (new ScanAttachment($piece->getKey()))->failed(new RuntimeException('Base injoignable'));

        $piece->refresh();

        $this->assertSame(AttachmentScanStatus::UNAVAILABLE, $piece->scan_status);
        $this->assertNull($piece->scan_signature, 'Un abandon ne nomme aucune menace.');
        $this->assertFalse(
            $piece->scan_status->autoriseLaRedistribution(),
            'Ce qui n’a pas été vérifié ne s’ouvre pas : c’est la règle de tout le §15.1.',
        );
        $this->assertTrue($piece->scan_status->seRejoue(), 'Le rattrapage doit pouvoir la reprendre.');
        $this->assertNotNull($piece->scanned_at, 'L’abandon est un fait daté.');
    }

    /**
     * Un signal d'abandon en retard n'efface pas un verdict déjà rendu.
     *
     * Même garde qu'ADR-019 sur les traces d'envoi : le premier à conclure fixe
     * l'issue. Sans elle, une pièce déclarée saine pourrait retomber en
     * « analyse indisponible » et se refermer au téléchargement sans raison.
     */
    public function test_un_abandon_tardif_n_efface_pas_un_verdict_rendu(): void
    {
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::CLEAN);

        (new ScanAttachment($piece->getKey()))->failed(new RuntimeException('Signal en retard'));

        $this->assertSame(AttachmentScanStatus::CLEAN, $piece->refresh()->scan_status);
    }

    /** Une pièce remplacée avant l'abandon : gérer un échec ne doit pas en produire un autre. */
    public function test_un_job_abandonne_sur_une_piece_disparue_ne_leve_rien(): void
    {
        (new ScanAttachment(999_999))->failed(new RuntimeException('Base injoignable'));

        $this->assertSame(0, Attachment::query()->count());
    }

    // — Sans analyseur configuré ————————————————————————————————

    public function test_sans_analyseur_configure_rien_ne_s_ouvre(): void
    {
        config()->set('scanning.enabled', false);

        $analyseur = app(VirusScanner::class);

        $this->assertInstanceOf(UnavailableScanner::class, $analyseur);

        $verdict = $analyseur->scan('n’importe quoi');

        $this->assertSame(AttachmentScanStatus::UNAVAILABLE, $verdict->status);
        $this->assertFalse($verdict->estSain(), 'L’absence d’analyseur ne vaut jamais « sain ».');
        $this->assertFalse($verdict->estInfecte(), 'Ni « infecté ».');
    }

    /** La commande de rattrapage reprend ce qui n'a pas de verdict, et rien d'autre. */
    public function test_le_rattrapage_ne_reprend_que_les_pieces_sans_verdict(): void
    {
        Queue::fake();

        $dossier = $this->dossier();

        foreach ([AttachmentScanStatus::CLEAN, AttachmentScanStatus::QUARANTINE] as $arrete) {
            $this->piece($this->dossier(), $arrete);
        }

        $reprises = [
            $this->piece($dossier, AttachmentScanStatus::NOT_SCANNED),
            $this->piece($this->dossier(), AttachmentScanStatus::UNAVAILABLE),
        ];

        Queue::fake();

        $this->artisan('attachments:scan')->assertSuccessful();

        Queue::assertPushed(ScanAttachment::class, count($reprises));
    }

    // — Le refus dit pourquoi ————————————————————————————————————

    /**
     * Un 423 affiche le motif, pas une page de panne.
     *
     * Le code refusait déjà correctement, et joignait l'explication — mais
     * aucun gabarit ne répondait au 423, si bien que Laravel se rabattait sur
     * la page générique de Symfony : « Something is broken ». Le blocage,
     * volontaire et le plus souvent temporaire, se lisait comme un serveur
     * cassé ; on réessayait en boucle et on ouvrait un incident.
     *
     * Ce test tient les deux bouts : le motif est présent, et la formule de
     * panne a disparu.
     */
    public function test_le_refus_au_deposant_affiche_le_motif(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossier($candidat);
        $piece = $this->piece($dossier, AttachmentScanStatus::QUARANTINE);

        $this->actingAs($candidat)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertStatus(423)
            ->assertSee(AttachmentScanStatus::QUARANTINE->explication(), false)
            ->assertDontSee('Something is broken');
    }

    /** Le vérificateur du §10 a droit à la même explication. */
    public function test_le_refus_a_un_tiers_affiche_le_motif(): void
    {
        $admin = User::factory()->role(UserRole::ADMIN)->create();
        $dossier = $this->dossier();
        $piece = $this->piece($dossier, AttachmentScanStatus::UNAVAILABLE);

        $this->actingAs($admin)
            ->get(route('admin.applications.documents.download', [$dossier, $piece->type->value]))
            ->assertStatus(423)
            ->assertSee(AttachmentScanStatus::UNAVAILABLE->explication(), false)
            ->assertDontSee('Something is broken');
    }

    // — L'état ne conseille pas un geste inutile ——————————————————

    /**
     * Sans analyseur, `attachments:status` ne renvoie plus vers le rattrapage.
     *
     * C'est le piège que cette commande devait fermer et dans lequel elle
     * tombait : elle conseillait `attachments:scan` pour les pièces jamais
     * analysées, quatre lignes au-dessus de son propre « Analyseur configuré :
     * non ». Le rattrapage les aurait toutes marquées « indisponible » —
     * effaçant la seule chose qui les distingue.
     */
    public function test_sans_analyseur_l_etat_ne_conseille_pas_le_rattrapage(): void
    {
        config()->set('scanning.enabled', false);

        $this->piece($this->dossier(), AttachmentScanStatus::NOT_SCANNED);

        $this->artisan('attachments:status')
            ->doesntExpectOutputToContain('attachments:scan')
            ->assertSuccessful();
    }

    /** Avec un analyseur joignable, le rattrapage redevient le bon geste. */
    public function test_avec_analyseur_l_etat_conseille_le_rattrapage(): void
    {
        config()->set('scanning.enabled', true);

        $this->piece($this->dossier(), AttachmentScanStatus::NOT_SCANNED);

        $this->artisan('attachments:status')
            ->expectsOutputToContain('attachments:scan')
            ->assertSuccessful();
    }

    private function analyseurRend(ScanVerdict $verdict): void
    {
        $this->app->bind(VirusScanner::class, fn (): VirusScanner => new class($verdict) implements VirusScanner
        {
            public function __construct(private readonly ScanVerdict $verdict) {}

            public function scan(string $contenu): ScanVerdict
            {
                return $this->verdict;
            }
        });
    }
}
