<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ChallengeSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProjectTheme;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Thématique officielle du projet — étape « Défi ».
 *
 * Ce que cette suite protège :
 *
 * 1. **Les quatre thématiques, et elles seules.** La liste est fermée : une
 *    cinquième valeur, même vraisemblable, n'entre pas en base. Sans quoi les
 *    dossiers deviendraient impossibles à dénombrer par axe.
 *
 * 2. **Une seule source.** Le portail public et le formulaire du candidat
 *    lisent la même enum. Deux listes séparées finiraient par diverger, et
 *    personne ne le verrait avant qu'un dossier soit rangé sous une thématique
 *    qui n'existe plus.
 *
 * 3. **Aucune valeur inventée pour les brouillons antérieurs.** Ils se chargent
 *    et se modifient, mais leur section « Défi » reste incomplète tant que le
 *    candidat n'a pas choisi lui-même.
 */
final class ThematiqueProjetTest extends TestCase
{
    use RefreshDatabase;

    private function candidat(): User
    {
        return User::factory()->create();
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    private function campagneOuverte(): Campaign
    {
        return Campaign::factory()->create();
    }

    /** Ouvre un brouillon par la vraie route. */
    private function brouillon(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    /** @return array<string, mixed> Les cinq réponses de l'étape « Défi ». */
    private function defi(array $remplacements = []): array
    {
        return array_merge([
            ChallengeSection::THEME_FIELD => ProjectTheme::LAND_REGISTRY->value,
            'main_challenge' => 'Les dossiers fonciers sont dispersés entre plusieurs services.',
            'affected_people' => 'Les propriétaires et les agents du cadastre.',
            'root_causes' => 'Un archivage papier sans index commun.',
            'location' => 'NE-8',
        ], $remplacements);
    }

    private function enregistrer(User $candidat, Application $dossier, array $reponses): TestResponse
    {
        return $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/challenge", $reponses);
    }

    private function reponsesEnBase(Application $dossier): array
    {
        return $dossier->fresh()->sectionAnswers(ApplicationSection::CHALLENGE)?->answers ?? [];
    }

    // — Le référentiel ————————————————————————————————————————————

    /** Quatre thématiques, ni plus ni moins, dans l'ordre du concours. */
    public function test_le_referentiel_compte_exactement_les_quatre_thematiques_officielles(): void
    {
        $this->assertSame(
            ['gestion-urbaine', 'foncier', 'etat-civil', 'cartographie'],
            array_map(static fn (ProjectTheme $t): string => $t->value, ProjectTheme::cases()),
        );

        $this->assertSame([
            'Gestion urbaine et services de base',
            'Gestion foncière et cadastrale',
            'État civil et services administratifs',
            'Cartographie, géolocalisation, risques et résilience',
        ], array_map(static fn (ProjectTheme $t): string => $t->label(), ProjectTheme::cases()));
    }

    /**
     * Les codes persistés sont stables et ne sont pas les libellés.
     *
     * Un libellé reformulé — et ils le seront — ne doit réécrire aucune ligne
     * en base, ni invalider un dossier déjà soumis.
     */
    public function test_les_valeurs_persistees_sont_des_codes_et_non_des_libelles(): void
    {
        foreach (ProjectTheme::cases() as $theme) {
            $this->assertMatchesRegularExpression('/^[a-z-]+$/', $theme->value);
            $this->assertNotSame($theme->label(), $theme->value);
        }
    }

    // — Enregistrement ————————————————————————————————————————————

    #[DataProvider('lesQuatreThematiques')]
    public function test_les_quatre_thematiques_sont_acceptees(string $code): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $this->enregistrer($candidat, $dossier, $this->defi([ChallengeSection::THEME_FIELD => $code]))
            ->assertOk();

        $this->assertSame($code, $this->reponsesEnBase($dossier)[ChallengeSection::THEME_FIELD]);
    }

    /** @return array<string, array{string}> */
    public static function lesQuatreThematiques(): array
    {
        $cas = [];

        foreach (ProjectTheme::cases() as $theme) {
            $cas[$theme->value] = [$theme->value];
        }

        return $cas;
    }

    /**
     * Tout le reste est refusé, y compris ce qui ressemble à une thématique.
     *
     * Le libellé officiel lui-même est refusé : c'est le code qui est persisté,
     * et accepter les deux formes créerait deux représentations d'une même
     * thématique — donc deux comptes différents au moment de dénombrer.
     */
    #[DataProvider('valeursRefusees')]
    public function test_toute_autre_valeur_est_refusee(string $valeur): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $this->enregistrer($candidat, $dossier, $this->defi([ChallengeSection::THEME_FIELD => $valeur]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(ChallengeSection::THEME_FIELD);

        // Rien de la charge utile n'est enregistré : une saisie refusée n'écrit
        // pas non plus les champs valides qui l'accompagnaient.
        $this->assertSame([], $this->reponsesEnBase($dossier));
    }

    /** @return array<string, array{string}> */
    public static function valeursRefusees(): array
    {
        return [
            'thématique inventée' => ['agroalimentaire'],
            'libellé officiel au lieu du code' => ['Gestion foncière et cadastrale'],
            'code approchant' => ['gestion_urbaine'],
            'casse différente' => ['FONCIER'],
            'chaîne arbitraire' => ['<script>alert(1)</script>'],
        ];
    }

    // — Obligatoire ————————————————————————————————————————————————

    /**
     * Obligatoire pour achever la section, jamais pour enregistrer un brouillon.
     *
     * C'est la règle de toutes les sections : la sauvegarde continue accepte
     * l'incomplet, la complétude exige le complet. Sans quoi le candidat
     * perdrait ce qu'il vient d'écrire dès qu'il quitte l'écran.
     */
    public function test_la_thematique_est_obligatoire_pour_achever_la_section(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        // Tout sauf la thématique : le brouillon s'enregistre, la section n'est
        // pas achevée.
        $sansTheme = $this->defi();
        unset($sansTheme[ChallengeSection::THEME_FIELD]);

        $this->enregistrer($candidat, $dossier, $sansTheme)->assertOk();

        $ligne = $dossier->fresh()->sectionAnswers(ApplicationSection::CHALLENGE);
        $this->assertNull($ligne->completed_at);
        $this->assertFalse(ChallengeSection::isComplete($ligne->answers));

        // La thématique choisie, la section est achevée.
        $this->enregistrer($candidat, $dossier, $this->defi())->assertOk();

        $this->assertNotNull($dossier->fresh()->sectionAnswers(ApplicationSection::CHALLENGE)->completed_at);
    }

    public function test_la_thematique_seule_ne_suffit_pas_a_achever_la_section(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $this->enregistrer($candidat, $dossier, [
            ChallengeSection::THEME_FIELD => ProjectTheme::CIVIL_REGISTRY->value,
        ])->assertOk();

        $this->assertNull($dossier->fresh()->sectionAnswers(ApplicationSection::CHALLENGE)->completed_at);
    }

    // — Persistance et reprise ————————————————————————————————————

    /** La thématique revient à l'écran, telle que le serveur l'a enregistrée. */
    public function test_la_thematique_survit_au_rechargement_et_a_la_reconnexion(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $this->enregistrer($candidat, $dossier, $this->defi([
            ChallengeSection::THEME_FIELD => ProjectTheme::MAPPING_RESILIENCE->value,
        ]))->assertOk();

        $this->actingAs($candidat)
            ->get("/candidate/application/{$dossier->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('answers.'.ChallengeSection::THEME_FIELD, ProjectTheme::MAPPING_RESILIENCE->value)
                // Les quatre choix arrivent du serveur : l'écran n'en connaît
                // aucun en dur.
                ->has('themes', 4)
                ->where('themes.0.value', ProjectTheme::URBAN_MANAGEMENT->value)
                ->where('themes.0.label', ProjectTheme::URBAN_MANAGEMENT->label()));

        // Une nouvelle session ne change rien : la valeur vient de la base.
        auth()->logout();

        $this->actingAs($candidat->fresh())
            ->get("/candidate/application/{$dossier->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('answers.'.ChallengeSection::THEME_FIELD, ProjectTheme::MAPPING_RESILIENCE->value));
    }

    /** La sauvegarde continue rend l'horodatage et l'avancement, comme les autres champs. */
    public function test_le_choix_declenche_une_sauvegarde_complete(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $this->enregistrer($candidat, $dossier, $this->defi())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed']);
    }

    /** Le dossier d'un autre candidat reste inaccessible, thématique comprise. */
    public function test_un_candidat_ne_peut_pas_choisir_la_thematique_d_un_autre(): void
    {
        $proprietaire = $this->candidat();
        $dossier = $this->brouillon($proprietaire, $this->campagneOuverte());

        $this->enregistrer($this->candidat(), $dossier, $this->defi())->assertForbidden();

        $this->assertSame([], $this->reponsesEnBase($dossier));
    }

    // — Brouillons antérieurs ——————————————————————————————————————

    /**
     * Un brouillon d'avant cette question.
     *
     * Il porte les quatre réponses d'origine et un `completed_at` posé sous
     * l'ancienne règle. Deux exigences, et elles tiennent ensemble : l'écran
     * s'ouvre sans erreur, et la section n'est plus considérée comme faite tant
     * que le candidat n'a pas choisi. Aucune thématique n'est devinée à sa
     * place.
     */
    public function test_un_ancien_brouillon_se_charge_et_reclame_sa_thematique(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $ancien = $this->defi();
        unset($ancien[ChallengeSection::THEME_FIELD]);

        ApplicationSectionAnswers::query()->create([
            'application_id' => $dossier->getKey(),
            'section' => ApplicationSection::CHALLENGE->value,
            'answers' => $ancien,
            'completed_at' => now(),
        ]);

        // 1. L'écran s'ouvre, les anciennes réponses sont là, la thématique est
        //    vide — et non pré-remplie avec une valeur choisie par le logiciel.
        $this->actingAs($candidat)
            ->get("/candidate/application/{$dossier->getKey()}/challenge")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('answers.main_challenge', $ancien['main_challenge'])
                ->where('answers.'.ChallengeSection::THEME_FIELD, null));

        // 2. Le domaine ne considère plus la section comme faite.
        $this->assertFalse(ChallengeSection::isComplete($ancien));

        // 3. Le choix effectué, la section redevient complète — sans qu'aucune
        //    des réponses d'origine ait été touchée.
        $this->enregistrer($candidat, $dossier, [
            ...$ancien,
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
        ])->assertOk();

        $ligne = $dossier->fresh()->sectionAnswers(ApplicationSection::CHALLENGE);
        $this->assertNotNull($ligne->completed_at);
        $this->assertSame($ancien['main_challenge'], $ligne->answers['main_challenge']);
        $this->assertSame(ProjectTheme::URBAN_MANAGEMENT->value, $ligne->answers[ChallengeSection::THEME_FIELD]);
    }

    // — Portail public ————————————————————————————————————————————

    /**
     * La page d'accueil sert toujours exactement les quatre thématiques, dans
     * l'ordre et avec les textes officiels.
     *
     * Le contenu a changé de fichier — il vit dans l'enum — mais pas d'un
     * caractère. C'est ce que ce test verrouille.
     */
    public function test_la_page_d_accueil_sert_toujours_les_quatre_thematiques_officielles(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('themes', 4)
                ->where('themes.0.key', 'gestion-urbaine')
                ->where('themes.0.title', 'Gestion urbaine et services de base')
                ->where('themes.0.problems', 'Signalement et suivi des déchets, voirie, caniveaux, éclairage, équipements, interventions et relation citoyenne.')
                ->where('themes.0.results', 'Collecte terrain, priorisation, affectation, traçabilité et tableau de bord opérationnel.')
                ->where('themes.1.key', 'foncier')
                ->where('themes.1.title', 'Gestion foncière et cadastrale')
                ->where('themes.2.key', 'etat-civil')
                ->where('themes.2.title', 'État civil et services administratifs')
                ->where('themes.3.key', 'cartographie')
                ->where('themes.3.title', 'Cartographie, géolocalisation, risques et résilience')
                ->where('themes.3.problems', 'Adressage, inventaire des actifs, zones inondables, ouvrages, ressources mobiles, alertes et décisions d’urgence.')
                ->where('themes.3.results', 'Données géoréférencées fiables, usages hors ligne, cartes opérationnelles et aide à la décision.')
                ->where('stats.themes', 4));
    }

    /** Le portail et le formulaire lisent la même liste. */
    public function test_le_portail_et_le_formulaire_servent_la_meme_liste(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());

        $accueil = $this->get('/')->assertOk();
        $formulaire = $this->actingAs($candidat)
            ->get("/candidate/application/{$dossier->getKey()}/challenge")
            ->assertOk();

        $clesPortail = array_column($accueil->viewData('page')['props']['themes'], 'key');
        $clesFormulaire = array_column($formulaire->viewData('page')['props']['themes'], 'value');

        $this->assertSame($clesPortail, $clesFormulaire);
    }

