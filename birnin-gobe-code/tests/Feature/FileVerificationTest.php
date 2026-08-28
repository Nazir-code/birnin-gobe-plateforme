<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationOutcome;
use App\Models\Application;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use App\Models\VerificationCheck;
use App\Models\VerificationDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le contrôle d'admissibilité — §10 du cahier des charges.
 *
 * Ce que cette suite protège, dans l'ordre :
 *
 * 1. **L'espace reste étanche.** Candidat, évaluateur et jury n'entrent pas.
 *
 * 2. **Une file est une file.** Elle ne contient que les dossiers qui appellent
 *    un geste, et le plus ancien dépôt vient en tête. Un brouillon n'y entre
 *    pas : il appartient encore au candidat.
 *
 * 3. **La garantie du §10.3 tient.** Un signalement automatique — doublon
 *    probable, alerte d'intégrité — ne peut pas fonder une exclusion. C'est le
 *    test le plus important du fichier : il porte sur une phrase du cahier des
 *    charges qui protège des candidats.
 *
 * 4. **Aucune décision sans grille complète, ni sans motif réel.** Les sept
 *    contrôles du §10.2 sont exigés, et un rejet ne peut invoquer qu'un
 *    contrôle réellement bloquant.
 *
 * 5. **Le dossier n'est jamais réécrit.** Le contrôle ajoute un verdict à côté
 *    du dossier et fait bouger son statut ; les réponses du candidat restent
 *    intactes.
 */
