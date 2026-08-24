<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ImplementationSection;
use App\Domain\Application\SubmissionBlocker;
use App\Domain\Application\SubmissionReadiness;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Étape 9 — la relecture, et le geste de dépôt qu'elle commande.
 *
 * Ce fichier n'éprouve **pas** le moteur de dépôt : `SoumissionCandidatureTest`
 * s'en charge, et le rejouer ici ferait deux suites à maintenir pour une seule
 * règle. Ce qui est vérifié ici, c'est le raccord — que l'écran montre ce que le
 * domaine décide, et rien d'autre :
 *
 *  1. la relecture est une **projection** : elle n'écrit rien, ne complète
 *     aucune section, et « Relecture / envoi » ne devient jamais une étape
 *     achevée du seul fait qu'on l'a ouverte ;
 *  2. les motifs de refus affichés sont ceux de `SubmissionReadiness`, pas une
 *     règle réinventée ;
 *  3. le dépôt passe par le cas d'usage existant, et l'accusé rend ce que la
 *     base porte ;
 *  4. après dépôt, l'écran cesse d'offrir ce que le serveur refuserait.
 */
final class RelectureEtDepotTest extends TestCase
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

    private function campagne(array $attributs = []): Campaign
    {
        $campagne = Campaign::factory()->create($attributs);

        $campagne->forceFill(['settings' => ['eligibility' => $this->reglesCompletes()]])->save();

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
     * Un dossier dont toutes les sections exigées sont achevées.
     *
     * L'étape 8 n'a pas encore d'écran — elle est développée en parallèle. Sa
     * ligne est donc écrite directement, exactement comme le fait
     * `SoumissionCandidatureTest` : `SubmissionReadiness` ne regarde que les
     * `completed_at`, et le jour où cet écran existera, le même dossier passera
     * par lui sans qu'une ligne d'ici ne change.
     */
    private function dossierDeposable(Campaign $campagne, ?User $candidat = null, array $eligibilite = []): Application
    {
        $fabrique = Application::factory()
            ->for($campagne)
            ->for($candidat ?? User::factory(), 'candidate');

        foreach (SubmissionReadiness::requiredSections() as $section) {
            $fabrique = $fabrique->withSection(
                $section,
                $section === ApplicationSection::ELIGIBILITY
                    ? $this->reponsesEligibilite($eligibilite)
                    : ['renseigne' => 'Réponse de l’étape '.$section->position()],
            );
        }

        return $fabrique->create();
    }

    /** Le même dossier, moins l'étape 8 : le cas courant tant qu'elle n'existe pas. */
    private function dossierSansPieces(Campaign $campagne, ?User $candidat = null): Application
    {
        $fabrique = Application::factory()
            ->for($campagne)
            ->for($candidat ?? User::factory(), 'candidate');

        foreach (ApplicationSection::openPath() as $section) {
            $fabrique = $fabrique->withSection(
                $section,
                $section === ApplicationSection::ELIGIBILITY
                    ? $this->reponsesEligibilite()
                    : ['renseigne' => 'Réponse de l’étape '.$section->position()],
            );
        }

        return $fabrique->create();
    }

    private function url(Application $dossier): string
    {
        return "/candidate/application/{$dossier->getKey()}/review";
    }

    // — Accès ——————————————————————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_la_connexion(): void
    {
        $dossier = $this->dossierSansPieces($this->campagne());

        $this->get($this->url($dossier))->assertRedirect('/login');
    }

    public function test_un_candidat_ne_peut_pas_relire_le_dossier_d_un_autre(): void
    {
        $dossier = $this->dossierSansPieces($this->campagne());

        $this->actingAs(User::factory()->create())
            ->get($this->url($dossier))
            ->assertForbidden();
    }

    // — La relecture ne modifie rien ————————————————————————————————

    /**
     * **Ouvrir la relecture n'achève aucune étape.**
     *
     * C'est le piège que cette phase devait éviter : marquer « Relecture / envoi »
     * comme faite parce que la page a été affichée. La section entrerait alors
     * dans le parcours ouvert, et la progression réclamerait pour toujours une
     * ligne que personne n'écrit.
     */
    public function test_ouvrir_la_relecture_n_ecrit_rien(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $avant = $dossier->fresh();
        $lignesAvant = $dossier->sections()->count();

        $this->actingAs($candidat)->get($this->url($dossier))->assertOk();

        $apres = $dossier->fresh();

        $this->assertSame($lignesAvant, $apres->sections()->count());
        $this->assertNull($apres->sectionAnswers(ApplicationSection::REVIEW));
        $this->assertSame($avant->completion_percent, $apres->completion_percent);
        $this->assertSame($avant->current_step, $apres->current_step);
        $this->assertEquals($avant->updated_at, $apres->updated_at);
    }

    /** La relecture affiche les neuf étapes, lisibles, jamais en JSON brut. */
    public function test_la_relecture_montre_les_neuf_etapes(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Candidate/Application/Review')
                ->has('sections', ApplicationSection::total())
                ->where('sections.0.key', ApplicationSection::ELIGIBILITY->value)
                ->where('sections.0.state', 'complete')
                // Des couples libellé/valeur, pas des clés techniques.
                ->where('sections.0.fields.0.label', 'Date de naissance')
                ->where('sections.8.key', ApplicationSection::REVIEW->value));
    }

    /** Chaque étape développée offre son lien de correction ; les autres, non. */
    public function test_chaque_etape_developpee_offre_un_lien_de_modification(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);
        $id = $dossier->getKey();

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.editUrl', route('candidate.application.eligibility', $id))
                ->where('sections.6.editUrl', route('candidate.application.implementation', $id))
                // Étape 8 : pas encore d'écran, donc pas de lien mort. C'est le
                // point de raccord avec la branche « Pièces / déclarations ».
                ->where('sections.7.editUrl', null)
                ->where('sections.8.editUrl', null));
    }

    /** Une correction faite depuis la relecture ne perd rien. */
    public function test_le_candidat_peut_corriger_une_etape_et_revenir(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $this->actingAs($candidat)->get($this->url($dossier))->assertOk();

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/implementation", [
                ImplementationSection::ACTIVITIES => 'Trois ateliers de terrain, corrigés depuis la relecture.',
            ])
            ->assertOk();

        $this->actingAs($candidat)->get($this->url($dossier))->assertOk();

        $this->assertSame(
            'Trois ateliers de terrain, corrigés depuis la relecture.',
            $dossier->fresh()->sectionAnswers(ApplicationSection::IMPLEMENTATION)->answers[ImplementationSection::ACTIVITIES],
        );
    }

    // — La recevabilité vient du domaine ————————————————————————————

    /** Les motifs affichés sont ceux de `SubmissionReadiness`, à l'identique. */
    public function test_la_relecture_affiche_les_motifs_du_domaine(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submission.ready', false)
                ->where('submission.blockers.0.code', SubmissionBlocker::SECTIONS_INCOMPLETE->value)
                ->where('submission.blockers.0.label', SubmissionBlocker::SECTIONS_INCOMPLETE->label())
                ->where('submission.missingSections.0.key', ApplicationSection::ATTACHMENTS->value));
    }

    /** Une campagne close ferme le dépôt, et la relecture le dit. */
    public function test_une_campagne_close_est_annoncee_sur_la_relecture(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(['status' => CampaignStatus::CLOSED]), $candidat);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submission.ready', false)
                ->where('submission.blockers.0.code', SubmissionBlocker::CAMPAIGN_NOT_OPEN->value));
    }

    /** Une règle d'éligibilité bloquante aussi. */
    public function test_une_eligibilite_bloquante_est_annoncee_sur_la_relecture(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat, [
            EligibilitySection::BIRTH_DATE => now()->subYears(60)->format('Y-m-d'),
        ]);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submission.ready', false)
                ->where('submission.blockers.0.code', SubmissionBlocker::ELIGIBILITY_BLOCKING->value));
    }

    /** Un dossier complet est annoncé déposable. */
    public function test_un_dossier_complet_est_annonce_deposable(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submission.ready', true)
                ->has('submission.blockers', 0)
                ->where('submitUrl', route('candidate.application.submit', $dossier)));
    }

    // — Le dépôt et son accusé ——————————————————————————————————————

    /** Le geste complet : relire, déposer, atterrir sur l'accusé. */
    public function test_le_depot_mene_a_l_accuse_de_depot(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)->get($this->url($dossier))->assertOk();

        $this->actingAs($candidat)
            ->post("/candidate/application/{$dossier->getKey()}/submit")
            ->assertRedirect(route('candidate.application.submitted', $dossier));

        $depose = $dossier->fresh();

        $this->assertSame(ApplicationStatus::SUBMITTED, $depose->status);
        $this->assertMatchesRegularExpression('/^BG-\d{4}-\d{6}$/', $depose->submission_number);
        $this->assertNotNull($depose->submitted_at);
        $this->assertNotNull($depose->submitted_snapshot);

        $this->actingAs($candidat)
            ->get(route('candidate.application.submitted', $dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Candidate/Application/Submitted')
                ->where('application.submissionNumber', $depose->submission_number)
                ->where('application.submittedAt', $depose->submitted_at->toIso8601String())
                ->where('application.statusLabel', ApplicationStatus::SUBMITTED->label()));
    }

    /** Un refus laisse le candidat sur la relecture, sans rien déposer. */
    public function test_un_depot_refuse_ramene_a_la_relecture(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->from($this->url($dossier))
            ->post("/candidate/application/{$dossier->getKey()}/submit")
            ->assertRedirect($this->url($dossier))
            ->assertSessionHas('submissionRefusee');

        $this->assertSame(ApplicationStatus::DRAFT, $dossier->fresh()->status);
        $this->assertNull($dossier->fresh()->submission_number);
    }

    /**
     * La campagne se clôt entre la relecture et le clic.
     *
     * Le cas qui justifie que le serveur reste autoritaire : l'écran a été rendu
     * quand tout allait bien, et la décision se prend à l'écriture.
     */
    public function test_une_campagne_qui_se_clot_entre_temps_fait_refuser_le_depot(): void
    {
        $candidat = User::factory()->create();
        $campagne = $this->campagne();
        $dossier = $this->dossierDeposable($campagne, $candidat);

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('submission.ready', true));

        // Le comité clôt l'édition pendant que le candidat relit.
        //
        // La marge est d'un jour, et non d'une minute, pour une raison qui n'est
        // pas cosmétique : la lecture d'une colonne `timestamptz` à travers le
        // cast `datetime` décale l'instant du décalage horaire de l'application
        // — une heure pour Africa/Niamey. Une clôture dépassée d'une minute
        // n'est donc pas vue comme dépassée. Le défaut est antérieur à cette
        // branche et vit dans les casts de modèle, pas ici ; il est signalé au
        // rapport. Ce test éprouve ce qu'il annonce — une clôture survenue entre
        // l'affichage et le clic ferme le dépôt — sans dépendre de la précision
        // défaillante.
        $campagne->forceFill(['closes_at' => now()->subDay()])->save();

        $this->actingAs($candidat)
            ->from($this->url($dossier))
            ->post("/candidate/application/{$dossier->getKey()}/submit")
            ->assertRedirect($this->url($dossier));

        $this->assertSame(ApplicationStatus::DRAFT, $dossier->fresh()->status);
        $this->assertNull($dossier->fresh()->submission_number);

        // Et la relecture annonce désormais le vrai motif.
        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('submission.ready', false)
                ->where('submission.blockers.0.code', SubmissionBlocker::DEADLINE_PASSED->value));
    }

    /** Un second envoi ne produit aucun doublon. */
    public function test_un_second_envoi_ne_depose_pas_deux_fois(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)->post("/candidate/application/{$dossier->getKey()}/submit")->assertRedirect();

        $premier = $dossier->fresh();

        $this->actingAs($candidat)->post("/candidate/application/{$dossier->getKey()}/submit")->assertForbidden();

        $second = $dossier->fresh();

        $this->assertSame($premier->submission_number, $second->submission_number);
        $this->assertEquals($premier->submitted_at, $second->submitted_at);
        $this->assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'APPLICATION_SUBMITTED')
                ->where('target_id', (string) $dossier->getKey())
                ->count(),
        );
    }

    // — Après le dépôt ——————————————————————————————————————————————

    /** La relecture n'a plus lieu d'être : elle mène à l'accusé. */
    public function test_apres_depot_la_relecture_mene_a_l_accuse(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)->post("/candidate/application/{$dossier->getKey()}/submit")->assertRedirect();

        $this->actingAs($candidat)
            ->get($this->url($dossier))
            ->assertRedirect(route('candidate.application.submitted', $dossier));
    }

    /** Un brouillon n'a pas d'accusé : on le renvoie relire. */
    public function test_un_brouillon_n_a_pas_d_accuse(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierSansPieces($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->get(route('candidate.application.submitted', $dossier))
            ->assertRedirect($this->url($dossier));
    }

    /**
     * Le tableau de bord cesse de proposer la reprise.
     *
     * La protection est côté serveur — `ApplicationPolicy::update()` — mais
     * l'écran ne doit pas offrir un chemin que le serveur refusera : le
     * candidat cliquerait, atterrirait sur un formulaire figé, et croirait à une
     * panne.
     */
    public function test_le_tableau_de_bord_montre_le_depot_et_non_la_reprise(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)
            ->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('application.continueUrl', fn ($url) => $url !== null));

        $this->actingAs($candidat)->post("/candidate/application/{$dossier->getKey()}/submit")->assertRedirect();

        $depose = $dossier->fresh();

        $this->actingAs($candidat)
            ->get('/candidate/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.status', ApplicationStatus::SUBMITTED->value)
                ->where('application.submissionNumber', $depose->submission_number)
                ->where('application.submittedAt', $depose->submitted_at->toIso8601String())
                ->where('application.continueUrl', null)
                ->where('application.submittedUrl', route('candidate.application.submitted', $dossier)));
    }

    /** Et les sections restent fermées à l'écriture. */
    public function test_apres_depot_aucune_section_n_accepte_de_modification(): void
    {
        $candidat = User::factory()->create();
        $dossier = $this->dossierDeposable($this->campagne(), $candidat);

        $this->actingAs($candidat)->post("/candidate/application/{$dossier->getKey()}/submit")->assertRedirect();

        foreach (['eligibility', 'profile', 'team', 'challenge', 'solution', 'impact', 'implementation'] as $section) {
            $this->actingAs($candidat)
                ->patchJson("/candidate/application/{$dossier->getKey()}/{$section}", ['renseigne' => 'réécrit'])
                ->assertForbidden();
        }
    }
}
