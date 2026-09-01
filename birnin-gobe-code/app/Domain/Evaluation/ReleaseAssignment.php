<?php

namespace App\Domain\Evaluation;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\EvaluationAssignment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Lever une affectation — retrait ou conflit déclaré (§11.1).
 *
 * Un seul cas d'usage pour deux gestes, parce qu'ils écrivent exactement la
 * même chose : une date de libération, un motif, un statut. Ce qui les
 * distingue est **ce que le statut autorise ensuite** — un retrait laisse le
 * dossier réaffectable à la même personne, un conflit l'interdit pour toujours.
 * Deux classes auraient dupliqué la transaction pour n'exprimer que ce
 * différentiel.
 *
 * **Rien n'est supprimé.** La ligne reste, avec sa date d'affectation et sa
 * date de libération. C'est ce qui permet de répondre à « ce dossier a-t-il
 * déjà été confié à quelqu'un, et pourquoi le lui a-t-on retiré ? » — question
 * que le §13.1 pose explicitement sous « conflits et récusations ».
 *
 * **Le motif est exigé.** Une affectation levée sans explication est
 * indéfendable : ni l'évaluateur ni le responsable suivant ne peuvent la
 * comprendre. C'est la même règle que l'observation d'un contrôle
 * d'admissibilité.
 *
 * Le statut du dossier n'est **pas** ramené en arrière quand la dernière
 * affectation tombe. `IN_EVALUATION → ADMISSIBLE` n'existe pas dans la machine
 * à états, et l'inventer ici ferait reculer un dossier dont une évaluation a
 * peut-être déjà commencé. Le tableau d'affectation montre le découvert : c'est
 * là que le responsable le voit, et il le comble en réaffectant.
 */
final readonly class ReleaseAssignment
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @throws DomainException
     */
    public function handle(
        EvaluationAssignment $affectation,
        AssignmentStatus $motif,
        string $raison,
        User $actor,
    ): EvaluationAssignment {
        if ($motif->compteDansLaCouverture()) {
            throw new DomainException("RELEASE_NOT_A_RELEASE: {$motif->value}");
        }

        $explication = trim($raison);

        if ($explication === '') {
            throw new DomainException('RELEASE_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($affectation, $motif, $explication, $actor): EvaluationAssignment {
            $verrouillee = EvaluationAssignment::query()
                ->whereKey($affectation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouillee->released_at !== null) {
                throw new DomainException('RELEASE_ALREADY_RELEASED');
            }

            $avant = $verrouillee->status;

            $verrouillee->forceFill([
                'status' => $motif->value,
                'released_at' => now(),
                'release_reason' => $explication,
            ])->save();

            $this->audit->write(
                actorId: $actor->getKey(),
                action: 'EVALUATION_ASSIGNMENT_RELEASED',
                targetType: Application::class,
                targetId: (string) $verrouillee->application_id,
                oldValue: ['status' => $avant->value],
                newValue: [
                    'status' => $motif->value,
                    'evaluator' => $verrouillee->evaluator_id,
                ],
                reason: $explication,
            );

            return $verrouillee;
        });
    }
}
