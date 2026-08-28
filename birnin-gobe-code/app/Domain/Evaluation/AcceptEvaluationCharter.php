<?php

namespace App\Domain\Evaluation;

use App\Domain\Audit\AuditWriter;
use App\Models\Application;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationScore;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * L'acceptation de la charte, dossier par dossier — §11.1.
 *
 * « Avant d'accéder à un dossier, chaque évaluateur accepte la charte, la
 * confidentialité et la déclaration d'impartialité. » Trois conséquences,
 * toutes tenues ici et nulle part ailleurs :
 *
 * **1. C'est une porte, pas une case à cocher.** Tant que l'acceptation n'est
 * pas écrite, l'écran du dossier n'est pas rendu — voir `EvaluationController`.
 * Un bandeau « pensez à accepter la charte » au-dessus d'un dossier déjà
 * lisible ne serait pas une déclaration d'impartialité, ce serait un
 * avertissement.
 *
 * **2. Elle est par dossier.** On ne déclare pas être impartial en général ; on
 * déclare l'être sur *ce* dossier, après avoir vu de qui il émane. Une
 * acceptation unique à la première connexion serait signée avant de savoir sur
 * quoi elle porte — donc sans objet.
 *
 * **3. Elle ouvre la feuille de notes.** L'évaluation et ses huit lignes sont
 * créées ici, vides. Les créer à l'affectation aurait donné à l'administration
 * des feuilles ouvertes que personne n'a acceptées ; les créer au premier
 * enregistrement aurait fait dépendre leur existence d'un geste que
 * l'évaluateur peut ne jamais faire, et l'avancement du §11.3 n'aurait plus rien
 * à compter.
 *
 * **Idempotent.** Un double clic, ou un retour arrière du navigateur, ne doit ni
 * échouer ni réécrire la date : c'est la première acceptation qui engage, et
 * c'est elle qu'on produira si l'engagement est contesté.
 */
final readonly class AcceptEvaluationCharter
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @throws DomainException
     */
    public function handle(EvaluationAssignment $affectation, User $evaluateur): Evaluation
    {
        if ($affectation->evaluator_id !== $evaluateur->getKey()) {
            throw new DomainException('CHARTER_NOT_ASSIGNEE');
        }

        return DB::transaction(function () use ($affectation, $evaluateur): Evaluation {
            $verrouillee = EvaluationAssignment::query()
                ->whereKey($affectation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($verrouillee->released_at !== null) {
                throw new DomainException('CHARTER_ASSIGNMENT_RELEASED');
            }

            $premiere = $verrouillee->accepted_at === null;

            if ($premiere) {
                $verrouillee->forceFill([
                    'status' => AssignmentStatus::ACCEPTED->value,
                    'accepted_at' => now(),
                ])->save();
            }

            $evaluation = $this->ouvrirLaFeuille($verrouillee);

            if ($premiere) {
                $this->audit->write(
                    actorId: $evaluateur->getKey(),
                    action: 'EVALUATION_CHARTER_ACCEPTED',
                    targetType: Application::class,
                    targetId: (string) $verrouillee->application_id,
                    oldValue: ['status' => AssignmentStatus::ASSIGNED->value],
                    newValue: [
                        'status' => AssignmentStatus::ACCEPTED->value,
                        'evaluator' => $evaluateur->getKey(),
                    ],
                    reason: null,
                );
            }

            return $evaluation;
        });
    }

    /**
     * La feuille de notes, créée vide si elle n'existe pas.
     *
     * Les huit lignes sont créées en même temps que l'évaluation, et non à la
     * volée pendant la saisie : l'écran doit pouvoir afficher la grille
     * complète du §11.2 dès la première ouverture, y compris les critères que
     * l'évaluateur n'a pas encore regardés.
     */
    private function ouvrirLaFeuille(EvaluationAssignment $affectation): Evaluation
    {
        $evaluation = Evaluation::query()
            ->where('evaluation_assignment_id', $affectation->getKey())
            ->first();

        if ($evaluation !== null) {
            return $evaluation;
        }

        $evaluation = Evaluation::query()->create([
            'evaluation_assignment_id' => $affectation->getKey(),
            'application_id' => $affectation->application_id,
            'evaluator_id' => $affectation->evaluator_id,
            'status' => EvaluationStatus::DRAFT->value,
        ]);

        foreach (EvaluationCriterion::cases() as $critere) {
            EvaluationScore::query()->create([
                'evaluation_id' => $evaluation->getKey(),
                'criterion' => $critere->value,
                'score' => null,
                'comment' => null,
            ]);
        }

        return $evaluation;
    }
}
