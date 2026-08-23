<?php

namespace Tests\Feature;

use App\Domain\Application\EligibilitySection;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilityOutcome;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Point de rencontre entre l'administration des campagnes (ADR-008) et
 * l'éligibilité guidée (ADR-007).
 *
 * Chacune des deux fonctionnalités est couverte par sa propre suite. Ce fichier
 * ne les répète pas : il vérifie ce qu'aucune des deux ne peut vérifier seule —
 * que le moteur d'éligibilité lit les paramètres **de la campagne à laquelle le
 * dossier est rattaché**, et non ceux d'une campagne trouvée autrement.
 *
 * La distinction n'est pas théorique. `ActiveCampaign` sert à savoir *où
 * déposer*, jamais *selon quelles règles juger* : un dossier déposé sous
 * l'édition 2026 reste jugé sur les critères de 2026, même après l'ouverture de
 * 2027. Un moteur qui interrogerait la campagne active changerait
 * rétroactivement les règles sous les pieds des candidats.
 *
 * L'écriture de `settings.eligibility` se fait ici directement en base, et c'est
 * délibéré : ce fichier isole le moteur de l'écran qui le configure. Le passage
 * par l'écran d'administration (ADR-009) est couvert, lui, par
 * `AdministrationEligibiliteTest`. Le reste du parcours passe par les vraies
 * routes.
 */
