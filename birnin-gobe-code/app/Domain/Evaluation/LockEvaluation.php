<?php

namespace App\Domain\Evaluation;

use App\Domain\Application\ApplicationStateMachine;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\Evaluation;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Le verrouillage d'une évaluation — §11.3.
 *
 * C'est ici que la notation devient opposable, et donc ici que les exigences du
 * §11.3 sont vérifiées. `SaveEvaluationDraft` n'en vérifie aucune : séparer les
 * deux permet d'enregistrer sans perte pendant tout le travail, et de n'exiger
 * qu'au moment où l'évaluateur signe.
 *
 * Quatre règles, dans l'ordre où elles se lisent dans le cahier des charges :
 *
 * **1. Les huit critères sont notés.** Une note sur 100 calculée sur cinq
 * critères ne serait pas une note sur 100. Le message nomme les critères
 * manquants — « feuille incomplète » obligerait à chercher lesquels.
 *
 * **2. Les notes extrêmes sont justifiées.** « Commentaire obligatoire pour les
 * notes extrêmes » : 0 exclut, 5 distingue, et ni l'une ni l'autre n'est
 * contestable sans son motif.
 *
 * **3. Une recommandation est portée.** Une notation sans avis laisse le comité
 * réinterpréter des chiffres, ce que le §11.3 confie précisément à
 * l'évaluateur.
 *
 * **4. Le rejet et la short-list sont justifiés.** Même phrase du §11.3 que la
 * règle 2 : ce sont les deux recommandations qui décident du sort du dossier.
 *
 * **Le verrou est définitif.** Aucun déverrouillage n'existe, et ce n'est pas
 * un oubli : « les évaluations restent indépendantes jusqu'au verrouillage »
 * n'a de sens que si le verrou tient. Une note révisable après coup permettrait
 * de s'aligner sur celle d'un collègue une fois l'écart connu — exactement ce
 * que l'indépendance interdit. Une erreur se corrige en levant l'affectation et
 * en réaffectant le dossier, ce qui laisse une trace, à la différence d'une
 * correction silencieuse.
 *
 * **Le total est calculé et enregistré ici, jamais reçu de l'écran.** Le
 * navigateur affiche le même chiffre parce qu'il applique la même pondération,
 * mais c'est le serveur qui l'arrête : une note sur 100 postée par le client
 * serait une note sur 100 modifiable par le client.
 */
final readonly class LockEvaluation
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private AuditWriter $audit,
    ) {}

    /**
     * @throws DomainException
     */
    public function handle(Evaluation $evaluation, User $evaluateur): Evaluation
    {
        return DB::transaction(function () use ($evaluation, $evaluateur): Evaluation {
            $verrouillee = Evaluation::query()
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouillee->evaluator_id !== $evaluateur->getKey()) {
                throw new DomainException('EVALUATION_NOT_OWNER');
            }

            if (! $verrouillee->status->estModifiable()) {
                throw new DomainException('EVALUATION_ALREADY_LOCKED');
            }

            $affectation = $verrouillee->assignment()->first();

            if ($affectation === null || $affectation->released_at !== null) {
                throw new DomainException('EVALUATION_ASSIGNMENT_RELEASED');
            }

            $feuille = $this->feuille($verrouillee);

            $this->assertNotable($feuille, $verrouillee);

            $total = $feuille->total();

            $verrouillee->forceFill([
                'status' => EvaluationStatus::LOCKED->value,
                'total_score' => $total,
                'locked_at' => now(),
            ])->save();

            $dossier = Application::query()
                ->whereKey($verrouillee->application_id)
                ->lockForUpdate()
                ->firstOrFail();

            $statutInitial = $dossier->status;
            $this->cloreSiCouvert($dossier);

            $this->audit->write(
                actorId: $evaluateur->getKey(),
                action: 'EVALUATION_LOCKED',
                targetType: Application::class,
                targetId: (string) $dossier->getKey(),
                oldValue: ['status' => $statutInitial->value],
                newValue: [
                    'status' => $dossier->status->value,
                    'evaluator' => $evaluateur->getKey(),
                    'total_score' => $total,
                    'recommendation' => $verrouillee->recommendation?->value,
                ],
                reason: null,
            );

            return $verrouillee;
        });
    }

    /**
     * @throws DomainException
     */
    private function assertNotable(ScoreSheet $feuille, Evaluation $evaluation): void
    {
        $manquants = $feuille->manquants();

        if ($manquants !== []) {
            $noms = implode(', ', array_map(
                static fn (EvaluationCriterion $critere): string => $critere->label(),
                $manquants,
            ));

            throw new DomainException("EVALUATION_INCOMPLETE: {$noms}");
        }

        $injustifiees = $feuille->extremesSansJustification();

        if ($injustifiees !== []) {
            $noms = implode(', ', array_map(
                static fn (EvaluationCriterion $critere): string => $critere->label(),
                $injustifiees,
            ));

            throw new DomainException("EVALUATION_EXTREME_UNJUSTIFIED: {$noms}");
        }

        $recommandation = $evaluation->recommendation;

        if ($recommandation === null) {
            throw new DomainException('EVALUATION_RECOMMENDATION_REQUIRED');
        }

        if ($recommandation->requiresComment() && trim((string) $evaluation->comment) === '') {
            throw new DomainException('EVALUATION_COMMENT_REQUIRED');
        }
    }

    /** La feuille telle qu'elle est enregistrée. */
    private function feuille(Evaluation $evaluation): ScoreSheet
    {
        $lignes = [];

        foreach ($evaluation->scores()->get() as $ligne) {
            $lignes[$ligne->criterion->value] = [
                'score' => $ligne->score,
                'comment' => $ligne->comment,
            ];
        }

        return ScoreSheet::make($lignes);
    }

    /**
     * Le dossier passe en `EVALUATED` quand il a reçu le nombre d'évaluations
     * verrouillées que le comité a arrêté — §9.2, §11.1.
     *
     * **Tant que ce nombre n'est pas arrêté, rien ne se passe.** C'est la règle
     * d'ADR-007 poussée jusqu'à sa conséquence : conclure l'évaluation d'un
     * dossier sur un minimum inventé le ferait sortir de la file avec une seule
     * notation, alors que le comité en attendait peut-être trois. Le dossier
     * reste `IN_EVALUATION`, et le tableau d'affectation montre le découvert.
     */
    private function cloreSiCouvert(Application $dossier): void
    {
        if ($dossier->status !== ApplicationStatus::IN_EVALUATION) {
            return;
        }

        $minimum = EvaluationSettings::fromCampaign($dossier->campaign()->first())->minEvaluations;

        if ($minimum === null) {
            return;
        }

        $verrouillees = Evaluation::query()
            ->where('application_id', $dossier->getKey())
            ->verrouillees()
            ->count();

        if ($verrouillees < $minimum) {
            return;
        }

        $this->stateMachine->assertCanTransition($dossier->status, ApplicationStatus::EVALUATED);
        $dossier->forceFill(['status' => ApplicationStatus::EVALUATED->value])->save();
    }
}
