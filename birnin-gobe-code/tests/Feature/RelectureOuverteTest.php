<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Http\Presenters\ApplicationPresenter;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * L'etape 9 est ouverte : elle a un ecran, la navigation doit y mener.
 *
 * Ce fichier existe a cause d'un bug constate sur une candidature reelle. Le
 * dossier avait ses huit sections achevees, `SubmissionReadiness` le declarait
 * recevable, et `GET .../review` repondait 200 — mais l'ecran de l'etape 8
 * n'offrait aucun bouton « Suivant » et la liste des etapes affichait la
 * neuvieme comme indisponible. Le candidat arrivait au bout de son dossier et
 * ne pouvait plus avancer.
 *
 * La cause etait une omission : `ApplicationSection::isImplemented()` ne
 * listait pas `REVIEW`, alors que la route, le controleur et l'ecran etaient
 * livres. La branche qui a livre l'etape 9 n'avait pas touche l'enum, et la
 * fusion ne pouvait pas le signaler.
 *
 * Les tests ci-dessous fixent les deux moities de la correction — la section
 * est declaree ouverte, et la navigation sait ou aller — **et** ce que la
 * correction ne doit pas emporter avec elle : aucune ligne `review` en base,
 * une recevabilite qui n'exige toujours pas cette section, et une progression
 * inchangee.
 */
final class RelectureOuverteTest extends TestCase
{
    use RefreshDatabase;

    // — L'enum ———————————————————————————————————————————————————————

    /** 1. « Relecture / envoi » est une section que le produit propose. */
    public function test_la_relecture_est_declaree_implementee(): void
    {
        $this->assertTrue(ApplicationSection::REVIEW->isImplemented());
        $this->assertTrue(ApplicationSection::REVIEW->isOnOpenPath());
    }

    /** 2. Le parcours ouvert ne s'arrete plus a l'etape 8. */
    public function test_l_etape_8_mene_a_la_relecture(): void
    {
        $this->assertSame(
            ApplicationSection::REVIEW,
            ApplicationSection::ATTACHMENTS->nextOnOpenPath(),
        );

        // Et la relecture reste le terminus : rien ne la suit.
        $this->assertNull(ApplicationSection::REVIEW->nextOnOpenPath());
    }

    /** Les neuf etapes sont ouvertes, dans l'ordre du concours. */
    public function test_le_parcours_ouvert_compte_les_neuf_etapes(): void
    {
        $this->assertSame(ApplicationSection::cases(), ApplicationSection::openPath());
    }

    // — Ce que le candidat voit ————————————————————————————————————

    /** 3. Un dossier complet de 1 a 8 n'affiche plus l'etape 9 comme fermee. */
    public function test_un_dossier_complet_voit_la_relecture_accessible(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeposable($candidat);

        $etapes = app(ApplicationPresenter::class)->steps($dossier);
        $relecture = collect($etapes)->firstWhere('key', ApplicationSection::REVIEW->value);

        $this->assertTrue($relecture['implemented'], 'L’étape 9 doit être annoncée disponible.');
        $this->assertTrue($relecture['onOpenPath'], 'L’étape 9 doit être sur le parcours ouvert.');

        // Les huit precedentes restent achevees : rien n'a bouge pour elles.
        $achevees = collect($etapes)->where('state', 'done')->pluck('key')->all();
        $this->assertCount(8, $achevees);
    }