final class IntegrationCampagneEligibiliteTest extends TestCase
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

    /** Les cinq règles explicitement configurées : le seul état où `ELIGIBLE` est atteignable. */
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

    private function campagne(array $eligibilite, array $attributs = []): Campaign
    {
        $campagne = Campaign::factory()->create($attributs);
        $campagne->forceFill(['settings' => ['eligibility' => $eligibilite]])->save();

        return $campagne;
    }

    /** Dépose un dossier par le vrai parcours, puis enregistre ses réponses. */
    private function dossierAvecReponses(User $candidat, Campaign $campagne, array $reponses): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $application = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();

        $this->actingAs($candidat)
            ->patch("/candidate/application/{$application->getKey()}/eligibility", $reponses)
            ->assertRedirect();

        return $application->fresh();
    }

    private function verdict(Application $application): EligibilityOutcome
    {
        return app(EvaluateEligibility::class)->forApplication($application->fresh())->outcome;
    }

    // — Le moteur juge selon LA campagne du dossier ————————————————

    /**
     * Le cœur de cette suite.
     *
     * Le dossier appartient à l'édition 2026, dont les règles acceptent ses
     * réponses. L'édition 2026 est ensuite close et 2027 ouverte, avec des
     * règles qui refuseraient les mêmes réponses. Le verdict du dossier ne doit
     * pas bouger : il est jugé par sa campagne, pas par la campagne du moment.
     *
     * L'invariant d'ADR-008 rend l'enchaînement obligatoire — deux campagnes ne
     * peuvent pas être ouvertes en même temps — et c'est justement ce qui rend
     * le test discriminant.
     */
    public function test_le_verdict_suit_la_campagne_du_dossier_et_non_la_campagne_ouverte(): void
    {
        $candidat = $this->candidat();
        $admin = $this->admin();

        $edition2026 = $this->campagne(
            $this->reglesCompletes(),
            ['code' => 'BG-2026', 'name' => 'BIRNIN GOBE 2026'],
        );

        $dossier = $this->dossierAvecReponses($candidat, $edition2026, $this->reponsesConformes());
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->verdict($dossier));

        // L'administration clôt 2026 puis ouvre 2027, par les vraies routes.
        $this->actingAs($admin)->put("/admin/campaigns/{$edition2026->getKey()}", [
            'code' => 'BG-2026',
            'name' => 'BIRNIN GOBE 2026',
            'status' => CampaignStatus::CLOSED->value,
            'timezone' => 'Africa/Niamey',
            'opens_at' => '2026-01-15T08:00',
            'closes_at' => '2026-04-30T23:59',
        ])->assertSessionHasNoErrors();

        // 2027 refuse la forme individuelle, que 2026 acceptait.
        $edition2027 = $this->campagne(
            $this->reglesCompletes(['candidate_types' => [CandidateType::STARTUP->value]]),
            ['code' => 'BG-2027', 'name' => 'BIRNIN GOBE 2027'],
        );

        $this->assertSame(CampaignStatus::OPEN, $edition2027->fresh()->status);

        // Le dossier de 2026 reste jugé par 2026.
        $this->assertSame(
            EligibilityOutcome::ELIGIBLE,
            $this->verdict($dossier),
            'Le moteur a utilisé la campagne ouverte au lieu de celle du dossier.',
        );
    }

    /**
     * Deux dossiers, deux campagnes, deux jeux de règles, des réponses
     * identiques : les verdicts doivent différer. Un moteur qui prendrait « la
     * première campagne trouvée » ou « la dernière créée » rendrait le même.
     */
    public function test_deux_campagnes_appliquent_chacune_leurs_propres_regles(): void
    {
        $reponses = $this->reponsesConformes();

        $permissive = $this->campagne($this->reglesCompletes(), ['code' => 'BG-PERM']);
        $dossierPermissif = $this->dossierAvecReponses($this->candidat(), $permissive, $reponses);

        // La seconde ne peut pas être ouverte en même temps (ADR-008) ; le
        // rattachement du dossier, lui, ne dépend pas du statut.
        $stricte = $this->campagne(
            $this->reglesCompletes(['regions' => [NigerRegion::ZINDER->value]]),
            ['code' => 'BG-STRICT', 'status' => CampaignStatus::DRAFT],
        );

        $candidatStrict = $this->candidat();
        $dossierStrict = Application::factory()->create([
            'candidate_id' => $candidatStrict->getKey(),
            'campaign_id' => $stricte->getKey(),
        ]);
        $this->actingAs($candidatStrict)
            ->patch("/candidate/application/{$dossierStrict->getKey()}/eligibility", $reponses)
            ->assertRedirect();

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->verdict($dossierPermissif));
        $this->assertSame(EligibilityOutcome::INELIGIBLE, $this->verdict($dossierStrict));
    }

    // — Les quatre verdicts, sur une campagne administrée ——————————

    public function test_configuration_complete_et_reponses_valides_donnent_eligible(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $dossier = $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes());

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->verdict($dossier));
    }

    public function test_une_regle_configuree_violee_donne_ineligible(): void
    {
        $campagne = $this->campagne(
            $this->reglesCompletes(['candidate_types' => [CandidateType::STARTUP->value]]),
        );

        $dossier = $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes([
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
        ]));

        $this->assertSame(EligibilityOutcome::INELIGIBLE, $this->verdict($dossier));
    }

    public function test_une_reponse_manquante_donne_incomplete(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        $dossier = $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes([
            EligibilitySection::BIRTH_DATE => null,
        ]));

        $this->assertSame(EligibilityOutcome::INCOMPLETE, $this->verdict($dossier));
    }

    /**
     * Une campagne créée par l'administration n'a aucun paramètre d'éligibilité :
     * l'écran ne les expose pas encore. Le dossier doit rester `TO_CONFIRM`, et
     * surtout pas être annoncé éligible sur des critères que personne n'a fixés.
     */
    public function test_une_campagne_sans_parametres_laisse_le_dossier_a_confirmer(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/campaigns', [
            'code' => 'BG-NEUVE',
            'name' => 'Campagne sans paramètres',
            'status' => CampaignStatus::OPEN->value,
            'timezone' => 'Africa/Niamey',
            'opens_at' => now()->subDay()->format('Y-m-d\TH:i'),
            'closes_at' => now()->addMonths(3)->format('Y-m-d\TH:i'),
        ])->assertSessionHasNoErrors();

        $campagne = Campaign::query()->where('code', 'BG-NEUVE')->sole();
        $this->assertSame([], $campagne->settings ?? []);

        $dossier = $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes());

        $this->assertSame(EligibilityOutcome::TO_CONFIRM, $this->verdict($dossier));
    }

    // — L'invariant survit au parcours candidat ————————————————————

    /**
     * Le moteur d'éligibilité ne crée ni ne modifie de campagne : dérouler un
     * parcours candidat complet ne doit pas pouvoir ouvrir une seconde édition.
     */
    public function test_le_parcours_candidat_ne_touche_pas_a_l_invariant(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        Campaign::factory()->draft()->create(['code' => 'BG-SUIVANTE']);

        $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes());
        $this->dossierAvecReponses($this->candidat(), $campagne, $this->reponsesConformes());

        $this->assertSame(1, Campaign::query()->where('status', CampaignStatus::OPEN->value)->count());
    }

    /**
     * Le rattachement se fait sur la campagne ouverte au moment du dépôt, et
     * l'unicité `(campaign_id, candidate_id)` reste portée par la base.
     */
    public function test_le_dossier_est_rattache_a_la_campagne_ouverte_du_moment(): void
    {
        $campagne = $this->campagne($this->reglesCompletes());
        Campaign::factory()->draft()->create(['code' => 'BG-AUTRE']);

        $candidat = $this->candidat();
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $dossier = Application::query()->sole();

        $this->assertSame($campagne->getKey(), $dossier->campaign_id);
    }
}
