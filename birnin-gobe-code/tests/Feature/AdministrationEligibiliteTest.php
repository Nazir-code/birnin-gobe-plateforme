<?php

namespace Tests\Feature;

use App\Domain\Application\EligibilitySection;
use App\Domain\Application\ProfileSection;
use App\Domain\Auth\UserRole;
use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilityOutcome;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Reference\NigerRegion;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administration des critères d'éligibilité par campagne (ADR-010).
 *
 * Ce que cette suite protège tient en trois invariants, dans cet ordre
 * d'importance :
 *
 * 1. **Le vide n'est jamais écrit.** Un critère laissé vide reste absent de
 *    `settings`, il n'est pas enregistré à `null`, `0` ou `[]`. C'est ce qui
 *    permet au moteur (ADR-007) de dire « non publié » plutôt que d'inventer un
 *    seuil au nom du comité de pilotage.
 *
 * 2. **Les autres clés de `settings` survivent.** Le cahier des charges (§9.2)
 *    prévoit d'y loger d'autres paramètres ; un écran qui n'en expose aucun n'a
 *    pas à les effacer.
 *
 * 3. **Ce qui est publié ici change ce que le candidat lit.** La dernière
 *    section déroule le parcours complet — administration puis candidat — pour
 *    les trois verdicts que ces critères peuvent produire.
 */