    /** 4. Le bouton « Suivant » de l'etape 8 mene bien a la relecture. */
    public function test_le_bouton_suivant_de_l_etape_8_mene_a_la_relecture(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeposable($candidat);

        $navigation = app(ApplicationPresenter::class)
            ->navigation($dossier, ApplicationSection::ATTACHMENTS);

        $this->assertSame(
            route('candidate.application.review', $dossier),
            $navigation['nextUrl'],
            'Sans URL, l’enum a beau ouvrir l’étape : le bouton reste absent.',
        );

        // Et l'ecran de l'etape 8 le sert reellement au navigateur.
        $this->actingAs($candidat)
            ->get(route('candidate.application.attachments', $dossier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('nextUrl', route('candidate.application.review', $dossier)));
    }

    /** La relecture s'ouvre, et c'est bien elle. */
    public function test_la_relecture_repond(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeposable($candidat);

        $this->actingAs($candidat)
            ->get(route('candidate.application.review', $dossier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Candidate/Application/Review')
                ->where('submission.ready', true));
    }

    // — Ce que la correction ne doit pas emporter ——————————————————

    /** 5. La recevabilite n'exige toujours pas « Relecture / envoi ». */
    public function test_la_recevabilite_n_exige_pas_la_relecture(): void
    {
        $exigees = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            SubmissionReadiness::requiredSections(),
        );

        $this->assertNotContains(ApplicationSection::REVIEW->value, $exigees);
        $this->assertCount(8, $exigees);

        // Et un dossier sans ligne « review » reste deposable.
        $dossier = $this->dossierDeposable($this->candidat());
        $verdict = SubmissionReadiness::for($dossier, app(EvaluateEligibility::class));

        $this->assertTrue($verdict->ready);
        $this->assertSame([], $verdict->blockers);
        $this->assertSame([], $verdict->missingSections);
    }

    /** 6. Ouvrir la relecture n'ecrit aucune section « review ». */
    public function test_ouvrir_la_relecture_ne_cree_aucune_section(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeposable($candidat);
        $avant = ApplicationSectionAnswers::query()->count();

        $this->actingAs($candidat)
            ->get(route('candidate.application.review', $dossier))
            ->assertOk();

        $this->assertSame($avant, ApplicationSectionAnswers::query()->count());
        $this->assertDatabaseMissing('application_sections', [
            'application_id' => $dossier->getKey(),
            'section' => ApplicationSection::REVIEW->value,
        ]);
    }

    /** La progression ne bouge pas : elle compte des dates, pas des ecrans. */
    public function test_la_progression_reste_a_huit_neuviemes(): void
    {
        $dossier = $this->dossierDeposable($this->candidat());
        $progression = app(ApplicationProgress::class);

        $this->assertSame(8, $progression->completedOnOpenPath($dossier));
        $this->assertSame(89, $progression->percent($dossier));
    }

    /** 7. Le depot fonctionne toujours, et reste le seul a figer le dossier. */
    public function test_le_depot_reste_fonctionnel(): void
    {
        $candidat = $this->candidat();
        $dossier = $this->dossierDeposable($candidat);

        $this->actingAs($candidat)
            ->postJson(route('candidate.application.submit', $dossier))
            ->assertOk();

        $depose = $dossier->fresh();

        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
        $this->assertNotNull($depose->submission_number);
        $this->assertNotNull($depose->submitted_at);
        $this->assertNotNull($depose->submitted_snapshot);

        // Toujours aucune section « review » apres le depot.
        $this->assertDatabaseMissing('application_sections', [
            'application_id' => $dossier->getKey(),
            'section' => ApplicationSection::REVIEW->value,
        ]);
    }

    // — Outils ——————————————————————————————————————————————————————

    private function candidat(): User
    {
        return User::factory()->create(['role' => UserRole::CANDIDATE]);
    }

    /**
     * Un dossier dont les huit sections exigees sont achevees.
     *
     * Les lignes sont ecrites par la fabrique : ce fichier eprouve la
     * navigation et l'enum, pas la saisie, que les suites de chaque etape
     * couvrent deja.
     */
    private function dossierDeposable(User $candidat): Application
    {
        $campagne = Campaign::factory()->create();
        $campagne->forceFill(['settings' => ['eligibility' => [
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => now()->addMonths(2)->format('Y-m-d')],
            'requires_niger_link' => true,
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
            'candidate_types' => array_map(static fn (CandidateType $t): string => $t->value, CandidateType::cases()),
            'team_size' => ['min' => 2, 'max' => 10],
        ]]])->save();

        $fabrique = Application::factory()->for($campagne->fresh())->for($candidat, 'candidate');

        foreach (SubmissionReadiness::requiredSections() as $section) {
            $fabrique = $fabrique->withSection(
                $section,
                $section === ApplicationSection::ELIGIBILITY ? [
                    EligibilitySection::BIRTH_DATE => now()->subYears(26)->format('Y-m-d'),
                    EligibilitySection::NIGERIEN_NATIONAL => true,
                    EligibilitySection::RESIDES_IN_NIGER => true,
                    EligibilitySection::INTERVENTION_REGION => NigerRegion::NIAMEY->value,
                    EligibilitySection::CANDIDATE_TYPE => CandidateType::INDIVIDUAL->value,
                    EligibilitySection::TEAM_SIZE => null,
                ] : ['renseigne' => 'Réponse de l’étape '.$section->position()],
            );
        }

        return $fabrique->create();
    }
}
