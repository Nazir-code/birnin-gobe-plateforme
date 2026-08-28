<?php

namespace App\Domain\Verification;

use App\Domain\Application\ApplicationStateMachine;
use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\User;
use App\Models\VerificationCheck;
use App\Models\VerificationDecision;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * La décision d'admissibilité — §10.3.
 *
 * Le seul endroit du code où un dossier devient recevable ou irrecevable. Tout
 * y part dans la même transaction : le statut, la version de décision et le
 * journal. Un statut changé sans décision enregistrée laisserait une
 * irrecevabilité sans motif ; une décision enregistrée sans statut laisserait
 * un dossier recevable que la file continue de proposer.
 *
 * Quatre règles, et chacune vient d'une phrase du cahier des charges :
 *
 * 1. **La grille doit être complète.** Le §10.2 s'intitule « matrice minimale
 *    d'admissibilité » : décider en ayant laissé un contrôle vide, c'est
 *    décider sans avoir fait le minimum. Les sept contrôles sont donc exigés.
 *
 * 2. **On ne rejette que sur un motif bloquant réellement constaté.** Le motif
 *    principal est un contrôle, et ce contrôle doit porter un verdict de
 *    gravité `BLOCKING`. C'est la garantie du §10.3 rendue exécutable : un
 *    doublon *probable* ou une *alerte* d'intégrité se rangent en `ATTENTION`,
 *    et aucun chemin ne permet de fonder une exclusion dessus.
 *
 * 3. **On ne déclare pas recevable ce qui bloque.** L'inverse de la précédente,
 *    et elle protège de l'erreur de manipulation plus que de la mauvaise foi :
 *    un contrôle bloquant qui subsiste doit être levé — reclassé en attention
 *    par une observation — avant que la recevabilité soit prononcée.
 *
 * 4. **Une clarification dit quoi fournir et pour quand.** Le §10.3 exige la
 *    date limite ; le message au candidat est distinct de l'observation
 *    interne, « afin d'éviter la divulgation d'informations sensibles ».
 *
 * Ce qui n'est **pas** ici : la notification du candidat. Elle appartient à la
 * file d'attente, après le `commit` — envoyer un refus depuis la transaction
 * enverrait un courriel pour une décision qui peut encore être annulée. C'est
 * le même partage que dans `SubmitApplication`.
 */
