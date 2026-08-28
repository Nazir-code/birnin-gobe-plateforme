<?php

namespace App\Domain\Evaluation;

use App\Domain\Application\ApplicationStateMachine;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Audit\AuditWriter;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\EvaluationAssignment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Affectation de dossiers à un évaluateur — §11.1.
 *
 * Le geste est **en lot** parce que c'est ainsi qu'on affecte : un responsable
 * répartit une vingtaine de dossiers, il ne clique pas vingt fois. Tout part
 * dans une transaction — un lot à moitié affecté laisserait une charge fausse
 * sur l'écran qui sert justement à l'équilibrer.
 *
 * Quatre refus, et chacun protège une phrase du cahier des charges :
 *
 * 1. **Le destinataire est un évaluateur.** Affecter un dossier à un
 *    administrateur ou à un candidat n'a pas de sens, et rien dans la requête
 *    HTTP ne le garantit.
 * 2. **Le dossier est recevable.** Le §11 vient après le §10 ; un dossier dont
 *    l'admissibilité n'est pas tranchée n'entre pas en évaluation.
 * 3. **Un conflit déclaré est définitif.** Le §11.1 veut que l'affectation
 *    « tienne compte des conflits déclarés » : reproposer à quelqu'un un
 *    dossier dont il s'est récusé viderait la récusation de son sens.
 * 4. **Une affectation en vigueur ne se double pas.** L'index unique partiel
 *    de la base le garantit ; le refus applicatif existe pour rendre un message
 *    plutôt qu'une erreur SQL.
 *
 * Le premier dossier affecté fait passer la candidature de `ADMISSIBLE` à
 * `IN_EVALUATION` — le statut décrit ce qui lui arrive, et un dossier confié à
 * quelqu'un est en évaluation. La transition passe par la machine à états,
 * jamais par une écriture directe.
 *
 * Ce qui n'est **pas** ici : la notification de l'évaluateur (§8.3, « Affectation
 * — Évaluateur — Email »). Comme partout, elle appartient à la file d'attente
 * après le `commit`.
 */
final readonly class AssignApplications
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private AuditWriter $audit,
    ) {}

    /**
     * @param  list<int>  $applicationIds
     * @return int Nombre d'affectations réellement créées.
     *
     * @throws DomainException
     */
    public function handle(array $applicationIds, User $evaluateur, User $actor): int
    {
        if ($evaluateur->role !== UserRole::EVALUATOR) {
            throw new DomainException('ASSIGNMENT_NOT_AN_EVALUATOR');
        }

        $identifiants = array_values(array_unique(array_filter($applicationIds)));

        if ($identifiants === []) {
            throw new DomainException('ASSIGNMENT_NO_APPLICATION');
        }

        return DB::transaction(function () use ($identifiants, $evaluateur, $actor): int {
            // Verrou sur tout le lot avant la moindre écriture : deux
            // responsables qui affectent en même temps ne doivent pas pouvoir
            // lire tous les deux « aucune affectation en vigueur ».
            $dossiers = Application::query()
                ->whereKey($identifiants)
                ->lockForUpdate()
                ->get();

            if ($dossiers->count() !== count($identifiants)) {
                throw new DomainException('ASSIGNMENT_APPLICATION_MISSING');
            }

            $crees = 0;

            foreach ($dossiers as $dossier) {
                $this->assertAffectable($dossier, $evaluateur);

                EvaluationAssignment::query()->create([
                    'application_id' => $dossier->getKey(),
                    'evaluator_id' => $evaluateur->getKey(),
                    'status' => AssignmentStatus::ASSIGNED->value,
                    'assigned_by' => $actor->getKey(),
                    'assigned_at' => now(),
                ]);

                $crees++;

                $statutInitial = $dossier->status;
                $this->ouvrirLEvaluation($dossier);

                // Un événement par dossier, visant le dossier — et non un seul
                // événement visant l'évaluateur. Le §13.3 veut que le journal
                // couvre les affectations, et la question qu'on lui posera est
                // « à qui ce dossier a-t-il été confié, et quand ». Un
                // événement de lot, rangé sous l'évaluateur, ne répondrait pas :
                // le filtre par dossier du journal ne le trouverait jamais.
                $this->audit->write(
                    actorId: $actor->getKey(),
                    action: 'EVALUATION_ASSIGNED',
                    targetType: Application::class,
                    targetId: (string) $dossier->getKey(),
                    oldValue: ['status' => $statutInitial->value],
                    newValue: [
                        'status' => $dossier->status->value,
                        'evaluator' => $evaluateur->getKey(),
                    ],
                    reason: null,
                );
            }

            return $crees;
        });
    }

    /**
     * @throws DomainException
     */
    private function assertAffectable(Application $dossier, User $evaluateur): void
    {
        if (! in_array($dossier->status, AssignmentBoardQuery::statutsAffectables(), true)) {
            throw new DomainException("ASSIGNMENT_NOT_ADMISSIBLE: {$dossier->getKey()}");
        }

        $existantes = EvaluationAssignment::query()
            ->where('application_id', $dossier->getKey())
            ->where('evaluator_id', $evaluateur->getKey())
            ->get();

        foreach ($existantes as $existante) {
            if ($existante->status === AssignmentStatus::CONFLICT) {
                throw new DomainException("ASSIGNMENT_CONFLICT_DECLARED: {$dossier->getKey()}");
            }

            if ($existante->released_at === null) {
                throw new DomainException("ASSIGNMENT_ALREADY_ASSIGNED: {$dossier->getKey()}");
            }
        }
    }

    /**
     * Le premier évaluateur ouvre l'évaluation du dossier.
     *
     * Idempotent : un dossier déjà `IN_EVALUATION` n'est pas retransitionné, et
     * la machine à états n'est consultée que lorsqu'un changement est
     * réellement demandé.
     */
    private function ouvrirLEvaluation(Application $dossier): void
    {
        if ($dossier->status !== ApplicationStatus::ADMISSIBLE) {
            return;
        }

        $this->stateMachine->assertCanTransition($dossier->status, ApplicationStatus::IN_EVALUATION);
        $dossier->forceFill(['status' => ApplicationStatus::IN_EVALUATION->value])->save();
    }
}
