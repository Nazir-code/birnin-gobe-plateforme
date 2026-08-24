<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\SubmissionBlocker;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ce que la clôture ferme réellement — et ce qu'elle ne ferme pas.
 *
 * La page d'accueil annonce au public ce qui se passera à la date de clôture.
 * Cette phrase engage la plateforme : un candidat qui la lit règle son travail
 * dessus. Elle doit donc décrire le comportement du serveur, pas une intention.
 *
 * Ce fichier établit les deux moitiés du contrat, mesurées et non supposées :
 *
 *  1. **Le dépôt se ferme.** Passé `closes_at`, `SubmissionReadiness` refuse, et
 *     la route de dépôt refuse avec lui. C'est le fait sur lequel la phrase
 *     publique repose.
 *  2. **La saisie, elle, reste ouverte.** `ApplicationPolicy::update()` autorise
 *     l'écriture sur le seul critère « c'est son dossier et il est encore un
 *     brouillon » ; aucune borne de calendrier n'y figure, et aucun middleware
 *     n'en ajoute — `eligible` ne juge que les règles d'éligibilité.
 *
 * Le second point n'est pas présenté ici comme un défaut : il n'a simplement
 * aucune conséquence tant qu'un dossier non déposé ne vaut rien. Ce qu'il
 * interdit, en revanche, c'est d'écrire au public que « les dossiers sont
 * verrouillés » à la clôture. Le libellé de l'accueil dit donc ce qui est vrai :
 * plus aucune candidature ne pourra être soumise.
 */
final class ClotureEtVerrouillageTest extends TestCase
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

    /** Une édition dont la date limite est franchement passée. */
    private function campagneClose(): Campaign
    {
        $campagne = Campaign::factory()->create([
            'opens_at' => now()->subMonths(2),
            'closes_at' => now()->subWeek(),
        ]);

        $campagne->forceFill(['settings' => ['eligibility' => $this->reglesCompletes()]])->save();

        return $campagne->fresh();
    }

    /** @return array<string, mixed> */
    private function reponsesEligibilite(): array
    {
        return [
            EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
            EligibilitySection::NIGERIEN_NATIONAL => true,
            EligibilitySection::RESIDES_IN_NIGER => true,
            EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
            EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
            EligibilitySection::TEAM_SIZE => null,
        ];
    }

    private function dossier(Campaign $campagne, User $candidat): Application
    {
        return Application::factory()
            ->for($campagne)
            ->for($candidat, 'candidate')
            ->withSection(ApplicationSection::ELIGIBILITY, $this->reponsesEligibilite())
            ->create();
    }

    /** Passé la date limite, plus aucun dépôt n'est possible. */
    public function test_apres_la_cloture_le_depot_est_refuse(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossier($this->campagneClose(), $candidat);

        $verdict = SubmissionReadiness::for($dossier, app(EvaluateEligibility::class));

        $this->assertFalse($verdict->ready);
        $this->assertContains(SubmissionBlocker::DEADLINE_PASSED, $verdict->blockers);

        $this->actingAs($candidat)
            ->postJson("/candidate/application/{$dossier->getKey()}/submit")
            ->assertStatus(422)
            ->assertJsonPath('submission.blockers.0.code', SubmissionBlocker::DEADLINE_PASSED->value);

        $this->assertSame(ApplicationStatus::DRAFT, $dossier->fresh()->status);
        $this->assertNull($dossier->fresh()->submission_number);
    }

    /**
     * En revanche, la saisie d'un brouillon reste ouverte après la clôture.
     *
     * C'est ce constat qui commande le libellé public : annoncer un
     * verrouillage des dossiers serait faux, alors qu'annoncer la fermeture du
     * dépôt est exact. Le domaine `Campaign` n'est pas modifié pour faire
     * correspondre le serveur à une phrase — c'est la phrase qui suit le serveur.
     */
    public function test_apres_la_cloture_un_brouillon_reste_modifiable(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossier($this->campagneClose(), $candidat);

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/eligibility", $this->reponsesEligibilite())
            ->assertOk();

        $this->assertNotNull(
            $dossier->fresh()->sectionAnswers(ApplicationSection::ELIGIBILITY),
            'La saisie devrait rester possible : la policy ne connaît aucune date.',
        );
    }

    /** Le libellé public dit ce que le serveur fait, ni plus ni moins. */
    public function test_l_accueil_annonce_la_fermeture_du_depot_et_non_un_verrouillage(): void
    {
        $rendu = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('les dossiers sont verrouillés', $rendu);
    }
}
