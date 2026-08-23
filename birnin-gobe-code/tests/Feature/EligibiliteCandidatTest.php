<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilityOutcome;
use App\Domain\Eligibility\EligibilityRule;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Eligibility\RuleStatus;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Étape 1 — Éligibilité guidée (Phase 1D).
 *
 * Le verdict est calculé par le serveur à partir des réponses en base et des
 * paramètres de la campagne. Ces tests vérifient donc deux choses distinctes :
 * ce qui est écrit en PostgreSQL, et ce que le serveur en conclut.
 */
final class EligibiliteCandidatTest extends TestCase
{
    use RefreshDatabase;

    private function candidat(): User
    {
        return User::factory()->create();
    }

    /**
     * Campagne ouverte, avec des paramètres d'éligibilité optionnels.
     *
     * Les paramètres sont écrits directement dans `settings`, colonne `jsonb`
     * existante : aucun modèle, aucune factory et aucune migration de campagne
     * n'est touché ici — leur écriture appartient à l'administration.
     *
     * @param  array<string, mixed>  $eligibilite
     */
    private function campagne(array $eligibilite = []): Campaign
    {
        $campagne = Campaign::factory()->create();

        if ($eligibilite !== []) {
            $campagne->forceFill(['settings' => ['eligibility' => $eligibilite]])->save();
        }

        return $campagne;
    }

    private function brouillonDe(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    private function urlEligibilite(Application $application): string
    {
        return "/candidate/application/{$application->getKey()}/eligibility";
    }

    /**
     * Campagne dont les cinq règles sont explicitement configurées.
     *
     * Depuis la correction « configuration explicite », aucune règle n'a de
     * valeur par défaut : c'est le seul état dans lequel un dossier peut
     * devenir `ELIGIBLE`.
     *
     * @param  array<string, mixed>  $remplacements
     * @return array<string, mixed>
     */
    private function reglesCompletes(array $remplacements = []): array
    {
        return [
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->addMonths(2)->format('Y-m-d')],
            'requires_niger_link' => true,
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
            'candidate_types' => array_map(static fn (CandidateType $t): string => $t->value, CandidateType::cases()),
            'team_size' => ['min' => 2, 'max' => 10],
            ...$remplacements,
        ];
    }

    /**
     * Les mêmes règles, moins celles nommées : sert à isoler l'effet d'un seul
     * paramètre manquant.
     *
     * @return array<string, mixed>
     */
    private function reglesSauf(string ...$cles): array
    {
        $regles = $this->reglesCompletes();

        foreach ($cles as $cle) {
            unset($regles[$cle]);
        }

        return $regles;
    }

