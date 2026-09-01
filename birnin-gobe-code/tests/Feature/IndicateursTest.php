<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\Gender;
use App\Domain\Reference\NigerRegion;
use App\Domain\Reporting\IndicatorBreakdown;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les tableaux de bord d'indicateurs — §13.1 et §13.4.
 *
 * Ce que cette suite protège :
 *
 * 1. **L'espace reste étanche.**
 *
 * 2. **« Non mesuré » ne se confond pas avec « zéro ».** C'est le défaut
 *    classique d'un tableau de bord : afficher 0 pour une famille sans source
 *    fait conclure à l'absence du phénomène, alors qu'il n'y a qu'absence de
 *    mesure.
 *
 * 3. **Les petits effectifs sont masqués sur les ventilations sensibles.** Le
 *    §13.4 le demande pour le genre, l'âge, le handicap et la localisation. Le
 *    test vérifie que la valeur **ne sort pas du serveur**, à l'écran comme à
 *    l'export : c'est l'export qui est le vrai chemin de fuite.
 *
 * 4. **Chaque indicateur porte sa fiche.** Définition, formule, source,
 *    fréquence, accès — les cinq champs du §13.4, sur chaque ligne.
 *
 * 5. **Un zéro n'est pas masqué.** Masquer les zéros ferait disparaître les
 *    modalités vides, qui sont précisément l'information de pilotage.
 */
final class IndicateursTest extends TestCase
{
    use RefreshDatabase;

    private int $numero = 0;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    private function dossier(Campaign $campagne, ApplicationStatus $statut, array $sections = []): Application
    {
        $fabrique = Application::factory()->for($campagne, 'campaign')->status($statut);

        foreach ($sections as $section => $reponses) {
            $fabrique = $fabrique->withSection(ApplicationSection::from($section), $reponses);
        }

        return $fabrique->create([
            'submission_number' => $statut === ApplicationStatus::DRAFT ? null : sprintf('BG-%06d', ++$this->numero),
            'submitted_at' => $statut === ApplicationStatus::DRAFT ? null : now()->subDays(2),
        ]);
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/indicators')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/indicators')->assertForbidden();
        $this->actingAs($utilisateur)->get('/admin/indicators/export')->assertForbidden();
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

    // — « Non mesuré » n'est pas « zéro » ——————————————————————————

