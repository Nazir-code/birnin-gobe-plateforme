<?php

namespace App\Domain\Verification;

use App\Domain\Application\ApplicationStateMachine;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\User;
use App\Models\VerificationCheck;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Enregistrement de la grille d'admissibilité — §10.1, « le vérificateur coche
 * chaque contrôle, ajoute une observation ».
 *
 * Sauvegarder la grille **est** la prise en charge du dossier : un dossier
 * `SUBMITTED` sur lequel quelqu'un coche passe à `PENDING_REVIEW`, le « en
 * contrôle » du §10.3. C'est délibérément le premier geste de contrôle qui
 * marque la prise en charge, et non l'ouverture de l'écran — consulter n'est
 * pas travailler, et un statut qui bougeait à la simple lecture ferait
 * apparaître comme « en contrôle » tout dossier qu'un gestionnaire a
 * entrouvert.
 *
 * La grille reste révisable tant qu'aucune décision n'est prise. Après
 * décision, elle est close : `assertModifiable()` refuse, parce qu'une grille
 * qu'on retouche après coup ne correspondrait plus à la décision qu'elle a
 * fondée.
 *
 * Ce cas d'usage **ne décide de rien**. Cocher « doublon confirmé » ne rend pas
 * un dossier irrecevable ; c'est `DecideAdmissibility` qui tranche, et une
 * personne qui la déclenche.
 */
final readonly class SaveVerificationChecks
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private AuditWriter $audit,
    ) {}

    /**
     * @param  array<string, array{outcome: VerificationOutcome, observation: ?string}>  $grille
     *                                                                                            Indexée par la valeur du contrôle. Les contrôles absents sont laissés
     *                                                                                            tels quels : une grille se remplit en plusieurs fois.
     *
     * @throws DomainException
     */
    public function handle(Application $application, array $grille, User $actor): Application
    {
        return DB::transaction(function () use ($application, $grille, $actor): Application {
            $verrouille = Application::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertModifiable($verrouille);

            $enregistres = [];

            foreach ($grille as $code => $saisie) {
                $controle = VerificationControl::tryFrom($code);

                if ($controle === null) {
                    throw new DomainException("VERIFICATION_UNKNOWN_CONTROL: {$code}");
                }

                $this->assertVerdictRecevable($controle, $saisie['outcome'], $saisie['observation']);

                VerificationCheck::query()->updateOrCreate(
                    [
                        'application_id' => $verrouille->getKey(),
                        'control' => $controle->value,
                    ],
                    [
                        'outcome' => $saisie['outcome']->value,
                        'observation' => $this->texte($saisie['observation']),
                        'actor_id' => $actor->getKey(),
                    ],
                );

                $enregistres[$controle->value] = $saisie['outcome']->value;
            }

            $statutInitial = $verrouille->status;

            // Premier geste de contrôle : le dossier passe « en contrôle ».
            if ($verrouille->status === ApplicationStatus::SUBMITTED) {
                $this->stateMachine->assertCanTransition($verrouille->status, ApplicationStatus::PENDING_REVIEW);
                $verrouille->forceFill(['status' => ApplicationStatus::PENDING_REVIEW->value])->save();
            }

            $this->audit->write(
                actorId: $actor->getKey(),
                action: 'VERIFICATION_CHECKS_RECORDED',
                targetType: Application::class,
                targetId: (string) $verrouille->getKey(),
                oldValue: ['status' => $statutInitial->value],
                newValue: [
                    'status' => $verrouille->status->value,
                    'checks' => $enregistres,
                ],
                reason: null,
            );

            return $verrouille;
        });
    }

    /**
     * Un dossier décidé n'est plus une grille de travail.
     *
     * @throws DomainException
     */
    private function assertModifiable(Application $application): void
    {
        if (! in_array($application->status, VerificationQueueQuery::statutsOuverts(), true)) {
            throw new DomainException("VERIFICATION_CLOSED: {$application->status->value}");
        }
    }

    /**
     * Deux refus, et ils disent la même chose sous deux angles : un contrôle ne
     * se coche pas avec le verdict d'un autre, et un verdict qui n'est pas
     * « le contrôle est passé » ne se coche pas sans être expliqué.
     *
     * @throws DomainException
     */
    private function assertVerdictRecevable(VerificationControl $controle, VerificationOutcome $verdict, ?string $observation): void
    {
        if (! $controle->accepts($verdict)) {
            throw new DomainException("VERIFICATION_OUTCOME_MISMATCH: {$controle->value}/{$verdict->value}");
        }

        if ($verdict->requiresObservation() && $this->texte($observation) === null) {
            throw new DomainException("VERIFICATION_OBSERVATION_REQUIRED: {$controle->value}");
        }
    }

    /** Une observation vide est absente, jamais une chaîne vide en base. */
    private function texte(?string $valeur): ?string
    {
        $propre = trim((string) $valeur);

        return $propre === '' ? null : $propre;
    }
}
