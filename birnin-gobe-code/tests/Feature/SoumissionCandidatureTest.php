<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\SubmissionBlocker;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Application\SubmissionSnapshot;
use App\Domain\Application\SubmitApplication;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le dépôt officiel d'une candidature — `DRAFT → SUBMITTED`.
 *
 * Quatre propriétés sont vérifiées ici, et ce sont celles dont dépend la valeur
 * juridique du dépôt :
 *
 * 1. **Le dépôt est complet ou inexistant.** Numéro, copie figée, horodatage,
 *    statut et journal arrivent ensemble ou pas du tout.
 * 2. **Le serveur décide.** Campagne, calendrier, éligibilité et sections
 *    exigées sont rejoués à l'écriture, quoi qu'ait affiché l'écran.
 * 3. **Un dossier ne se dépose qu'une fois.** Ni double-clic, ni renvoi réseau,
 *    ni appel direct au cas d'usage ne produisent un second numéro.
 * 4. **Ce qui est déposé ne bouge plus.** Ni par le candidat, ni par l'effet de
 *    bord d'une donnée modifiée ailleurs.
 *
 * Une remarque sur la fabrication des dossiers : l'étape 8 « Pièces /
 * déclarations » n'a pas encore d'écran, donc aucun candidat ne peut aujourd'hui
 * remplir un dossier déposable par les vraies routes. Les tests écrivent donc
 * directement la ligne de cette section. Ce n'est pas un contournement de la
 * règle — c'est précisément ce que la règle attend : `SubmissionReadiness` ne
 * regarde que les `completed_at`, et le jour où cet écran existera, le même
 * dossier passera par lui sans qu'une ligne d'ici ne change.
 *
 * Les étapes 5 à 7 en sont la démonstration : elles étaient dans ce même cas à
 * l'écriture de ce fichier, elles ont depuis leur écran, et pas une ligne de
 * `SubmissionReadiness` n'a bougé.
 */
final class SoumissionCandidatureTest extends TestCase
{
    use RefreshDatabase;