    public function test_les_familles_sans_source_sont_annoncees_comme_telles(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $familles = collect($page->toArray()['props']['families'])->keyBy('value');

                // Les six familles du §13.1 sont là, y compris celles qu'on ne
                // sait pas encore renseigner.
                $this->assertCount(6, $familles);

                foreach (['MOBILISATION', 'FINALE', 'QUALITE_DE_SERVICE'] as $sansSource) {
                    $this->assertFalse($familles[$sansSource]['available']);
                    $this->assertNotNull($familles[$sansSource]['reason']);
                }

                foreach (['CANDIDATURES', 'ADMISSIBILITE', 'EVALUATION'] as $mesuree) {
                    $this->assertTrue($familles[$mesuree]['available']);
                }
            });
    }

    /** Un indicateur sans donnée rend `null`, jamais 0 — et l'écran le distingue. */
    public function test_une_completude_sans_brouillon_est_non_mesuree_et_non_nulle(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();
        $this->dossier($campagne, ApplicationStatus::SUBMITTED);

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $completude = collect($page->toArray()['props']['indicators'])
                    ->firstWhere('key', 'candidatures.completude_moyenne');

                $this->assertNull($completude['value']);
                $this->assertFalse($completude['measured']);
            });
    }

    // — Chaque indicateur porte sa fiche (§13.4) ———————————————————

    public function test_chaque_indicateur_porte_ses_cinq_champs_de_gouvernance(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $indicateurs = $page->toArray()['props']['indicators'];

                $this->assertNotEmpty($indicateurs);

                foreach ($indicateurs as $indicateur) {
                    foreach (['definition', 'formula', 'source', 'refreshLabel', 'accessLabel'] as $champ) {
                        $this->assertNotSame('', trim((string) $indicateur[$champ]), "{$indicateur['key']} sans {$champ}");
                    }
                }
            });
    }

    // — Les comptes ————————————————————————————————————————————————

    public function test_les_dossiers_sont_comptes_par_etape(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier($campagne, ApplicationStatus::DRAFT);
        $this->dossier($campagne, ApplicationStatus::SUBMITTED);
        $this->dossier($campagne, ApplicationStatus::PENDING_REVIEW);
        $this->dossier($campagne, ApplicationStatus::ADMISSIBLE);
        $this->dossier($campagne, ApplicationStatus::INADMISSIBLE);

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $valeurs = collect($page->toArray()['props']['indicators'])->keyBy('key');

                $this->assertSame(1, $valeurs['candidatures.brouillons']['value']);
                $this->assertSame(4, $valeurs['candidatures.deposees']['value']);
                $this->assertSame(1, $valeurs['admissibilite.a_controler']['value']);
                $this->assertSame(1, $valeurs['admissibilite.en_controle']['value']);
                $this->assertSame(1, $valeurs['admissibilite.recevables']['value']);
                $this->assertSame(1, $valeurs['admissibilite.irrecevables']['value']);
            });
    }

    // — Les petits effectifs (§13.4) ———————————————————————————————

    /**
     * Le test central de ce fichier.
     *
     * Trois dossiers déclarent le même sexe : sous le seuil, l'effectif doit
     * être masqué, et la valeur ne doit pas quitter le serveur.
     */
    public function test_un_petit_effectif_est_masque_sur_une_ventilation_sensible(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->dossier($campagne, ApplicationStatus::SUBMITTED, [
                ApplicationSection::PROFILE->value => [ProfileSection::GENDER => Gender::FEMALE->value],
            ]);
        }

        $this->assertLessThan(IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS, 3);

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $sexe = collect($page->toArray()['props']['breakdowns'])->firstWhere('key', 'candidatures.par_sexe');
                $femmes = collect($sexe['rows'])->firstWhere('label', Gender::FEMALE->label());

                $this->assertTrue($femmes['masked']);
                $this->assertNull($femmes['value']);
            });
    }

    /** Au-dessus du seuil, l'effectif s'affiche : le masquage protège, il n'aveugle pas. */
    public function test_un_effectif_suffisant_n_est_pas_masque(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        for ($i = 0; $i < IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS; $i++) {
            $this->dossier($campagne, ApplicationStatus::SUBMITTED, [
                ApplicationSection::PROFILE->value => [ProfileSection::GENDER => Gender::FEMALE->value],
            ]);
        }

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $sexe = collect($page->toArray()['props']['breakdowns'])->firstWhere('key', 'candidatures.par_sexe');
                $femmes = collect($sexe['rows'])->firstWhere('label', Gender::FEMALE->label());

                $this->assertFalse($femmes['masked']);
                $this->assertSame(IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS, $femmes['value']);
            });
    }

    /**
     * Un zéro n'identifie personne.
     *
     * Le masquer ferait disparaître les modalités vides — une région sans
     * aucune candidature est précisément ce qu'un pilotage doit voir.
     */
    public function test_un_effectif_nul_n_est_pas_masque(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $regions = collect($page->toArray()['props']['breakdowns'])->firstWhere('key', 'candidatures.par_region');

                foreach ($regions['rows'] as $ligne) {
                    $this->assertFalse($ligne['masked'], "{$ligne['label']} masquée alors qu'elle vaut zéro");
                    $this->assertSame(0, $ligne['value']);
                }
            });
    }

    /** Les modalités non choisies restent listées, à zéro. */
    public function test_une_ventilation_liste_toutes_les_modalites_du_referentiel(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $this->dossier($campagne, ApplicationStatus::SUBMITTED, [
            ApplicationSection::ELIGIBILITY->value => [EligibilitySection::INTERVENTION_REGION => NigerRegion::cases()[0]->value],
        ]);

        $this->actingAs($admin)
            ->get('/admin/indicators')
            ->assertInertia(function (AssertableInertia $page): void {
                $regions = collect($page->toArray()['props']['breakdowns'])->firstWhere('key', 'candidatures.par_region');

                // Toutes les régions du référentiel, plus « Non renseigné ».
                $this->assertCount(count(NigerRegion::cases()) + 1, $regions['rows']);
            });
    }

    // — L'export (§13.2) ——————————————————————————————————————————

    public function test_l_export_rend_un_csv_nomme(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();
        $this->dossier($campagne, ApplicationStatus::SUBMITTED);

        $reponse = $this->actingAs($admin)->get('/admin/indicators/export');

        $reponse->assertOk();
        $reponse->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('indicateurs-', $reponse->headers->get('content-disposition') ?? '');
    }

    /**
     * L'export est le vrai chemin de fuite d'une donnée ré-identifiante.
     *
     * Un effectif sous le seuil doit y sortir comme « masqué », jamais comme un
     * nombre.
     */
    public function test_l_export_ne_laisse_pas_fuir_un_petit_effectif(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        for ($i = 0; $i < 2; $i++) {
            $this->dossier($campagne, ApplicationStatus::SUBMITTED, [
                ApplicationSection::PROFILE->value => [ProfileSection::GENDER => Gender::MALE->value],
            ]);
        }

        $contenu = $this->actingAs($admin)->get('/admin/indicators/export')->streamedContent();

        $lignes = array_values(array_filter(
            explode("\n", $contenu),
            static fn (string $ligne): bool => str_contains($ligne, Gender::MALE->label()),
        ));

        $this->assertNotEmpty($lignes);

        foreach ($lignes as $ligne) {
            $this->assertStringContainsString('masqué', $ligne);
        }
    }

    public function test_l_export_porte_la_definition_de_chaque_indicateur(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $contenu = $this->actingAs($admin)->get('/admin/indicators/export')->streamedContent();

        $this->assertStringContainsString('Définition', $contenu);
        $this->assertStringContainsString('Formule', $contenu);
        $this->assertStringContainsString('Dossiers ouverts par un candidat et jamais déposés.', $contenu);
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        Campaign::factory()->create();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/indicators')->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }
}