final readonly class DecideAdmissibility
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private AuditWriter $audit,
    ) {}

    /**
     * @throws DomainException
     */
    public function handle(
        Application $application,
        AdmissibilityDecision $decision,
        User $actor,
        ?VerificationControl $primaryReason = null,
        ?VerificationControl $secondaryReason = null,
        ?string $internalNote = null,
        ?string $candidateMessage = null,
        ?string $respondBy = null,
    ): Application {
        return DB::transaction(function () use (
            $application,
            $decision,
            $actor,
            $primaryReason,
            $secondaryReason,
            $internalNote,
            $candidateMessage,
            $respondBy,
        ): Application {
            $verrouille = Application::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $grille = $this->grille($verrouille);

            $this->assertGrilleComplete($grille);
            $this->assertMotifsCoherents($decision, $grille, $primaryReason, $secondaryReason);
            $this->assertTextesFournis($decision, $candidateMessage, $respondBy);

            $statutInitial = $verrouille->status;
            $statutVise = $decision->targetStatus();

            // La machine à états reste seule juge du droit d'aller là : un
            // dossier déjà décidé, ou encore au dépôt, n'y passe pas.
            $this->stateMachine->assertCanTransition($statutInitial, $statutVise);

            $verrouille->forceFill(['status' => $statutVise->value])->save();

            VerificationDecision::query()->create([
                'application_id' => $verrouille->getKey(),
                'decision' => $decision->value,
                'primary_reason' => $primaryReason?->value,
                'secondary_reason' => $secondaryReason?->value,
                'internal_note' => $this->texte($internalNote),
                'candidate_message' => $this->texte($candidateMessage),
                'respond_by' => $decision->requiresRespondBy() ? $respondBy : null,
                'previous_status' => $statutInitial->value,
                'new_status' => $statutVise->value,
                'actor_id' => $actor->getKey(),
                'created_at' => now(),
            ]);

            // Le journal porte la décision et son motif — jamais le message au
            // candidat ni l'observation interne. Le §13.3 en fait une trace de
            // décisions, pas un second exemplaire de la correspondance.
            $this->audit->write(
                actorId: $actor->getKey(),
                action: 'ADMISSIBILITY_DECIDED',
                targetType: Application::class,
                targetId: (string) $verrouille->getKey(),
                oldValue: ['status' => $statutInitial->value],
                newValue: [
                    'status' => $statutVise->value,
                    'decision' => $decision->value,
                    'primary_reason' => $primaryReason?->value,
                    'secondary_reason' => $secondaryReason?->value,
                ],
                reason: $primaryReason?->label(),
            );

            return $verrouille;
        });
    }

    /**
     * La grille telle qu'elle est en base, indexée par contrôle.
     *
     * Relue sous verrou plutôt que prise sur la relation déjà chargée : entre
     * l'affichage de l'écran et le clic, un autre vérificateur a pu cocher.
     * Ce qui a été montré n'engage pas la décision ; ce qui est vrai à
     * l'instant de l'écriture, si.
     *
     * @return array<string, VerificationOutcome>
     */
    private function grille(Application $application): array
    {
        $grille = [];

        foreach (VerificationCheck::query()->where('application_id', $application->getKey())->get() as $ligne) {
            $grille[$ligne->control->value] = $ligne->outcome;
        }

        return $grille;
    }

    /**
     * @param  array<string, VerificationOutcome>  $grille
     *
     * @throws DomainException
     */
    private function assertGrilleComplete(array $grille): void
    {
        $manquants = array_values(array_filter(
            VerificationControl::cases(),
            static fn (VerificationControl $controle): bool => ! isset($grille[$controle->value]),
        ));

        if ($manquants !== []) {
            $codes = implode(',', array_map(
                static fn (VerificationControl $controle): string => $controle->value,
                $manquants,
            ));

            throw new DomainException("VERIFICATION_GRID_INCOMPLETE: {$codes}");
        }
    }

    /**
     * @param  array<string, VerificationOutcome>  $grille
     *
     * @throws DomainException
     */
    private function assertMotifsCoherents(
        AdmissibilityDecision $decision,
        array $grille,
        ?VerificationControl $primaryReason,
        ?VerificationControl $secondaryReason,
    ): void {
        $bloquants = array_keys(array_filter(
            $grille,
            static fn (VerificationOutcome $verdict): bool => $verdict->severity() === VerificationSeverity::BLOCKING,
        ));

        if ($decision === AdmissibilityDecision::ADMISSIBLE && $bloquants !== []) {
            throw new DomainException('VERIFICATION_BLOCKING_REMAINS: '.implode(',', $bloquants));
        }

        if (! $decision->requiresPrimaryReason()) {
            return;
        }

        if ($primaryReason === null) {
            throw new DomainException('VERIFICATION_PRIMARY_REASON_REQUIRED');
        }

        foreach (array_filter([$primaryReason, $secondaryReason]) as $motif) {
            if (! in_array($motif->value, $bloquants, true)) {
                throw new DomainException("VERIFICATION_REASON_NOT_BLOCKING: {$motif->value}");
            }
        }

        if ($secondaryReason !== null && $secondaryReason === $primaryReason) {
            throw new DomainException('VERIFICATION_REASONS_IDENTICAL');
        }
    }

    /**
     * @throws DomainException
     */
    private function assertTextesFournis(AdmissibilityDecision $decision, ?string $candidateMessage, ?string $respondBy): void
    {
        if ($decision->requiresCandidateMessage() && $this->texte($candidateMessage) === null) {
            throw new DomainException('VERIFICATION_CANDIDATE_MESSAGE_REQUIRED');
        }

        if (! $decision->requiresRespondBy()) {
            return;
        }

        if ($respondBy === null) {
            throw new DomainException('VERIFICATION_RESPOND_BY_REQUIRED');
        }

        // Une date limite déjà passée n'ouvre aucun délai : elle ferme la porte
        // en donnant l'apparence de l'ouvrir.
        if (Carbon::parse($respondBy)->endOfDay()->isPast()) {
            throw new DomainException('VERIFICATION_RESPOND_BY_PAST');
        }
    }

    private function texte(?string $valeur): ?string
    {
        $propre = trim((string) $valeur);

        return $propre === '' ? null : $propre;
    }
}