final class FileVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Compteur des numéros de dépôt : déterministe, remis à zéro par test. */
    private int $numero = 0;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create(['name' => 'Aïcha Diallo']);
    }

    /**
     * Un dossier déposé, tel que la file doit le voir.
     *
     * Numéro déterministe, comme dans `CampaignFactory` : un test qui échoue
     * doit échouer identiquement à chaque exécution. Faker n'est pas utilisé
     * dans ce dépôt.
     */
    private function dossierDepose(?Campaign $campagne = null, ?string $numero = null, ?string $depose = null): Application
    {
        $campagne ??= Campaign::factory()->create();

        return Application::factory()
            ->for($campagne, 'campaign')
            ->status(ApplicationStatus::SUBMITTED)
            ->create([
                'submission_number' => $numero ?? sprintf('BG-%06d', ++$this->numero),
                'submitted_at' => $depose ?? now()->subDay(),
            ]);
    }

    /**
     * Coche la grille par le vrai chemin — la route, jamais l'insertion directe.
     *
     * @param  array<string, string>  $verdicts  Indexé par contrôle.
     */
    private function cocher(User $admin, Application $dossier, array $verdicts, string $observation = 'Contrôle effectué.'): void
    {
        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/checks", [
                'checks' => array_map(
                    static fn (string $controle, string $verdict): array => [
                        'control' => $controle,
                        'outcome' => $verdict,
                        'observation' => $observation,
                    ],
                    array_keys($verdicts),
                    array_values($verdicts),
                ),
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Une grille entièrement conforme : les sept contrôles au vert.
     *
     * @return array<string, string>
     */
    private function grilleConforme(): array
    {
        return [
            VerificationControl::DEPOSIT_DEADLINE->value => VerificationOutcome::ON_TIME->value,
            VerificationControl::PROFILE->value => VerificationOutcome::PROFILE_ELIGIBLE->value,
            VerificationControl::COMPLETENESS->value => VerificationOutcome::FILE_COMPLETE->value,
            VerificationControl::DOCUMENTS->value => VerificationOutcome::DOCUMENTS_VALID->value,
            VerificationControl::THEME->value => VerificationOutcome::THEME_ADMISSIBLE->value,
            VerificationControl::UNIQUENESS->value => VerificationOutcome::UNIQUE->value,
            VerificationControl::INTEGRITY->value => VerificationOutcome::NO_ALERT->value,
        ];
    }

    // — L'espace reste étanche ————————————————————————————————————

    public function test_un_visiteur_est_renvoye_vers_l_acces_interne(): void
    {
        $this->get('/admin/verification')->assertRedirect('/admin/login');
    }

    #[DataProvider('rolesSansAcces')]
    public function test_les_autres_roles_n_entrent_pas(string $role): void
    {
        $utilisateur = User::factory()->role(UserRole::from($role))->create();

        $this->actingAs($utilisateur)->get('/admin/verification')->assertForbidden();
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

    // — Une file est une file —————————————————————————————————————

    public function test_un_brouillon_n_entre_pas_dans_la_file(): void
    {
        $admin = $this->admin();

        // Une seule campagne : `campaigns_une_seule_ouverte` interdit d'en avoir
        // deux ouvertes, et laisser la fabrique en créer une par dossier ferait
        // échouer le test sur une contrainte qui n'est pas son sujet.
        $campagne = Campaign::factory()->create();

        Application::factory()->for($campagne, 'campaign')->create(); // DRAFT par défaut.
        $depose = $this->dossierDepose($campagne);

        $this->actingAs($admin)
            ->get('/admin/verification')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $depose->getKey()));
    }

    public function test_le_plus_ancien_depot_vient_en_tete(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $recent = $this->dossierDepose($campagne, 'BG-000002', now()->subDay()->toDateTimeString());
        $ancien = $this->dossierDepose($campagne, 'BG-000001', now()->subDays(10)->toDateTimeString());

        $this->actingAs($admin)
            ->get('/admin/verification')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('applications.0.id', $ancien->getKey())
                ->where('applications.1.id', $recent->getKey()));
    }

    public function test_un_dossier_decide_sort_de_la_file_mais_reste_consultable(): void
    {
        $admin = $this->admin();
        $decide = $this->dossierDepose();
        $decide->forceFill(['status' => ApplicationStatus::ADMISSIBLE->value])->save();

        $this->actingAs($admin)
            ->get('/admin/verification')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('applications', 0));

        $this->actingAs($admin)
            ->get('/admin/verification?scope=traites')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('applications', 1)
                ->where('applications.0.id', $decide->getKey()));
    }

    public function test_la_file_vide_se_distingue_d_un_filtre_sans_resultat(): void
    {
        $admin = $this->admin();
        $this->dossierDepose(numero: 'BG-000042');

        // Un filtre trop étroit : la file n'est pas vide pour autant.
        $this->actingAs($admin)
            ->get('/admin/verification?search=introuvable')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('applications', 0)
                ->where('totalWaiting', 1));
    }

    public function test_un_filtre_illisible_ouvre_la_file_sans_lui(): void
    {
        $admin = $this->admin();
        $this->dossierDepose();

        $this->actingAs($admin)
            ->get('/admin/verification?status=PAS_UN_STATUT&scope=n_importe_quoi&sort=au_hasard')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('applications', 1)
                ->where('filters.status', '')
                ->where('filters.scope', 'ouverts')
                ->where('filters.sort', 'attente'));
    }

    // — La grille ————————————————————————————————————————————————

    public function test_cocher_la_grille_prend_le_dossier_en_charge(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->cocher($admin, $dossier, [
            VerificationControl::DEPOSIT_DEADLINE->value => VerificationOutcome::ON_TIME->value,
        ]);

        $this->assertSame(ApplicationStatus::PENDING_REVIEW, $dossier->fresh()->status);
        $this->assertDatabaseHas('verification_checks', [
            'application_id' => $dossier->getKey(),
            'control' => VerificationControl::DEPOSIT_DEADLINE->value,
            'outcome' => VerificationOutcome::ON_TIME->value,
        ]);
    }

    public function test_une_grille_se_corrige_sans_empiler_les_lignes(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->cocher($admin, $dossier, [
            VerificationControl::PROFILE->value => VerificationOutcome::PROFILE_TO_CONFIRM->value,
        ]);
        $this->cocher($admin, $dossier, [
            VerificationControl::PROFILE->value => VerificationOutcome::PROFILE_ELIGIBLE->value,
        ]);

        $this->assertSame(1, VerificationCheck::query()->where('application_id', $dossier->getKey())->count());
        $this->assertSame(
            VerificationOutcome::PROFILE_ELIGIBLE,
            VerificationCheck::query()->where('application_id', $dossier->getKey())->firstOrFail()->outcome,
        );
    }

    /** Un verdict d'une autre famille n'entre pas : la liste blanche est le contrôle lui-même. */
    public function test_un_verdict_etranger_au_controle_est_refuse(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/checks", [
                'checks' => [[
                    'control' => VerificationControl::DEPOSIT_DEADLINE->value,
                    'outcome' => VerificationOutcome::DUPLICATE_CONFIRMED->value,
                    'observation' => 'Tentative.',
                ]],
            ])
            ->assertSessionHasErrors('checks.0.outcome');

        $this->assertDatabaseCount('verification_checks', 0);
    }

    public function test_un_verdict_non_conforme_exige_une_observation(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/checks", [
                'checks' => [[
                    'control' => VerificationControl::DOCUMENTS->value,
                    'outcome' => VerificationOutcome::DOCUMENTS_UNREADABLE->value,
                    'observation' => '   ',
                ]],
            ])
            ->assertSessionHasErrors('checks.0.observation');

        $this->assertDatabaseCount('verification_checks', 0);
    }

    // — La garantie du §10.3 ——————————————————————————————————————

    /**
     * Le test central de ce fichier.
     *
     * Un doublon *probable* et une *alerte* d'intégrité sont des signalements.
     * Le §10.3 interdit qu'ils excluent à eux seuls : la décision d'irrecevabilité
     * doit donc être refusée tant qu'aucun contrôle ne porte un verdict bloquant.
     */
    public function test_un_signalement_seul_ne_peut_pas_fonder_une_exclusion(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::UNIQUENESS->value] = VerificationOutcome::DUPLICATE_SUSPECTED->value;
        $grille[VerificationControl::INTEGRITY->value] = VerificationOutcome::ALERT->value;

        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'INADMISSIBLE',
                'primary_reason' => VerificationControl::UNIQUENESS->value,
                'candidate_message' => 'Votre dossier n’est pas retenu.',
            ])
            ->assertSessionHasErrors('decision');

        $this->assertSame(ApplicationStatus::PENDING_REVIEW, $dossier->fresh()->status);
        $this->assertDatabaseCount('verification_decisions', 0);
    }

    /** Le doublon *confirmé*, lui, est une constatation humaine : il fonde le rejet. */
    public function test_un_doublon_confirme_fonde_le_rejet(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::UNIQUENESS->value] = VerificationOutcome::DUPLICATE_CONFIRMED->value;

        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'INADMISSIBLE',
                'primary_reason' => VerificationControl::UNIQUENESS->value,
                'internal_note' => 'Même projet déposé deux fois, vérifié auprès du candidat.',
                'candidate_message' => 'Votre dossier fait doublon avec un autre dépôt.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ApplicationStatus::INADMISSIBLE, $dossier->fresh()->status);
    }

    // — Aucune décision sans grille complète, ni sans motif réel ——

    public function test_une_grille_incomplete_bloque_la_decision(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->cocher($admin, $dossier, [
            VerificationControl::DEPOSIT_DEADLINE->value => VerificationOutcome::ON_TIME->value,
        ]);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", ['decision' => 'ADMISSIBLE'])
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseCount('verification_decisions', 0);
    }

    public function test_un_controle_bloquant_interdit_de_declarer_recevable(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::DOCUMENTS->value] = VerificationOutcome::DOCUMENTS_EXPIRED->value;

        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", ['decision' => 'ADMISSIBLE'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(ApplicationStatus::PENDING_REVIEW, $dossier->fresh()->status);
    }

    public function test_un_rejet_sans_motif_principal_est_refuse(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::THEME->value] = VerificationOutcome::THEME_REJECTED->value;
        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'INADMISSIBLE',
                'candidate_message' => 'Votre dossier n’est pas retenu.',
            ])
            ->assertSessionHasErrors('primary_reason');
    }

    public function test_un_motif_de_rejet_doit_designer_un_controle_bloquant(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::THEME->value] = VerificationOutcome::THEME_REJECTED->value;
        $this->cocher($admin, $dossier, $grille);

        // « Profil » est au vert : il ne peut pas motiver le rejet.
        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'INADMISSIBLE',
                'primary_reason' => VerificationControl::PROFILE->value,
                'candidate_message' => 'Votre dossier n’est pas retenu.',
            ])
            ->assertSessionHasErrors('decision');

        $this->assertDatabaseCount('verification_decisions', 0);
    }

    // — La décision ——————————————————————————————————————————————

    public function test_une_recevabilite_ecrit_son_statut_sa_version_et_son_audit(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->cocher($admin, $dossier, $this->grilleConforme());

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", ['decision' => 'ADMISSIBLE'])
            ->assertRedirect('/admin/verification');

        $this->assertSame(ApplicationStatus::ADMISSIBLE, $dossier->fresh()->status);

        $version = VerificationDecision::query()->where('application_id', $dossier->getKey())->firstOrFail();
        $this->assertSame(ApplicationStatus::PENDING_REVIEW, $version->previous_status);
        $this->assertSame(ApplicationStatus::ADMISSIBLE, $version->new_status);
        $this->assertSame($admin->getKey(), $version->actor_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ADMISSIBILITY_DECIDED',
            'target_id' => (string) $dossier->getKey(),
            'actor_id' => $admin->getKey(),
        ]);
    }

    public function test_une_clarification_exige_une_date_limite_non_passee(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::COMPLETENESS->value] = VerificationOutcome::FILE_CLARIFICATION->value;
        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'CLARIFICATION',
                'candidate_message' => 'Merci de fournir votre justificatif d’existence légale.',
                'respond_by' => now()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('respond_by');

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", [
                'decision' => 'CLARIFICATION',
                'candidate_message' => 'Merci de fournir votre justificatif d’existence légale.',
                'respond_by' => now()->addDays(7)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(ApplicationStatus::CLARIFICATION_REQUESTED, $dossier->fresh()->status);
    }

    /**
     * Le §10.3 sépare le message au candidat de l'observation interne.
     *
     * Le test vérifie que les deux textes sont bien conservés à part : c'est ce
     * qui permet de motiver en interne sans divulguer au candidat.
     */
    public function test_les_deux_textes_d_une_decision_restent_distincts(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::PROFILE->value] = VerificationOutcome::PROFILE_INELIGIBLE->value;
        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)->post("/admin/verification/{$dossier->getKey()}/decision", [
            'decision' => 'INADMISSIBLE',
            'primary_reason' => VerificationControl::PROFILE->value,
            'internal_note' => 'Pièce d’identité contradictoire, signalée au référent.',
            'candidate_message' => 'Vous ne remplissez pas les conditions d’âge de cette édition.',
        ])->assertSessionHasNoErrors();

        $version = VerificationDecision::query()->where('application_id', $dossier->getKey())->firstOrFail();

        $this->assertSame('Pièce d’identité contradictoire, signalée au référent.', $version->internal_note);
        $this->assertSame('Vous ne remplissez pas les conditions d’âge de cette édition.', $version->candidate_message);
        $this->assertNotSame($version->internal_note, $version->candidate_message);
    }

    /** Le journal porte la décision et son motif, jamais la correspondance. */
    public function test_le_journal_ne_recopie_pas_les_textes_de_la_decision(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $grille = $this->grilleConforme();
        $grille[VerificationControl::PROFILE->value] = VerificationOutcome::PROFILE_INELIGIBLE->value;
        $this->cocher($admin, $dossier, $grille);

        $this->actingAs($admin)->post("/admin/verification/{$dossier->getKey()}/decision", [
            'decision' => 'INADMISSIBLE',
            'primary_reason' => VerificationControl::PROFILE->value,
            'internal_note' => 'SECRET INTERNE',
            'candidate_message' => 'MESSAGE CANDIDAT',
        ])->assertSessionHasNoErrors();

        $evenement = AuditEvent::query()->where('action', 'ADMISSIBILITY_DECIDED')->firstOrFail();
        $serialise = json_encode($evenement->new_value, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SECRET INTERNE', (string) $serialise);
        $this->assertStringNotContainsString('MESSAGE CANDIDAT', (string) $serialise);
    }

    public function test_un_dossier_decide_n_accepte_plus_de_coche(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->cocher($admin, $dossier, $this->grilleConforme());
        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", ['decision' => 'ADMISSIBLE'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/checks", [
                'checks' => [[
                    'control' => VerificationControl::PROFILE->value,
                    'outcome' => VerificationOutcome::PROFILE_INELIGIBLE->value,
                    'observation' => 'Après coup.',
                ]],
            ])
            ->assertSessionHasErrors('checks');
    }

    // — Le dossier n'est jamais réécrit ————————————————————————————

    public function test_le_controle_ne_touche_pas_aux_reponses_du_candidat(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->create();

        $dossier = Application::factory()
            ->for($campagne, 'campaign')
            ->status(ApplicationStatus::SUBMITTED)
            ->withSection(ApplicationSection::PROFILE, ['phone_primary' => '+22790000001'])
            ->create(['submission_number' => 'BG-000777', 'submitted_at' => now()->subDay()]);

        $avant = $dossier->sectionAnswers(ApplicationSection::PROFILE)?->answers;

        $this->cocher($admin, $dossier, $this->grilleConforme());
        $this->actingAs($admin)
            ->post("/admin/verification/{$dossier->getKey()}/decision", ['decision' => 'ADMISSIBLE'])
            ->assertSessionHasNoErrors();

        $this->assertSame($avant, $dossier->fresh()->sectionAnswers(ApplicationSection::PROFILE)?->answers);
    }

    // — L'écran de contrôle ———————————————————————————————————————

    public function test_l_ecran_rend_les_sept_controles_meme_vides(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $this->actingAs($admin)
            ->get("/admin/verification/{$dossier->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('matrix', 7)
                ->has('checks', 7)
                ->where('checks.0.outcome', null)
                ->where('editable', true));
    }

    /** Un dépôt après la clôture doit être signalé, sans rien décider. */
    public function test_un_depot_hors_delai_est_signale(): void
    {
        $admin = $this->admin();
        $campagne = Campaign::factory()->closed()->create();

        $dossier = Application::factory()
            ->for($campagne, 'campaign')
            ->status(ApplicationStatus::SUBMITTED)
            ->create(['submission_number' => 'BG-000999', 'submitted_at' => now()]);

        $this->actingAs($admin)
            ->get("/admin/verification/{$dossier->getKey()}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('findings.0.control', VerificationControl::DEPOSIT_DEADLINE->value));

        // Le signalement n'a rien coché : la grille reste vierge.
        $this->assertDatabaseCount('verification_checks', 0);
    }

    public function test_la_consultation_n_ecrit_aucun_evenement(): void
    {
        $admin = $this->admin();
        $dossier = $this->dossierDepose();

        $avant = AuditEvent::query()->count();

        $this->actingAs($admin)->get('/admin/verification')->assertOk();
        $this->actingAs($admin)->get("/admin/verification/{$dossier->getKey()}")->assertOk();

        $this->assertSame($avant, AuditEvent::query()->count());
    }
}
