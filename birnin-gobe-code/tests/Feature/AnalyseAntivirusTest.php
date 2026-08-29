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
use Tests\TestCase;

/**
 * L'analyse antivirus des pièces — §15.1.
 *
 * Ce que cette suite protège :
 *
 * 1. **Seule une pièce analysée et saine se télécharge.** C'est la garantie
 *    centrale, et elle vaut pour les trois espaces qui servent des fichiers :
 *    le candidat, le vérificateur, l'évaluateur. Un test par espace, parce que
 *    c'est l'oubli d'un seul qui rouvrirait la porte.
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

    // — Seule une pièce saine se télécharge ————————————————————————

    #[DataProvider('etatsBloquants')]
    public function test_le_candidat_ne_telecharge_pas_une_piece_sans_verdict(string $etat): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossier($candidat);
        $piece = $this->piece($dossier, AttachmentScanStatus::from($etat));

        $this->actingAs($candidat)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertStatus(423);
    }

    /** @return array<string, array{string}> */
    public static function etatsBloquants(): array
    {
        $cas = [];

        foreach (AttachmentScanStatus::bloquants() as $etat) {
            $cas[mb_strtolower($etat->label())] = [$etat->value];
        }

        return $cas;
    }

    public function test_le_candidat_telecharge_une_piece_analysee_et_saine(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossier($candidat);
        $piece = $this->piece($dossier, AttachmentScanStatus::CLEAN);

        $this->actingAs($candidat)
            ->get($this->urlCandidat($dossier, $piece))
            ->assertOk();
    }

    /**
     * Les quatre états bloquants le sont pour tout le monde.
     *
     * Le test porte sur l'énumération plutôt que sur une liste recopiée : un
     * état ajouté demain sans décision explicite sur le téléchargement fera
     * échouer ce test, ce qui est exactement le rappel qu'on veut.
     */
    public function test_un_seul_etat_ouvre_le_telechargement(): void
    {
        $ouverts = array_filter(
            AttachmentScanStatus::cases(),
            static fn (AttachmentScanStatus $etat): bool => $etat->autoriseLeTelechargement(),
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
        $this->assertFalse($piece->scan_status->autoriseLeTelechargement(), 'Et elle n’ouvre rien non plus.');
        $this->assertTrue($piece->scan_status->seRejoue(), 'Une panne se répare : la pièce doit être reprise.');
    }

    /** Une pièce remplacée entre le dépôt et l'analyse : le job s'arrête sans bruit. */
    public function test_une_piece_disparue_n_est_pas_une_erreur(): void
    {
        $this->analyseurRend(ScanVerdict::clean());

        (new ScanAttachment(999_999))->handle(app(VirusScanner::class));

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