    /**
     * Jeu de réponses qui ne déclenche aucune règle bloquante.
     *
     * @return array<string, mixed>
     */
    private function reponsesConformes(array $remplacements = []): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
            ...$remplacements,
        ];
    }

    // — Ordre des étapes ————————————————————————————————————————————

    public function test_un_nouveau_brouillon_demarre_sur_l_eligibilite(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();

        $reponse = $this->actingAs($candidat)->post('/candidate/application');
        $application = Application::query()->sole();

        $reponse->assertRedirect($this->urlEligibilite($application));

        $this->assertSame(ApplicationSection::ELIGIBILITY, $application->current_step);
        $this->assertSame(1, ApplicationSection::ELIGIBILITY->position());
    }

    public function test_un_brouillon_deja_ouvert_sur_le_defi_reste_valide(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillonDe($candidat, $campagne);

        // Cas des candidatures créées avant cette phase : leur étape courante
        // pointe sur « Défi », qui reste une section ouverte.
        $application->forceFill(['current_step' => ApplicationSection::CHALLENGE])->save();

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect("/candidate/application/{$application->getKey()}/challenge");

        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk();
    }

    // — Persistance ————————————————————————————————————————————————

    public function test_les_reponses_sont_ecrites_en_base_avec_leur_type(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonStructure(['savedAt', 'application', 'steps', 'completed', 'eligibility']);

        $ligne = ApplicationSectionAnswers::query()
            ->where('section', ApplicationSection::ELIGIBILITY->value)
            ->sole();

        $this->assertSame($application->getKey(), $ligne->application_id);

        // Le typage a lieu une fois, à l'écriture : `jsonb` conserve de vrais
        // booléens, pas des chaînes « 1 » que chaque lecture réinterpréterait.
        $this->assertTrue($ligne->answers[EligibilitySection::NIGERIEN_NATIONAL]);
        $this->assertTrue($ligne->answers[EligibilitySection::RESIDES_IN_NIGER]);
        $this->assertSame(NigerRegion::NIAMEY->value, $ligne->answers[EligibilitySection::INTERVENTION_REGION]);
        $this->assertNotNull($ligne->completed_at);
    }

    public function test_un_effectif_est_persiste_comme_entier(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => '4',
            ]))
            ->assertOk();

        $this->assertSame(4, ApplicationSectionAnswers::query()->sole()->answers[EligibilitySection::TEAM_SIZE]);
    }

    public function test_une_sauvegarde_partielle_est_acceptee_sans_achever_la_section(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [
                EligibilitySection::NIGERIEN_NATIONAL => true,
            ])
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INCOMPLETE->value);

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertTrue($ligne->answers[EligibilitySection::NIGERIEN_NATIONAL]);
        $this->assertNull($ligne->answers[EligibilitySection::BIRTH_DATE]);
        $this->assertNull($ligne->completed_at);
    }

    public function test_les_sauvegardes_successives_ne_creent_qu_une_ligne(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        foreach ([NigerRegion::AGADEZ, NigerRegion::DOSSO, NigerRegion::ZINDER] as $region) {
            $this->actingAs($candidat)
                ->patchJson($this->urlEligibilite($application), [EligibilitySection::INTERVENTION_REGION => $region->value])
                ->assertOk();
        }

        $ligne = ApplicationSectionAnswers::query()->sole();

        $this->assertSame(1, ApplicationSectionAnswers::query()->count());
        $this->assertSame(NigerRegion::ZINDER->value, $ligne->answers[EligibilitySection::INTERVENTION_REGION]);
    }

    public function test_current_step_bascule_sur_l_eligibilite_a_la_sauvegarde(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());
        $application->forceFill(['current_step' => ApplicationSection::CHALLENGE])->save();

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk();

        $this->assertDatabaseHas('applications', [
            'id' => $application->getKey(),
            'current_step' => ApplicationSection::ELIGIBILITY->value,
        ]);
    }

    // — Validation serveur ————————————————————————————————————————

    public function test_une_region_hors_referentiel_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [EligibilitySection::INTERVENTION_REGION => 'FR-75'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(EligibilitySection::INTERVENTION_REGION);

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_type_de_candidature_inconnu_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [EligibilitySection::CANDIDATE_TYPE => 'CONSORTIUM'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(EligibilitySection::CANDIDATE_TYPE);
    }

    public function test_une_date_de_naissance_dans_le_futur_est_refusee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [
                EligibilitySection::BIRTH_DATE => now()->addDay()->format('Y-m-d'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(EligibilitySection::BIRTH_DATE);
    }

    public function test_un_effectif_non_numerique_est_refuse(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [EligibilitySection::TEAM_SIZE => 'plusieurs'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(EligibilitySection::TEAM_SIZE);
    }

    public function test_le_verdict_envoye_par_le_navigateur_est_ignore(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));

        // Requête forgée : le client affirme être éligible tout en répondant
        // qu'il n'a aucun lien avec le Niger.
        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::NIGERIEN_NATIONAL => false,
                EligibilitySection::RESIDES_IN_NIGER => false,
                'eligible' => true,
                'outcome' => EligibilityOutcome::ELIGIBLE->value,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $enregistrees = ApplicationSectionAnswers::query()->sole()->answers;

        $this->assertArrayNotHasKey('eligible', $enregistrees);
        $this->assertArrayNotHasKey('outcome', $enregistrees);
    }

    // — Calcul du verdict ————————————————————————————————————————

    public function test_un_dossier_conforme_est_eligible_quand_toutes_les_regles_sont_configurees(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes());
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value)
            ->assertJsonPath('eligibility.blocksNextSections', false);

        // `ELIGIBLE` n'est atteint que si les cinq règles ont conclu.
        foreach (EligibilityRule::cases() as $regle) {
            $this->assertStatutDeRegle($application, $regle, RuleStatus::SATISFIED);
        }
    }

    /**
     * Une campagne peut configurer une règle collective et rester cohérente
     * pour un candidat individuel : l'effectif ne le concerne pas.
     */
    public function test_une_candidature_collective_conforme_est_eligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes());
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 4,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);
    }

    // — Matrice : une règle non configurée ne conclut pas ————————————

    /**
     * @return array<string, array{string, EligibilityRule}>
     */
    public static function criteresNonConfigures(): array
    {
        return [
            'age' => ['age', EligibilityRule::AGE],
            'lien avec le Niger' => ['requires_niger_link', EligibilityRule::NATIONALITY_RESIDENCE],
            'zones' => ['regions', EligibilityRule::ZONE],
            'types de candidature' => ['candidate_types', EligibilityRule::CANDIDATE_TYPE],
            'taille d equipe' => ['team_size', EligibilityRule::TEAM_SIZE],
        ];
    }

    /**
     * Le cœur de la correction : un critère que le comité de pilotage n'a pas
     * arrêté ne devient pas une règle du concours parce que le logiciel aurait
     * une opinion. Il ne conclut pas, et le dossier reste « à confirmer ».
     */
    #[DataProvider('criteresNonConfigures')]
    public function test_un_critere_non_configure_laisse_le_dossier_a_confirmer(string $cleAbsente, EligibilityRule $regle): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesSauf($cleAbsente));
        $application = $this->brouillonDe($candidat, $campagne);

        // Réponses collectives : sans cela, la règle de taille d'équipe ne
        // s'appliquerait pas et le cas `team_size` ne prouverait rien.
        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 4,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::TO_CONFIRM->value)
            ->assertJsonPath('eligibility.blocksNextSections', false);

        $this->assertStatutDeRegle($application, $regle, RuleStatus::NOT_CONFIGURED);
    }

    #[DataProvider('criteresNonConfigures')]
    public function test_un_critere_non_configure_est_explique_sans_jargon(string $cleAbsente, EligibilityRule $regle): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesSauf($cleAbsente));
        $application = $this->brouillonDe($candidat, $campagne);

        $reponse = $this->actingAs($candidat)->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
            EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
            EligibilitySection::TEAM_SIZE => 4,
        ]))->assertOk();

        $constat = collect($reponse->json('eligibility.findings'))
            ->firstWhere('rule', $regle->value);

        $this->assertStringContainsString('pas encore publiée', $constat['message']);
        $this->assertStringContainsString('reste indicatif', $constat['message']);

        // Aucune fuite de vocabulaire technique vers le candidat.
        foreach (['NOT_CONFIGURED', 'settings', 'campaign', 'null'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase($jargon, $constat['message']);
        }
    }

    public function test_un_seul_critere_manquant_suffit_a_empecher_le_resultat_eligible(): void
    {
        $candidat = $this->candidat();
        // Quatre règles sur cinq configurées et satisfaites : le dossier ne
        // doit pas pour autant être annoncé comme définitivement éligible.
        $campagne = $this->campagne($this->reglesSauf('age'));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::TO_CONFIRM->value);

        $this->assertStatutDeRegle($application, EligibilityRule::NATIONALITY_RESIDENCE, RuleStatus::SATISFIED);
        $this->assertStatutDeRegle($application, EligibilityRule::ZONE, RuleStatus::SATISFIED);
        $this->assertStatutDeRegle($application, EligibilityRule::CANDIDATE_TYPE, RuleStatus::SATISFIED);
        $this->assertStatutDeRegle($application, EligibilityRule::AGE, RuleStatus::NOT_CONFIGURED);
    }

    public function test_aucune_regle_configuree_ne_donne_jamais_eligible(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::TO_CONFIRM->value)
            ->assertJsonPath('eligibility.blocksNextSections', false);
    }

    public function test_un_age_hors_tranche_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->format('Y-m-d')],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::BIRTH_DATE => now()->subYears(52)->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value)
            ->assertJsonPath('eligibility.blocksNextSections', true);

        $this->assertBloquePar($application, EligibilityRule::AGE);
    }

    public function test_l_age_se_calcule_a_la_date_de_reference_et_non_au_jour_de_la_saisie(): void
    {
        $candidat = $this->candidat();
        // Le candidat a 35 ans aujourd'hui et 36 à la date de référence : c'est
        // cette dernière qui fait foi, sinon le verdict changerait tout seul
        // d'un jour à l'autre.
        $reference = now()->addMonths(6);
        $campagne = $this->campagne($this->reglesCompletes([
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => $reference->format('Y-m-d')],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::BIRTH_DATE => $reference->copy()->subYears(36)->format('Y-m-d'),
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::AGE);
    }

    public function test_ni_nationalite_ni_residence_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        // La condition n'existe que si la campagne l'a posée.
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::NIGERIEN_NATIONAL => false,
                EligibilitySection::RESIDES_IN_NIGER => false,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::NATIONALITY_RESIDENCE);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function liensAvecLeNiger(): array
    {
        return [
            'nationalite seule' => [true, false],
            'residence seule' => [false, true],
            'les deux' => [true, true],
        ];
    }

    #[DataProvider('liensAvecLeNiger')]
    public function test_la_nationalite_ou_la_residence_suffit(bool $nationalite, bool $residence): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::NIGERIEN_NATIONAL => $nationalite,
                EligibilitySection::RESIDES_IN_NIGER => $residence,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);

        $this->assertStatutDeRegle($application, EligibilityRule::NATIONALITY_RESIDENCE, RuleStatus::SATISFIED);
    }

    public function test_une_campagne_peut_lever_la_condition_de_lien_avec_le_niger(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes(['requires_niger_link' => false]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::NIGERIEN_NATIONAL => false,
                EligibilitySection::RESIDES_IN_NIGER => false,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);

        $this->assertStatutDeRegle($application, EligibilityRule::NATIONALITY_RESIDENCE, RuleStatus::SATISFIED);
    }

    public function test_une_zone_hors_campagne_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'regions' => [NigerRegion::MARADI->value, NigerRegion::ZINDER->value],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::INTERVENTION_REGION => NigerRegion::AGADEZ->value,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::ZONE);
    }

    public function test_une_zone_ouverte_par_la_campagne_satisfait_la_regle(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'regions' => [NigerRegion::DIFFA->value, NigerRegion::NIAMEY->value],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::INTERVENTION_REGION => NigerRegion::DIFFA->value,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);

        $this->assertStatutDeRegle($application, EligibilityRule::ZONE, RuleStatus::SATISFIED);
    }

    /**
     * Le référentiel des régions reste une règle de **validation** : il
     * s'applique que la campagne ait publié ses zones ou non. Ne pas confondre
     * « ce code désigne-t-il une région du Niger » et « cette campagne
     * couvre-t-elle cette région ».
     */
    public function test_le_referentiel_des_regions_s_applique_meme_sans_zones_configurees(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [EligibilitySection::INTERVENTION_REGION => 'FR-75'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(EligibilitySection::INTERVENTION_REGION);
    }

    public function test_un_type_de_candidature_ecarte_par_la_campagne_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'candidate_types' => [CandidateType::STARTUP->value],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::CANDIDATE_TYPE);
    }

    public function test_un_type_de_candidature_accepte_par_la_campagne_satisfait_la_regle(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'candidate_types' => [CandidateType::INDIVIDUAL->value],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);

        $this->assertStatutDeRegle($application, EligibilityRule::CANDIDATE_TYPE, RuleStatus::SATISFIED);
    }

    public function test_un_effectif_sous_le_minimum_configure_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes(['team_size' => ['min' => 2, 'max' => 10]]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::TEAM_SIZE);
    }

    public function test_un_effectif_au_dessus_du_maximum_configure_rend_ineligible(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes(['team_size' => ['min' => 2, 'max' => 5]]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 9,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value);

        $this->assertBloquePar($application, EligibilityRule::TEAM_SIZE);
    }

    /**
     * Sans borne publiée, une équipe d'une seule personne n'est pas déclarée
     * inéligible : « au moins deux » est une évidence, pas une décision du
     * comité de pilotage. C'est précisément ce que cette correction interdit
     * d'inventer.
     */
    public function test_une_equipe_d_une_personne_n_est_pas_bloquee_sans_regle_publiee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesSauf('team_size')));

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::CANDIDATE_TYPE => CandidateType::TEAM->value,
                EligibilitySection::TEAM_SIZE => 1,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::TO_CONFIRM->value)
            ->assertJsonPath('eligibility.blocksNextSections', false);

        $this->assertStatutDeRegle($application, EligibilityRule::TEAM_SIZE, RuleStatus::NOT_CONFIGURED);
    }

    /**
     * « Non configuré » ne veut pas dire « tout est accepté » : la validation
     * technique reste entière, indépendamment des règles métier.
     */
    public function test_un_effectif_incoherent_reste_refuse_meme_sans_regle_publiee(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesSauf('team_size')));

        foreach ([-15, 0, 1000] as $effectif) {
            $this->actingAs($candidat)
                ->patchJson($this->urlEligibilite($application), [EligibilitySection::TEAM_SIZE => $effectif])
                ->assertStatus(422)
                ->assertJsonValidationErrors(EligibilitySection::TEAM_SIZE);
        }

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_une_candidature_individuelle_n_a_pas_d_effectif_a_declarer(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('completed', true);
    }

    public function test_le_verdict_est_reproductible_a_partir_de_la_base(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne($this->reglesCompletes([
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->format('Y-m-d')],
        ]));
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertOk()
            ->assertJsonPath('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value);

        // Rien n'est stocké : recharger l'écran refait le calcul, et retrouve
        // exactement le même verdict.
        $this->actingAs($candidat)->get($this->urlEligibilite($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('eligibility.outcome', EligibilityOutcome::ELIGIBLE->value));
    }

    public function test_le_verdict_suit_le_parametrage_de_la_campagne(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillonDe($candidat, $campagne);

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
                EligibilitySection::INTERVENTION_REGION => NigerRegion::AGADEZ->value,
            ]))
            ->assertOk()
            ->assertJsonPath('eligibility.blocksNextSections', false);

        // Le comité publie ensuite les zones : le verdict change sans qu'une
        // seule réponse du candidat soit modifiée. C'est précisément ce qu'un
        // verdict figé en base ne saurait pas faire.
        $campagne->forceFill([
            'settings' => ['eligibility' => $this->reglesCompletes(['regions' => [NigerRegion::NIAMEY->value]])],
        ])->save();

        $this->actingAs($candidat)->get($this->urlEligibilite($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('eligibility.outcome', EligibilityOutcome::INELIGIBLE->value));
    }

    // — Cas non éligible : les réponses restent ————————————————————

    public function test_un_candidat_non_eligible_conserve_ses_reponses(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));
        $reponses = $this->reponsesConformes([
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]);

        $this->actingAs($candidat)->patchJson($this->urlEligibilite($application), $reponses)->assertOk();

        // La candidature existe toujours, au statut brouillon, et l'écran
        // d'éligibilité reste ouvert : le candidat peut corriger.
        $this->assertSame(ApplicationStatus::DRAFT, $application->fresh()->status);
        $this->assertSame(1, Application::query()->count());

        $this->actingAs($candidat)->get($this->urlEligibilite($application))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.'.EligibilitySection::INTERVENTION_REGION, NigerRegion::NIAMEY->value)
                ->where('answers.'.EligibilitySection::NIGERIEN_NATIONAL, '0'));
    }

    public function test_un_candidat_non_eligible_n_atteint_pas_la_section_suivante(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));

        $this->actingAs($candidat)->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]))->assertOk();

        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertRedirect($this->urlEligibilite($application));

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$application->getKey()}/challenge", ['main_challenge' => 'Tentative'])
            ->assertForbidden();

        $this->assertSame(
            0,
            ApplicationSectionAnswers::query()->where('section', ApplicationSection::CHALLENGE->value)->count(),
            'Une section fermée ne doit rien écrire.',
        );
    }

    public function test_corriger_ses_reponses_rouvre_la_section_suivante(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne($this->reglesCompletes()));
        $url = $this->urlEligibilite($application);

        $this->actingAs($candidat)->patchJson($url, $this->reponsesConformes([
            EligibilitySection::NIGERIEN_NATIONAL => false,
            EligibilitySection::RESIDES_IN_NIGER => false,
        ]))->assertOk();

        $this->actingAs($candidat)->get("/candidate/application/{$application->getKey()}/challenge")->assertRedirect($url);

        $this->actingAs($candidat)->patchJson($url, $this->reponsesConformes())->assertOk();

        $this->actingAs($candidat)->get("/candidate/application/{$application->getKey()}/challenge")->assertOk();
    }

    public function test_un_dossier_incomplet_ne_ferme_pas_la_section_suivante(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        // Aucune réponse : rien ne bloque encore, donc le candidat continue.
        // Cahier des charges §5.2, « tant qu'aucune règle bloquante n'est validée ».
        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/challenge")
            ->assertOk();
    }

    // — Progression ————————————————————————————————————————————————

    public function test_la_progression_compte_les_sections_reellement_achevees(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)->patchJson($this->urlEligibilite($application), $this->reponsesConformes())->assertOk();

        $uneSurNeuf = (int) round(1 / ApplicationSection::total() * 100);
        $this->assertSame($uneSurNeuf, (int) $application->fresh()->completion_percent);

        // L'étape 3 ayant été développée en Phase 1F, « Défi » est revenu dans
        // le parcours ouvert : le remplir fait de nouveau avancer la
        // progression — voir StructureEquipeCandidatTest.
        $this->actingAs($candidat)->patchJson("/candidate/application/{$application->getKey()}/challenge", [
            'main_challenge' => 'L’accès à l’eau potable en périphérie.',
            'affected_people' => 'Les ménages non raccordés au réseau.',
            'location' => NigerRegion::NIAMEY->value,
            'root_causes' => 'Une extension urbaine plus rapide que le réseau.',
        ])->assertOk();

        $deuxSurNeuf = (int) round(2 / ApplicationSection::total() * 100);
        $this->assertSame($deuxSurNeuf, (int) $application->fresh()->completion_percent);
    }

    public function test_une_section_ouverte_mais_incomplete_ne_compte_pas(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), [EligibilitySection::NIGERIEN_NATIONAL => true])
            ->assertOk();

        $this->assertSame(0, (int) $application->fresh()->completion_percent);
    }

    // — Ownership et cloisonnement ————————————————————————————————

    public function test_un_candidat_ne_lit_pas_l_eligibilite_d_un_autre(): void
    {
        $campagne = $this->campagne();
        $application = $this->brouillonDe($this->candidat(), $campagne);

        $this->actingAs($this->candidat())
            ->get($this->urlEligibilite($application))
            ->assertForbidden();
    }

    public function test_un_candidat_ne_modifie_pas_l_eligibilite_d_un_autre(): void
    {
        $campagne = $this->campagne();
        $application = $this->brouillonDe($this->candidat(), $campagne);

        $this->actingAs($this->candidat())
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertForbidden();

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    public function test_un_visiteur_n_ouvre_pas_l_eligibilite(): void
    {
        $application = Application::factory()->create([
            'candidate_id' => $this->candidat()->getKey(),
            'campaign_id' => $this->campagne()->getKey(),
        ]);

        $this->get($this->urlEligibilite($application))->assertRedirect('/login');
        $this->patch($this->urlEligibilite($application), $this->reponsesConformes())->assertRedirect('/login');

        $this->assertSame(0, ApplicationSectionAnswers::query()->count());
    }

    /**
     * @return array<string, array{UserRole}>
     */
    public static function rolesInternes(): array
    {
        return [
            'administrateur' => [UserRole::ADMIN],
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    #[DataProvider('rolesInternes')]
    public function test_un_role_interne_n_ouvre_pas_l_eligibilite_d_un_candidat(UserRole $role): void
    {
        $campagne = $this->campagne();
        $application = $this->brouillonDe($this->candidat(), $campagne);
        $interne = User::factory()->role($role)->create();

        $this->actingAs($interne)->get($this->urlEligibilite($application))->assertForbidden();
    }

    public function test_une_candidature_soumise_n_est_plus_modifiable(): void
    {
        $candidat = $this->candidat();
        $application = $this->brouillonDe($candidat, $this->campagne());

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)
            ->patchJson($this->urlEligibilite($application), $this->reponsesConformes())
            ->assertForbidden();

        // La consultation reste ouverte à son propriétaire.
        $this->actingAs($candidat)->get($this->urlEligibilite($application))->assertOk();
    }

    // — Reprise ————————————————————————————————————————————————————

    public function test_les_reponses_survivent_a_une_deconnexion_et_une_reconnexion(): void
    {
        $this->campagne();
        $candidat = User::factory()->create(['password' => 'MotDePasseSolide!2026']);

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->post('/candidate/application');
        $application = Application::query()->sole();

        $this->patchJson($this->urlEligibilite($application), $this->reponsesConformes([
            EligibilitySection::CANDIDATE_TYPE => CandidateType::STARTUP->value,
            EligibilitySection::TEAM_SIZE => 5,
        ]))->assertOk();

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', ['email' => $candidat->email, 'password' => 'MotDePasseSolide!2026']);
        $this->assertAuthenticatedAs($candidat);

        $this->get('/candidate/dashboard')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('application.currentStep.key', ApplicationSection::ELIGIBILITY->value)
            ->where('application.continueUrl', url($this->urlEligibilite($application))));

        $this->get($this->urlEligibilite($application))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('answers.'.EligibilitySection::CANDIDATE_TYPE, CandidateType::STARTUP->value)
                ->where('answers.'.EligibilitySection::TEAM_SIZE, '5')
                ->where('answers.'.EligibilitySection::RESIDES_IN_NIGER, '1'));
    }

    private function assertBloquePar(Application $application, EligibilityRule $regle): void
    {
        $this->assertStatutDeRegle($application, $regle, RuleStatus::BLOCKING);
    }

    /** Verdict d'une règle précise, recalculé depuis la base. */
    private function assertStatutDeRegle(Application $application, EligibilityRule $regle, RuleStatus $attendu): void
    {
        $verdict = app(EvaluateEligibility::class)->forApplication($application->fresh());

        foreach ($verdict->findings as $constat) {
            if ($constat->rule === $regle) {
                $this->assertSame(
                    $attendu,
                    $constat->status,
                    "Règle {$regle->value} : {$constat->status->value} au lieu de {$attendu->value} — {$constat->message}",
                );

                return;
            }
        }

        $this->fail("Aucun verdict rendu pour la règle {$regle->value}.");
    }
}
