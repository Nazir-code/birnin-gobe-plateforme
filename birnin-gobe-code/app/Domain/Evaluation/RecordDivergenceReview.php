<?php

namespace App\Domain\Evaluation;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\EvaluationReview;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * L'enregistrement d'une revue d'écart — §11.3.
 *
 * **Ce cas d'usage n'écrit aucune note, et ne peut pas en écrire.** C'est sa
 * propriété principale : le §11.3 confie la notation aux évaluateurs et
 * n'accorde au gestionnaire que « l'avancement, pas une modification silencieuse
 * des notes ». Une revue conclut sur ce qu'on fait du désaccord, jamais sur sa
 * disparition. Aucune route de l'administration ne touche à `evaluation_scores`,
 * et c'est ce qui rend la phrase du cahier des charges vérifiable plutôt que
 * déclarative.
 *
 * Trois refus :
 *
 * 1. **Il faut de quoi diverger.** Moins de deux évaluations verrouillées, et
 *    il n'y a pas d'écart — seulement une notation en cours. Revoir un écart
 *    qui n'existe pas produirait une trace fausse.
 * 2. **Il faut un seuil arrêté.** Sans lui, aucun écart n'est excessif :
 *    enregistrer une revue reviendrait à dire qu'on a arbitré contre une règle
 *    que personne n'a fixée. Le §9.2 est le préalable, et l'écran y renvoie.
 * 3. **Le motif est exigé.** Une revue sans explication ne prouve pas qu'elle a
 *    eu lieu ; elle prouve qu'on a cliqué. La question que posera un contrôle
 *    est « pourquoi ce désaccord a-t-il été jugé acceptable », et c'est cette
 *    phrase-là qui y répond.
 *
 * L'état vu est figé sur la ligne — nombre d'évaluations et écart constaté —
 * dans la même transaction que la lecture, sous verrou : deux gestionnaires qui
 * revoient le même dossier pendant qu'une évaluation se verrouille ne doivent
 * pas écrire deux revues portant sur des états différents en se croyant
 * d'accord.
 */
final readonly class RecordDivergenceReview
{
    /** Un motif de revue est une phrase d'arbitrage, pas un accusé de réception. */
    public const REASON_MIN = 15;

    public const REASON_MAX = 2000;

    public function __construct(private AuditWriter $audit) {}

    /**
     * @throws DomainException
     */
    public function handle(
        Application $dossier,
        DivergenceReviewOutcome $issue,
        string $motif,
        User $actor,
    ): EvaluationReview {
        $explication = trim($motif);

        if (mb_strlen($explication) < self::REASON_MIN) {
            throw new DomainException('REVIEW_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($dossier, $issue, $explication, $actor): EvaluationReview {
            $verrouille = Application::query()
                ->whereKey($dossier->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $verrouille->load(['campaign', 'evaluations.scores']);

            $seuil = EvaluationSettings::fromCampaign($verrouille->campaign)->scoreGapThreshold;

            if ($seuil === null) {
                throw new DomainException('REVIEW_NO_THRESHOLD');
            }

            $divergence = EvaluationDivergence::pour($verrouille, $seuil);

            if (! $divergence->comparable()) {
                throw new DomainException('REVIEW_NOT_COMPARABLE');
            }

            $revue = EvaluationReview::query()->create([
                'application_id' => $verrouille->getKey(),
                'outcome' => $issue->value,
                'reason' => $explication,
                'covered_evaluations' => $divergence->lockedCount(),
                'observed_gap' => $divergence->maxGap(),
                'reviewed_by' => $actor->getKey(),
                'created_at' => now(),
            ]);

            $this->audit->write(
                actorId: $actor->getKey(),
                action: 'EVALUATION_DIVERGENCE_REVIEWED',
                targetType: Application::class,
                targetId: (string) $verrouille->getKey(),
                // Le statut ne bouge pas : une revue arbitre un désaccord, elle
                // ne fait pas avancer le dossier dans le concours.
                oldValue: null,
                newValue: [
                    'outcome' => $issue->value,
                    'covered_evaluations' => $divergence->lockedCount(),
                    'observed_gap' => $divergence->maxGap(),
                    'threshold' => $seuil,
                ],
                reason: $explication,
            );

            return $revue;
        });
    }
}