    // — Administration ————————————————————————————————————————————

    public function test_le_detail_administration_affiche_la_thematique(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());
        $this->enregistrer($candidat, $dossier, $this->defi([
            ChallengeSection::THEME_FIELD => ProjectTheme::CIVIL_REGISTRY->value,
        ]))->assertOk();

        $this->actingAs($this->admin())
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Première ligne de la fiche « Défi » : la thématique cadre tout
                // le reste, et se lit en intitulé, jamais en code.
                ->where('application.sections.3.key', ApplicationSection::CHALLENGE->value)
                ->where('application.sections.3.fields.0.label', 'Thématique du projet')
                ->where('application.sections.3.fields.0.value', ProjectTheme::CIVIL_REGISTRY->label()));
    }

    public function test_la_liste_administration_affiche_la_thematique(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());
        $this->enregistrer($candidat, $dossier, $this->defi())->assertOk();

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.theme', ProjectTheme::LAND_REGISTRY->value)
                ->where('applications.0.themeLabel', ProjectTheme::LAND_REGISTRY->label())
                ->has('options.themes', 4));
    }

    /** Un dossier sans thématique s'affiche sans en inventer une. */
    public function test_la_liste_administration_tolere_un_dossier_sans_thematique(): void
    {
        $candidat = $this->candidat();
        $this->brouillon($candidat, $this->campagneOuverte());

        $this->actingAs($this->admin())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.theme', null)
                ->where('applications.0.themeLabel', null));
    }

    /**
     * Le filtre porte sur la section « Défi », dans PostgreSQL.
     *
     * Pas de filtrage en PHP après pagination : le total annoncé doit
     * correspondre aux lignes affichées.
     */
    public function test_le_filtre_par_thematique(): void
    {
        $campagne = $this->campagneOuverte();

        $foncier = $this->brouillon($candidatA = $this->candidat(), $campagne);
        $this->enregistrer($candidatA, $foncier, $this->defi())->assertOk();

        $urbain = $this->brouillon($candidatB = $this->candidat(), $campagne);
        $this->enregistrer($candidatB, $urbain, $this->defi([
            ChallengeSection::THEME_FIELD => ProjectTheme::URBAN_MANAGEMENT->value,
        ]))->assertOk();

        // Un dossier sans thématique ne remonte sous aucun filtre.
        $this->brouillon($this->candidat(), $campagne);

        $this->actingAs($this->admin())
            ->get('/admin/applications?theme='.ProjectTheme::URBAN_MANAGEMENT->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $urbain->getKey())
                ->where('filters.theme', ProjectTheme::URBAN_MANAGEMENT->value));

        $this->actingAs($this->admin())
            ->get('/admin/applications?theme='.ProjectTheme::LAND_REGISTRY->value)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('applications.0.id', $foncier->getKey()));
    }

    /** Une thématique inconnue dans l'URL est ignorée, pas rejetée. */
    public function test_un_filtre_de_thematique_illisible_est_ignore(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->brouillon($candidat, $this->campagneOuverte());
        $this->enregistrer($candidat, $dossier, $this->defi())->assertOk();

        $this->actingAs($this->admin())
            ->get('/admin/applications?theme=agroalimentaire')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 1)
                ->where('filters.theme', ''));
    }

    // — Dossier soumis ————————————————————————————————————————————

    /**
     * La thématique est conservée dans le dossier officiel.
     *
     * `SubmissionSnapshot` recopie les réponses de chaque section telles
     * quelles ; la thématique étant une réponse de l'étape « Défi », elle y
     * entre sans que rien n'ait été ajouté à la soumission. Ce test le prouve
     * plutôt que de le supposer : le dossier soumis est la seule pièce qui fera
     * foi, et une thématique absente de la copie rendrait le dossier
     * inclassable après coup, même si la section, elle, l'avait bien
     * enregistrée.
     */
    public function test_le_dossier_soumis_conserve_sa_thematique(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagneOuverte();

        $fabrique = Application::factory()->for($campagne)->for($candidat, 'candidate');

        foreach (SubmissionReadiness::requiredSections() as $section) {
            $fabrique = $fabrique->withSection($section, match ($section) {
                ApplicationSection::ELIGIBILITY => [
                    EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
                    EligibilitySection::NIGERIEN_NATIONAL => true,
                    EligibilitySection::RESIDES_IN_NIGER => true,
                    EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
                    EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
                    EligibilitySection::TEAM_SIZE => null,
                ],
                ApplicationSection::CHALLENGE => $this->defi([
                    ChallengeSection::THEME_FIELD => ProjectTheme::MAPPING_RESILIENCE->value,
                ]),
                default => ['renseigne' => 'Réponse de l’étape '.$section->position()],
            });
        }

        $dossier = $fabrique->create();

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$dossier->getKey()}/submit")
            ->assertOk();

        // Les sections du dossier soumis forment une liste ordonnée, pas un
        // dictionnaire : on retrouve « Défi » par sa clé, comme le ferait un
        // lecteur du dossier officiel.
        $sections = $dossier->fresh()->submitted_snapshot['sections'];
        $defi = collect($sections)->firstWhere('key', ApplicationSection::CHALLENGE->value);

        $this->assertNotNull($defi, 'Le dossier soumis doit contenir la section « Défi ».');
        $this->assertSame(
            ProjectTheme::MAPPING_RESILIENCE->value,
            $defi['answers'][ChallengeSection::THEME_FIELD],
        );
    }
}