    /** Les cinq critères renseignés : le seul état où `ELIGIBLE` est atteignable. */
    private function reglesCompletes(): array
    {
        return [
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->addMonths(2)->format('Y-m-d')],
            'requires_niger_link' => true,
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
            'candidate_types' => array_map(static fn (CandidateType $t): string => $t->value, CandidateType::cases()),
            'team_size' => ['min' => 2, 'max' => 10],
        ];
    }

    private function campagne(array $eligibilite = [], array $attributs = []): Campaign
    {
        $campagne = Campaign::factory()->create($attributs);

        $campagne->forceFill(['settings' => $eligibilite === [] ? [] : ['eligibility' => $eligibilite]])->save();

        return $campagne->fresh();
    }

    /** @return array<string, mixed> */
    private function reponsesEligibilite(array $remplacements = []): array
    {
        return array_merge([
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
        ], $remplacements);
    }

    /**
     * Un dossier dont **toutes les sections exigées** sont achevées.
     *
     * Y compris celles qui n'ont pas encore d'écran : c'est le seul état dans
     * lequel un dépôt doit aboutir, et il faut donc pouvoir le fabriquer pour le
     * prouver.
     */
    private function dossierComplet(Campaign $campagne, ?User $candidat = null, array $reponses = []): Application
    {
        $fabrique = Application::factory()
            ->for($campagne)
            ->for($candidat ?? User::factory(), 'candidate');

        foreach (SubmissionReadiness::requiredSections() as $section) {
            $fabrique = $fabrique->withSection(
                $section,
                $section === ApplicationSection::ELIGIBILITY
                    ? $this->reponsesEligibilite($reponses)
                    : ['renseigne' => 'Réponse de l’étape '.$section->position()],
            );
        }

        return $fabrique->create();
    }

    /**
     * Un dossier complet de l'étape 1 à l'étape 7 — tout sauf les pièces.
     *
     * Ce fabricant portait le parcours ouvert tant que celui-ci s'arrêtait à
     * l'étape 7. Depuis l'ouverture de l'étape 8, parcours ouvert et sections
     * exigées coïncident, et un dossier bâti sur `openPath()` serait déposable :
     * les tests qui mesurent la distance entre « rempli » et « déposable »
     * n'auraient plus rien à mesurer. Ce qu'ils vérifient — un dossier auquel il
     * manque une pièce ne part pas — est nommé ici explicitement.
     */
    private function dossierSansPieces(Campaign $campagne, ?User $candidat = null): Application
    {
        $fabrique = Application::factory()
            ->for($campagne)
            ->for($candidat ?? User::factory(), 'candidate');

        foreach (SubmissionReadiness::requiredSections() as $section) {
            if ($section === ApplicationSection::ATTACHMENTS) {
                continue;
            }

            $fabrique = $fabrique->withSection(
                $section,
                $section === ApplicationSection::ELIGIBILITY
                    ? $this->reponsesEligibilite()
                    : ['renseigne' => 'Réponse de l’étape '.$section->position()],
            );
        }

        return $fabrique->create();
    }

    /** Le pourcentage qu'un nombre de sections achevées vaut, sur les neuf. */
    private function pourcentage(int $sections): int
    {
        return (int) round($sections / ApplicationSection::total() * 100);
    }

    private function deposer(User $candidat, Application $dossier): TestResponse
    {
        return $this->actingAs($candidat)->postJson("/candidate/application/{$dossier->getKey()}/submit");
    }

    // — Le dépôt lui-même ——————————————————————————————————————————

    /** Un dossier complet part, et tout ce que le dépôt produit arrive ensemble. */
    public function test_un_dossier_complet_est_depose(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)
            ->assertOk()
            ->assertJsonPath('status', ApplicationStatus::SUBMITTED->value);

        $depose = $dossier->fresh();

        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
        $this->assertMatchesRegularExpression('/^BG-\d{4}-\d{6}$/', $depose->submission_number);
        $this->assertNotNull($depose->submitted_at);
        $this->assertNotNull($depose->submitted_snapshot);

        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'APPLICATION_SUBMITTED')
                ->where('target_id', (string) $dossier->getKey())
                ->count(),
        );
    }

    /** L'horodatage vient du serveur : la requête ne peut pas l'imposer. */
    public function test_la_date_de_depot_vient_du_serveur(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $avant = now()->subSecond();

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$dossier->getKey()}/submit", [
                'submitted_at' => '1999-01-01T00:00:00+00:00',
                'submission_number' => 'BG-1999-000001',
                'status' => ApplicationStatus::ADMISSIBLE->value,
            ])
            ->assertOk();

        $depose = $dossier->fresh();

        $this->assertTrue($depose->submitted_at->greaterThanOrEqualTo($avant));
        $this->assertNotSame('BG-1999-000001', $depose->submission_number);
        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
    }

    /** Le numéro est unique d'un dossier à l'autre. */
    public function test_deux_dossiers_recoivent_deux_numeros_distincts(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());

        $premier = User::factory()->create();
        $second = User::factory()->create();

        $this->deposer($premier, $this->dossierComplet($campagne, $premier))->assertOk();
        $this->deposer($second, $this->dossierComplet($campagne, $second))->assertOk();

        $numeros = Application::query()->whereNotNull('submission_number')->pluck('submission_number');

        $this->assertCount(2, $numeros);
        $this->assertCount(2, array_unique($numeros->all()));
    }

    // — Le point critique : le parcours ouvert ne suffit pas ————————

    /**
     * **Le garde-fou de cette phase.**
     *
     * Un dossier auquel il manque une section exigée n'est pas un dossier
     * complet, quelle que soit la longueur du chemin déjà parcouru. Le dépôt
     * doit refuser, et nommer ce qui manque — sinon la plateforme délivrerait
     * des numéros officiels à des dossiers amputés, sans retour possible.
     */
    public function test_un_dossier_sans_pieces_est_refuse(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($campagne, $candidat);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.ready', false)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::SECTIONS_INCOMPLETE->value);

        $this->assertNull($dossier->fresh()->submission_number);
        $this->assertSame(ApplicationStatus::DRAFT, $dossier->fresh()->status);
    }

    /**
     * Le refus nomme les étapes qui manquent, il ne se contente pas de refuser.
     *
     * **Ce test mesure la distance entre ce qui est rempli et ce qui est
     * exigé**, et c'est sa raison d'être. Il nommait quatre sections quand les
     * étapes 5 à 7 n'existaient pas, une seule depuis leur livraison — et
     * toujours une seule ici, sans qu'une ligne de `SubmissionReadiness` ait
     * changé.
     *
     * Ce que cela prouve : la recevabilité ne se déduit pas d'une progression.
     * Un dossier peut afficher sept étapes sur neuf et rester non déposable,
     * parce qu'il manque une pièce que le concours exige.
     */
    public function test_le_refus_nomme_les_sections_manquantes(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($campagne, $candidat);

        // Le parcours ouvert compte les neuf étapes depuis que la relecture y
        // figure ; ce dossier en a rempli sept, et la neuvième ne s'y remplit
        // pas — elle ne persiste rien.
        $this->assertCount(9, ApplicationSection::openPath());
        $this->assertSame(7, app(ApplicationProgress::class)->completedOnOpenPath($dossier));

        $verdict = SubmissionReadiness::for($dossier, app(EvaluateEligibility::class));

        $manquantes = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            $verdict->missingSections,
        );

        // Et il n'est pourtant pas déposable : « Pièces / déclarations » manque.
        $this->assertSame([ApplicationSection::ATTACHMENTS->value], $manquantes);
        $this->assertFalse($verdict->ready);
        $this->assertSame([SubmissionBlocker::SECTIONS_INCOMPLETE], $verdict->blockers);
    }

    /**
     * Sept étapes sur neuf ne déposent pas un dossier.
     *
     * Le garde-fou éprouvé par la route elle-même : un candidat à qui il manque
     * les pièces reçoit un refus motivé, pas un numéro de dépôt.
     */
    public function test_un_dossier_a_sept_neuviemes_n_est_pas_deposable(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($campagne, $candidat);

        $this->assertSame($this->pourcentage(7), (int) $dossier->fresh()->completion_percent);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.ready', false)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::SECTIONS_INCOMPLETE->value)
            ->assertJsonPath('submission.missingSections.0.key', ApplicationSection::ATTACHMENTS->value);

        $depose = $dossier->fresh();

        $this->assertSame(ApplicationStatus::DRAFT, $depose->status);
        $this->assertNull($depose->submission_number);
        $this->assertNull($depose->submitted_at);
        $this->assertNull($depose->submitted_snapshot);
    }

    /**
     * « Relecture / envoi » n'est pas une section de contenu.
     *
     * L'exiger achevée demanderait de terminer l'envoi avant de pouvoir
     * envoyer : la condition ne serait jamais remplie.
     */
    public function test_la_relecture_n_est_pas_une_section_exigee(): void
    {
        $exigees = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            SubmissionReadiness::requiredSections(),
        );

        $this->assertNotContains(ApplicationSection::REVIEW->value, $exigees);
        $this->assertCount(ApplicationSection::total() - 1, $exigees);
        $this->assertContains(ApplicationSection::ATTACHMENTS->value, $exigees);
    }

    // — Campagne et calendrier ——————————————————————————————————————

    public function test_une_campagne_close_refuse_le_depot(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), ['status' => CampaignStatus::CLOSED]);
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::CAMPAIGN_NOT_OPEN->value);

        $this->assertNull($dossier->fresh()->submission_number);
    }

    public function test_une_date_limite_depassee_refuse_le_depot(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), [
            'opens_at' => now()->subDays(60),
            'closes_at' => now()->subDay(),
        ]);
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::DEADLINE_PASSED->value);

        $this->assertNull($dossier->fresh()->submission_number);
    }

    public function test_une_campagne_pas_encore_ouverte_refuse_le_depot(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), [
            'opens_at' => now()->addWeek(),
            'closes_at' => now()->addMonths(2),
        ]);
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::CAMPAIGN_NOT_YET_OPEN->value);
    }

    /**
     * La date limite est celle de l'édition, lue dans **son** fuseau.
     *
     * Une clôture fixée à Niamey ne doit pas se déplacer parce que le serveur
     * tourne ailleurs. Le dossier ci-dessous a une heure d'avance sur la
     * clôture : il passe, quel que soit le fuseau du processus.
     */
    public function test_la_date_limite_est_lue_dans_le_fuseau_de_la_campagne(): void
    {
        $campagne = $this->campagne($this->reglesCompletes(), [
            'timezone' => 'Africa/Niamey',
            'opens_at' => now()->subMonth(),
            'closes_at' => now()->addHour(),
        ]);
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $this->assertNotNull($dossier->fresh()->submission_number);
    }

    // — Éligibilité —————————————————————————————————————————————————

    /** Une règle bloquante ferme le dépôt : c'est le seul verdict qui l'interdit. */
    public function test_un_dossier_ineligible_ne_peut_pas_etre_depose(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat, [
            // Hors de la tranche d'âge : la règle devient bloquante.
            EligibilitySection::BIRTH_DATE => now()->subYears(60)->format('Y-m-d'),
        ]);

        $this->deposer($candidat, $dossier)
            ->assertStatus(422)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::ELIGIBILITY_BLOCKING->value);

        $this->assertNull($dossier->fresh()->submission_number);
        $this->assertSame(ApplicationStatus::DRAFT, $dossier->fresh()->status);
    }

    /**
     * « Sous réserve » laisse déposer, et c'est un choix documenté.
     *
     * `TO_CONFIRM` signifie qu'un paramètre de campagne n'est pas encore arrêté
     * — pas que le candidat échoue à une condition. Le cahier des charges (§5.2)
     * autorise explicitement à poursuivre « tant qu'aucune règle bloquante n'est
     * validée », et `EligibilityOutcome::blocksNextSections()` en fait déjà la
     * règle pour le formulaire. Fermer le dépôt ici punirait le candidat d'une
     * décision que le comité de pilotage n'a pas prise. L'éligibilité reste de
     * toute façon indicative : l'admissibilité est une décision humaine
     * postérieure (§10.2), et le verdict du jour est figé dans la copie.
     */
    public function test_un_verdict_sous_reserve_laisse_deposer(): void
    {
        $regles = $this->reglesCompletes();
        unset($regles['age']);

        $campagne = $this->campagne($regles);
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $depose = $dossier->fresh();

        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
        $this->assertSame('TO_CONFIRM', $depose->submitted_snapshot['eligibility']['outcome']);
    }

    // — Appartenance et double dépôt ————————————————————————————————

    public function test_un_autre_candidat_ne_peut_pas_deposer_ce_dossier(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $proprietaire = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $proprietaire);

        $this->deposer(User::factory()->create(), $dossier)->assertForbidden();

        $this->assertNull($dossier->fresh()->submission_number);
    }

    /**
     * Le second envoi n'aboutit à rien — et c'est explicite.
     *
     * La route s'arrête sur `can:update`, qui exige un brouillon : la seconde
     * requête reçoit 403 sans jamais atteindre le domaine. Aucun second numéro,
     * aucune seconde copie, aucun second événement.
     */
    public function test_un_second_envoi_ne_produit_aucun_doublon(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $apresPremier = $dossier->fresh();

        $this->deposer($candidat, $dossier)->assertForbidden();

        $apresSecond = $dossier->fresh();

        $this->assertSame($apresPremier->submission_number, $apresSecond->submission_number);
        $this->assertEquals($apresPremier->submitted_at, $apresSecond->submitted_at);
        $this->assertSame($apresPremier->submitted_snapshot, $apresSecond->submitted_snapshot);

        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'APPLICATION_SUBMITTED')
                ->where('target_id', (string) $dossier->getKey())
                ->count(),
        );
    }

    /**
     * Deux appels concurrents ne déposent qu'une fois.
     *
     * Le cas d'usage est appelé deux fois de suite sans passer par la route,
     * donc sans la garde de la policy : c'est exactement la position dans
     * laquelle se retrouve la seconde requête d'un double-clic, une fois la
     * première commitée. Le verrou `lockForUpdate` sérialise, le second appel
     * relit `SUBMITTED` et ressort sans écrire — sans lever d'erreur non plus,
     * parce qu'un candidat qui a cliqué deux fois a bien déposé.
     */
    public function test_un_second_appel_du_cas_d_usage_est_idempotent(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $deposer = app(SubmitApplication::class);

        $deposer->handle($dossier, $candidat);
        $apresPremier = $dossier->fresh();

        $second = $deposer->handle($dossier->fresh(), $candidat);
        $apresSecond = $dossier->fresh();

        // Le second appel rend le dossier déjà déposé, et n'a rien réécrit.
        $this->assertSame($apresPremier->submission_number, $second->submission_number);
        $this->assertSame($apresPremier->submission_number, $apresSecond->submission_number);
        $this->assertEquals($apresPremier->submitted_at, $apresSecond->submitted_at);
        $this->assertSame($apresPremier->submitted_snapshot, $apresSecond->submitted_snapshot);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'APPLICATION_SUBMITTED')->count(),
        );
    }

    /** Le cas d'usage refuse aussi hors HTTP, là où aucune policy ne s'applique. */
    public function test_le_cas_d_usage_refuse_un_acteur_qui_n_est_pas_le_candidat(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $dossier = $this->dossierComplet($campagne);

        $this->expectException(DomainException::class);

        app(SubmitApplication::class)->handle($dossier, User::factory()->create());
    }

    // — Verrouillage après dépôt ————————————————————————————————————

    /**
     * Une fois déposé, plus rien ne se réécrit.
     *
     * Les quatre sections branchées sont éprouvées une à une. La protection ne
     * vit pas dans les contrôleurs mais dans `ApplicationPolicy::update()`,
     * déclarée sur chaque route par `can:update,application` : une section
     * ajoutée plus tard héritera de la même barrière du seul fait de suivre la
     * convention de route.
     */
    public function test_un_dossier_depose_n_accepte_plus_aucune_modification(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $avant = $dossier->fresh()->submitted_snapshot;

        foreach (['eligibility', 'profile', 'team', 'challenge'] as $section) {
            $this->actingAs($candidat)
                ->patchJson("/candidate/application/{$dossier->getKey()}/{$section}", ['renseigne' => 'réécrit'])
                ->assertForbidden();
        }

        $this->assertSame($avant, $dossier->fresh()->submitted_snapshot);
        $this->assertSame(ApplicationStatus::SUBMITTED, $dossier->fresh()->status);
    }

    // — La copie figée ——————————————————————————————————————————————

    /** La copie porte de quoi reconstituer le dépôt, et sa version de format. */
    public function test_la_copie_figee_contient_le_dossier_depose(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create(['name' => 'Hadiza Souley']);
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $copie = $dossier->fresh()->submitted_snapshot;

        $this->assertSame(SubmissionSnapshot::SCHEMA_VERSION, $copie['schema_version']);
        $this->assertSame($dossier->fresh()->submission_number, $copie['submission_number']);
        $this->assertSame('Hadiza Souley', $copie['candidate']['name']);
        $this->assertSame($campagne->code, $copie['campaign']['code']);
        $this->assertSame($campagne->timezone, $copie['campaign']['timezone']);
        $this->assertCount(count(SubmissionReadiness::requiredSections()), $copie['sections']);
        $this->assertSame(ApplicationSection::ELIGIBILITY->value, $copie['sections'][0]['key']);
        $this->assertSame('ELIGIBLE', $copie['eligibility']['outcome']);

        // Rien de technique n'y entre : ni mot de passe, ni jeton, ni adresse IP.
        $this->assertStringNotContainsString('password', json_encode($copie));
        $this->assertStringNotContainsString('remember_token', json_encode($copie));
    }

    /** Une donnée modifiée après coup ne réécrit pas ce qui a été déposé. */
    public function test_la_copie_ne_bouge_pas_quand_une_donnee_externe_change(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create(['name' => 'Hadiza Souley']);
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $copieInitiale = $dossier->fresh()->submitted_snapshot;

        $candidat->forceFill(['name' => 'Nom corrigé plus tard'])->save();
        $campagne->forceFill(['name' => 'Édition renommée', 'closes_at' => now()->addYear()])->save();
        $dossier->sections()->where('section', ApplicationSection::ELIGIBILITY->value)
            ->update(['answers' => json_encode(['efface' => true])]);

        $this->assertSame($copieInitiale, $dossier->fresh()->submitted_snapshot);
        $this->assertSame('Hadiza Souley', $dossier->fresh()->submitted_snapshot['candidate']['name']);
    }

    // — Ce que l'administration en voit ————————————————————————————

    public function test_l_administration_voit_le_dossier_depose(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();
        $dossier = $this->dossierComplet($campagne, $candidat);

        $this->deposer($candidat, $dossier)->assertOk();

        $depose = $dossier->fresh();
        $administrateur = User::factory()->role(UserRole::ADMIN)->create();

        $this->actingAs($administrateur)
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.status', ApplicationStatus::SUBMITTED->value)
                ->where('applications.0.statusLabel', ApplicationStatus::SUBMITTED->label())
                ->where('applications.0.submissionNumber', $depose->submission_number)
                ->where('applications.0.submittedAt', $depose->submitted_at->toIso8601String()));

        $this->actingAs($administrateur)
            ->get("/admin/applications/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.status', ApplicationStatus::SUBMITTED->value)
                ->where('application.submissionNumber', $depose->submission_number)
                ->where('application.submittedAt', $depose->submitted_at->toIso8601String()));
    }

    /** Un brouillon n'a ni numéro ni date : l'écran doit le dire, pas l'inventer. */
    public function test_un_brouillon_n_annonce_ni_numero_ni_date(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $this->dossierSansPieces($campagne);

        $this->actingAs(User::factory()->role(UserRole::ADMIN)->create())
            ->get('/admin/applications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.0.status', ApplicationStatus::DRAFT->value)
                ->where('applications.0.submissionNumber', null)
                ->where('applications.0.submittedAt', null));
    }

    /** L'administration ne dépose pas à la place du candidat. */
    public function test_l_administration_ne_peut_pas_deposer_un_dossier(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $dossier = $this->dossierComplet($campagne);

        $this->actingAs(User::factory()->role(UserRole::ADMIN)->create())
            ->postJson("/candidate/application/{$dossier->getKey()}/submit")
            ->assertForbidden();

        $this->assertNull($dossier->fresh()->submission_number);
    }

    /** Le tableau de bord compte les dépôts, il ne les devine pas. */
    public function test_le_tableau_de_bord_compte_les_dossiers_deposes(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $candidat = User::factory()->create();

        $this->dossierSansPieces($campagne);
        $this->deposer($candidat, $this->dossierComplet($campagne, $candidat))->assertOk();

        $this->actingAs(User::factory()->role(UserRole::ADMIN)->create())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('applications.total', 2)
                ->where('applications.drafts', 1)
                ->where('applications.submitted', 1));
    }
}