final class AdministrationEligibiliteTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    /** Les cinq critères renseignés : le seul état où `ELIGIBLE` est atteignable. */
    private function formulaire(array $remplacements = []): array
    {
        return array_merge([
            'age_min' => 18,
            'age_max' => 35,
            'age_reference_date' => now()->addMonths(2)->format('Y-m-d'),
            'requires_niger_link' => 'true',
            'regions' => [NigerRegion::NIAMEY->value, NigerRegion::MARADI->value],
            'candidate_types' => [CandidateType::INDIVIDUAL->value, CandidateType::TEAM->value],
            'team_size_min' => 2,
            'team_size_max' => 10,
        ], $remplacements);
    }

    /** Le formulaire tel qu'il part quand l'administrateur n'a rien renseigné. */
    private function formulaireVide(): array
    {
        return [
            'age_min' => '',
            'age_max' => '',
            'age_reference_date' => '',
            'requires_niger_link' => '',
            'regions' => [],
            'candidate_types' => [],
            'team_size_min' => '',
            'team_size_max' => '',
        ];
    }

    /**
     * Campagne en préparation par défaut : les critères s'éditent quel que soit
     * le statut, et l'invariant d'ADR-008 interdit deux campagnes ouvertes —
     * plusieurs de ces tests en créent deux.
     */
    private function campagne(array $settings = []): Campaign
    {
        $campagne = Campaign::factory()->draft()->create();
        $campagne->forceFill(['settings' => $settings])->save();

        return $campagne->fresh();
    }

    private function url(Campaign $campagne): string
    {
        return "/admin/campaigns/{$campagne->getKey()}/eligibility";
    }

    /** @return array<string, mixed> Le bloc réellement enregistré. */
    private function bloc(Campaign $campagne): mixed
    {
        return $campagne->fresh()->settings['eligibility'] ?? null;
    }

    // — Accès ——————————————————————————————————————————————————————

    public function test_un_admin_ouvre_les_criteres_d_une_campagne(): void
    {
        $campagne = $this->campagne(['eligibility' => [
            'age' => ['min' => 21],
            'requires_niger_link' => false,
        ]]);

        $this->actingAs($this->admin())
            ->get($this->url($campagne))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Campaigns/Eligibility')
                ->where('campaign.code', $campagne->code)
                ->where('form.age_min', '21')
                // Un plafond absent reste une chaîne vide : `0` se relirait
                // comme un critère publié.
                ->where('form.age_max', '')
                // Trois états distincts jusque dans les props : « false » est
                // une décision, la chaîne vide un silence.
                ->where('form.requires_niger_link', 'false')
                ->where('form.regions', [])
                ->where('criteria.0.rule', 'AGE')
                ->where('criteria.0.configured', true)
                ->where('criteria.2.rule', 'ZONE')
                ->where('criteria.2.configured', false));
    }

    #[DataProvider('methodesEcran')]
    public function test_un_candidat_ne_peut_pas_atteindre_les_criteres(string $methode): void
    {
        $campagne = $this->campagne();

        $this->actingAs(User::factory()->create())
            ->call($methode, $this->url($campagne), $this->formulaire())
            ->assertForbidden();

        $this->assertNull($this->bloc($campagne));
    }

    /** @return array<string, array{string}> */
    public static function methodesEcran(): array
    {
        return ['lecture' => ['GET'], 'écriture' => ['PUT']];
    }

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get($this->url($this->campagne()))->assertRedirect('/admin/login');
    }

    // — Écriture ————————————————————————————————————————————————————

    public function test_un_admin_publie_les_cinq_criteres(): void
    {
        $campagne = $this->campagne();
        $reference = now()->addMonths(2)->format('Y-m-d');

        $this->actingAs($this->admin())
            ->put($this->url($campagne), $this->formulaire())
            ->assertRedirect($this->url($campagne));

        $bloc = $this->bloc($campagne);

        // `assertEquals` et non `assertSame` : la colonne est un `jsonb`, et
        // PostgreSQL n'y conserve pas l'ordre des clés. Ce qui compte est le
        // contenu — l'ordre n'a aucun sens pour le moteur qui le relit.
        $this->assertEquals([
            'age' => ['min' => 18, 'max' => 35, 'reference_date' => $reference],
            'requires_niger_link' => true,
            'regions' => [NigerRegion::NIAMEY->value, NigerRegion::MARADI->value],
            'candidate_types' => [CandidateType::INDIVIDUAL->value, CandidateType::TEAM->value],
            'team_size' => ['min' => 2, 'max' => 10],
        ], $bloc);

        // Les types, eux, sont vérifiés strictement : des bornes stockées en
        // chaînes traverseraient `assertEquals` sans bruit, et le moteur les
        // relirait comme « non configuré » au premier changement de format.
        $this->assertSame(18, $bloc['age']['min']);
        $this->assertSame(35, $bloc['age']['max']);
        $this->assertSame(2, $bloc['team_size']['min']);
        $this->assertTrue($bloc['requires_niger_link']);
        // L'ordre des zones, lui, est bien celui de la saisie : c'est une liste,
        // pas un objet, et PostgreSQL préserve l'ordre d'un tableau JSON.
        $this->assertSame([NigerRegion::NIAMEY->value, NigerRegion::MARADI->value], $bloc['regions']);
    }

    /**
     * Le cœur d'ADR-007 vu depuis l'administration : un champ vide n'est pas une
     * valeur permissive, c'est une absence de valeur.
     */
    public function test_les_criteres_non_renseignes_ne_sont_pas_ecrits(): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->admin())
            ->put($this->url($campagne), $this->formulaireVide())
            ->assertRedirect();

        $this->assertNull($this->bloc($campagne));
        $this->assertSame([], $campagne->fresh()->settings);
    }

    public function test_un_critere_partiel_n_ecrit_que_ce_qui_est_renseigne(): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->admin())
            ->put($this->url($campagne), array_merge($this->formulaireVide(), ['age_min' => 18]))
            ->assertRedirect();

        // Ni `max` à null, ni date de référence inventée : seule la borne saisie.
        $this->assertSame(['age' => ['min' => 18]], $this->bloc($campagne));
    }

    /**
     * Les trois états du lien avec le Niger.
     *
     * `false` n'est pas l'absence : il dit « cette campagne n'impose aucune
     * condition de nationalité ni de résidence », ce qui rassure le candidat,
     * là où l'absence le laisse sous réserve.
     */
    #[DataProvider('etatsDuLienAvecLeNiger')]
    public function test_le_lien_avec_le_niger_a_trois_etats(string $saisie, ?bool $attendu): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->admin())
            ->put($this->url($campagne), array_merge($this->formulaireVide(), ['requires_niger_link' => $saisie]))
            ->assertRedirect();

        $bloc = $this->bloc($campagne);

        if ($attendu === null) {
            $this->assertNull($bloc, 'Un lien non renseigné ne doit rien écrire.');

            return;
        }

        $this->assertSame(['requires_niger_link' => $attendu], $bloc);
    }

    /** @return array<string, array{string, ?bool}> */
    public static function etatsDuLienAvecLeNiger(): array
    {
        return [
            'exigé' => ['true', true],
            'explicitement non exigé' => ['false', false],
            'non renseigné' => ['', null],
        ];
    }

    public function test_les_autres_cles_de_settings_sont_preservees(): void
    {
        $campagne = $this->campagne([
            'countdown' => ['enabled' => true],
            'legal' => ['privacy_url' => 'https://exemple.ne/confidentialite'],
        ]);

        $this->actingAs($this->admin())->put($this->url($campagne), $this->formulaire())->assertRedirect();

        $settings = $campagne->fresh()->settings;

        $this->assertSame(['enabled' => true], $settings['countdown']);
        $this->assertSame(['privacy_url' => 'https://exemple.ne/confidentialite'], $settings['legal']);
        $this->assertTrue($settings['eligibility']['requires_niger_link']);
    }

    public function test_effacer_les_criteres_ne_touche_pas_aux_autres_cles(): void
    {
        $campagne = $this->campagne([
            'countdown' => ['enabled' => true],
            'eligibility' => ['requires_niger_link' => true],
        ]);

        $this->actingAs($this->admin())->put($this->url($campagne), $this->formulaireVide())->assertRedirect();

        $this->assertSame(['countdown' => ['enabled' => true]], $campagne->fresh()->settings);
    }

    public function test_les_criteres_d_une_campagne_ne_touchent_pas_a_une_autre(): void
    {
        $voisine = $this->campagne(['eligibility' => ['requires_niger_link' => false]]);
        $campagne = $this->campagne();

        $this->actingAs($this->admin())->put($this->url($campagne), $this->formulaire())->assertRedirect();

        $this->assertSame(['requires_niger_link' => false], $this->bloc($voisine));
    }

    // — Validation serveur ——————————————————————————————————————————

    #[DataProvider('formulairesRefuses')]
    public function test_la_validation_serveur_refuse_une_saisie_incoherente(array $remplacements, string $champ): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->admin())
            ->put($this->url($campagne), array_merge($this->formulaire(), $remplacements))
            ->assertSessionHasErrors($champ);

        // Une saisie refusée n'écrit rien, pas même la partie valide.
        $this->assertNull($this->bloc($campagne));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function formulairesRefuses(): array
    {
        return [
            'âge maximum inférieur au minimum' => [['age_min' => 35, 'age_max' => 18], 'age_max'],
            'âge négatif' => [['age_min' => -1], 'age_min'],
            'âge invraisemblable' => [['age_max' => 300], 'age_max'],
            'âge non numérique' => [['age_min' => 'dix-huit'], 'age_min'],
            'date de référence mal formée' => [['age_reference_date' => '30/04/2027'], 'age_reference_date'],
            'lien avec le Niger hors des trois états' => [['requires_niger_link' => 'peut-être'], 'requires_niger_link'],
            'région hors du référentiel' => [['regions' => ['NE-99']], 'regions.0'],
            'région dupliquée' => [['regions' => ['NE-8', 'NE-8']], 'regions.0'],
            'forme de candidature inconnue' => [['candidate_types' => ['ASSOCIATION']], 'candidate_types.0'],
            'effectif maximum inférieur au minimum' => [['team_size_min' => 10, 'team_size_max' => 2], 'team_size_max'],
            'équipe de zéro personne' => [['team_size_min' => 0], 'team_size_min'],
        ];
    }

    /**
     * Une date de référence sans tranche d'âge ne s'applique à rien : le moteur
     * ne calcule un âge que s'il a une borne à lui opposer. L'accepter
     * laisserait croire que le critère d'âge est publié alors que le candidat
     * continuerait de lire « sous réserve ».
     */
    public function test_une_date_de_reference_seule_est_refusee(): void
    {
        $campagne = $this->campagne();

        $this->actingAs($this->admin())
            ->put($this->url($campagne), array_merge($this->formulaireVide(), [
                'age_reference_date' => now()->addMonths(2)->format('Y-m-d'),
            ]))
            ->assertSessionHasErrors('age_reference_date');

        $this->assertNull($this->bloc($campagne));
    }

    // — Audit ——————————————————————————————————————————————————————

    public function test_la_publication_des_criteres_est_inscrite_au_journal(): void
    {
        $administrateur = $this->admin();
        $campagne = $this->campagne(['eligibility' => ['requires_niger_link' => false]]);

        $this->actingAs($administrateur)->put($this->url($campagne), $this->formulaire())->assertRedirect();

        $evenement = AuditEvent::query()->where('action', 'CAMPAIGN_ELIGIBILITY_UPDATED')->sole();

        $this->assertSame($administrateur->getKey(), $evenement->actor_id);
        $this->assertSame(Campaign::class, $evenement->target_type);
        $this->assertSame((string) $campagne->getKey(), $evenement->target_id);
        $this->assertSame(['eligibility' => ['requires_niger_link' => false]], $evenement->old_value);
        $this->assertTrue($evenement->new_value['eligibility']['requires_niger_link']);
        $this->assertSame([18, 35], [
            $evenement->new_value['eligibility']['age']['min'],
            $evenement->new_value['eligibility']['age']['max'],
        ]);
    }

    /**
     * Un enregistrement qui ne change aucun critère n'est pas une décision : le
     * journal d'audit sert à retrouver *quand les règles ont changé*, et des
     * lignes sans contenu noieraient celles qui en ont.
     */
    public function test_un_enregistrement_sans_changement_n_ecrit_pas_au_journal(): void
    {
        $campagne = $this->campagne();
        $administrateur = $this->admin();

        $this->actingAs($administrateur)->put($this->url($campagne), $this->formulaire())->assertRedirect();
        $this->actingAs($administrateur)->put($this->url($campagne), $this->formulaire())->assertRedirect();

        $this->assertSame(1, AuditEvent::query()->where('action', 'CAMPAIGN_ELIGIBILITY_UPDATED')->count());
    }

    // — De l'administration au candidat ————————————————————————————

    /**
     * Le parcours complet, pour les trois verdicts que ces critères produisent.
     *
     * Chacun passe par les vraies routes des deux espaces : l'administrateur
     * publie, le candidat répond, et c'est le serveur qui conclut. Aucune
     * écriture directe dans `settings`, sans quoi le test vérifierait le moteur
     * plutôt que l'écran d'administration.
     */
    public function test_les_criteres_publies_rendent_un_candidat_eligible(): void
    {
        $campagne = Campaign::factory()->create();
        $this->publier($campagne, $this->formulaire([
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
        ]));

        $dossier = $this->dossier($campagne, $this->reponsesConformes());

        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->verdict($dossier));
    }

    public function test_un_critere_publie_peut_rendre_un_candidat_ineligible(): void
    {
        $campagne = Campaign::factory()->create();
        $this->publier($campagne, $this->formulaire([
            // Une seule zone ouverte, et ce n'est pas celle du candidat.
            'regions' => [NigerRegion::AGADEZ->value],
        ]));

        $candidat = User::factory()->create();
        $dossier = $this->dossier($campagne, $this->reponsesConformes(), $candidat);

        $this->assertSame(EligibilityOutcome::INELIGIBLE, $this->verdict($dossier));

        // Et la conséquence visible pour le candidat : la suite du formulaire
        // reste fermée (ADR-007), y compris en tapant l'URL — il est renvoyé sur
        // l'étape 1, où le motif lui est expliqué.
        //
        // Les deux sections postérieures développées sont éprouvées : « Profil »,
        // étape suivante du parcours ouvert, et « Défi », développée mais située
        // derrière l'étape 3 non développée (ADR-009). La barrière d'éligibilité
        // ne dépend pas du parcours : elle ferme tout ce qui suit l'étape 1.
        foreach (['profile', 'challenge'] as $section) {
            $this->actingAs($candidat)
                ->get("/candidate/application/{$dossier->getKey()}/{$section}")
                ->assertRedirect("/candidate/application/{$dossier->getKey()}/eligibility");
        }

        // Et une sauvegarde forcée sur une section fermée n'écrit rien.
        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/profile", [
                ProfileSection::BIRTH_PLACE => 'Niamey',
            ])
            ->assertForbidden();

        $this->actingAs($candidat)
            ->patchJson("/candidate/application/{$dossier->getKey()}/challenge", ['main_challenge' => 'Tentative'])
            ->assertForbidden();
    }

    public function test_un_critere_non_publie_laisse_le_candidat_sous_reserve(): void
    {
        $campagne = Campaign::factory()->create();
        // Quatre critères sur cinq : les zones restent non publiées.
        $this->publier($campagne, $this->formulaire(['regions' => []]));

        $dossier = $this->dossier($campagne, $this->reponsesConformes());

        $this->assertSame(EligibilityOutcome::TO_CONFIRM, $this->verdict($dossier));
    }

    /**
     * Republier des critères change le verdict des dossiers déjà commencés :
     * le résultat est dérivé, jamais figé en base (ADR-007). C'est voulu, et
     * c'est ce qui rend l'écran d'administration utile après l'ouverture.
     */
    public function test_republier_un_critere_change_le_verdict_d_un_dossier_existant(): void
    {
        $campagne = Campaign::factory()->create();
        $this->publier($campagne, $this->formulaire([
            'regions' => array_map(static fn (NigerRegion $r): string => $r->value, NigerRegion::cases()),
        ]));

        $dossier = $this->dossier($campagne, $this->reponsesConformes());
        $this->assertSame(EligibilityOutcome::ELIGIBLE, $this->verdict($dossier));

        $this->publier($campagne, $this->formulaire(['regions' => [NigerRegion::AGADEZ->value]]));

        $this->assertSame(EligibilityOutcome::INELIGIBLE, $this->verdict($dossier));
    }

    private function publier(Campaign $campagne, array $formulaire): void
    {
        $this->actingAs($this->admin())
            ->put($this->url($campagne), $formulaire)
            ->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function reponsesConformes(array $remplacements = []): array
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

    /** Dépose un dossier par le vrai parcours candidat, puis enregistre ses réponses. */
    private function dossier(Campaign $campagne, array $reponses, ?User $candidat = null): Application
    {
        $candidat ??= User::factory()->create();

        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        $application = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();

        $this->actingAs($candidat)
            ->patch("/candidate/application/{$application->getKey()}/eligibility", $reponses)
            ->assertSessionHasNoErrors();

        return $application->fresh();
    }

    private function verdict(Application $dossier): EligibilityOutcome
    {
        return app(EvaluateEligibility::class)->forApplication($dossier->fresh())->outcome;
    }
}
