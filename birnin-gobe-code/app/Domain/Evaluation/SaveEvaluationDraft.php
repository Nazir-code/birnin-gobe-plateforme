<?php

namespace App\Domain\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationScore;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * L'enregistrement du brouillon de notation — §11.2, §11.3.
 *
 * **Rien n'est validé ici, et c'est la décision principale.** Une feuille à
 * moitié remplie, une note extrême encore sans justification, aucune
 * recommandation : tout cela s'enregistre. Le contraire — refuser un
 * enregistrement incomplet — reviendrait à obliger l'évaluateur à finir sa
 * notation en une seule séance, ou à perdre ce qu'il a saisi. C'est le contrat
 * d'enregistrement sans perte du candidat, appliqué à l'évaluateur : les
 * exigences du §11.3 sont vérifiées **au verrouillage**, par `LockEvaluation`,
 * qui est le moment où la notation engage son auteur.
 *
 * **Aucun événement d'audit.** Le journal du §13.3 sert à retrouver des
 * décisions ; un brouillon enregistré toutes les trente secondes n'en est pas
 * une, et le versement de ces écritures noierait les décisions réelles. Ce qui
 * est journalisé, c'est le verrouillage — le moment où les notes deviennent
 * opposables.
 *
 * Trois refus, tous portant sur l'état et non sur le contenu :
 *
 *  - la feuille n'appartient pas à qui écrit ;
 *  - l'affectation a été levée entre-temps — un dossier retiré ne se note plus ;
 *  - l'évaluation est verrouillée. Le §11.3 veut que « les évaluations restent
 *    indépendantes jusqu'au verrouillage » ; après lui, elles ne bougent plus,
 *    sinon la comparaison des écarts porterait sur des chiffres révisables.
 */
final readonly class SaveEvaluationDraft
{
    /** Un commentaire de critère justifie une note, il ne rédige pas un rapport. */
    public const CRITERION_COMMENT_MAX = 2000;

    /** Le commentaire général porte la recommandation : on lui laisse de la place. */
    public const COMMENT_MAX = 5000;

    /**
     * @throws DomainException
     */
    public function handle(
        Evaluation $evaluation,
        User $evaluateur,
        ScoreSheet $feuille,
        ?EvaluationRecommendation $recommandation,
        ?string $commentaire,
    ): Evaluation {
        return DB::transaction(function () use ($evaluation, $evaluateur, $feuille, $recommandation, $commentaire): Evaluation {
            $verrouillee = Evaluation::query()
                ->whereKey($evaluation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertModifiable($verrouillee, $evaluateur);

            foreach ($feuille->toArray() as $critere => $ligne) {
                EvaluationScore::query()->updateOrCreate(
                    ['evaluation_id' => $verrouillee->getKey(), 'criterion' => $critere],
                    ['score' => $ligne['score'], 'comment' => $ligne['comment']],
                );
            }

            $texte = trim((string) $commentaire);

            $verrouillee->forceFill([
                'recommendation' => $recommandation?->value,
                'comment' => $texte === '' ? null : $texte,
            ])->save();

            return $verrouillee->load('scores');
        });
    }

    /**
     * @throws DomainException
     */
    public function assertModifiable(Evaluation $evaluation, User $evaluateur): void
    {
        if ($evaluation->evaluator_id !== $evaluateur->getKey()) {
            throw new DomainException('EVALUATION_NOT_OWNER');
        }

        if (! $evaluation->status->estModifiable()) {
            throw new DomainException('EVALUATION_LOCKED');
        }

        $affectation = $evaluation->assignment()->first();

        if ($affectation === null || $affectation->released_at !== null) {
            throw new DomainException('EVALUATION_ASSIGNMENT_RELEASED');
        }
    }
}
