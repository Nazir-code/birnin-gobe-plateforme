<?php

namespace App\Http\Controllers\Evaluator;

use App\Domain\Application\DocumentType;
use App\Domain\Application\StoreApplicationDocument;
use App\Domain\Evaluation\AcceptEvaluationCharter;
use App\Domain\Evaluation\AssignmentStatus;
use App\Domain\Evaluation\EvaluatorDeskQuery;
use App\Domain\Evaluation\LockEvaluation;
use App\Domain\Evaluation\ReleaseAssignment;
use App\Domain\Evaluation\SaveEvaluationDraft;
use App\Http\Presenters\EvaluatorDeskPresenter;
use App\Http\Requests\Evaluator\DeclareConflictRequest;
use App\Http\Requests\Evaluator\SaveEvaluationRequest;
use App\Models\Evaluation;
use App\Models\EvaluationAssignment;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'espace évaluateur — §11.1, §11.2, §11.3.
 *
 * **Toute route passe par `sienne()`, et aucune ne lit d'identifiant
 * d'évaluateur.** L'affectation est résolue par la route, puis comparée à
 * l'utilisateur authentifié ; si elle n'est pas la sienne, la réponse est 404 et
 * non 403. La nuance compte ici plus qu'ailleurs : un 403 confirmerait
 * l'existence de l'affectation, donc qu'un dossier donné a été confié à
 * quelqu'un — exactement ce que l'indépendance des notations interdit de
 * laisser deviner.
 *
 * **La charte est une porte, pas un bandeau** (§11.1). Tant qu'elle n'est pas
 * acceptée, `show` rend l'écran d'engagement et non le dossier. C'est le seul
 * moyen que la déclaration d'impartialité soit signée *avant* d'avoir lu, ce que
 * le cahier des charges demande mot pour mot.
 *
 * **`lock` enregistre avant de verrouiller.** Un bouton « verrouiller » qui
 * exigerait d'avoir enregistré d'abord perdrait la dernière modification de
 * quiconque l'oublie, et la perdrait au moment le plus coûteux. Les deux gestes
 * sont donc une seule requête : la saisie est écrite, puis les règles du §11.3
 * sont opposées à ce qui vient d'être écrit.
 *
 * **La récusation réutilise `ReleaseAssignment`.** Elle écrit exactement ce
 * qu'écrit un retrait décidé par l'administration — date, motif, statut — et
 * seul le statut `CONFLICT` en tire la conséquence durable. Un second cas
 * d'usage aurait dupliqué la transaction pour n'exprimer que l'acteur.
 */
final class EvaluationController
{
    public function index(Request $request, EvaluatorDeskPresenter $presenter): Response
    {
        $desk = new EvaluatorDeskQuery($request->user());

        return Inertia::render('Evaluator/Assignments', [
            'assignments' => $desk->get()
                ->map(fn (EvaluationAssignment $affectation): array => $presenter->deskRow($affectation))
                ->all(),
            'remaining' => $desk->restantes(),
        ]);
    }

    public function show(
        Request $request,
        EvaluationAssignment $assignment,
        EvaluatorDeskPresenter $presenter,
    ): Response {
        $affectation = $this->sienne($request, $assignment);

        if (! $affectation->charteAcceptee()) {
            return Inertia::render('Evaluator/Charter', $presenter->charte($affectation));
        }

        $affectation->load(['application.campaign', 'application.candidate', 'application.sections', 'evaluation.scores']);

        $evaluation = $affectation->evaluation;

        // Défensif, et volontairement silencieux pour l'utilisateur : la charte
        // ouvre la feuille dans la même transaction, donc l'absence ne peut
        // venir que d'une écriture faite hors du cas d'usage.
        abort_if($evaluation === null, 404);

        return Inertia::render('Evaluator/Evaluate', $presenter->dossier($affectation, $evaluation));
    }

    public function acceptCharter(
        Request $request,
        EvaluationAssignment $assignment,
        AcceptEvaluationCharter $accepter,
    ): RedirectResponse {
        $affectation = $this->sienne($request, $assignment);

        try {
            $accepter->handle($affectation, $request->user());
        } catch (DomainException $refus) {
            return back()->withErrors(['charter' => $this->message($refus)]);
        }

        return redirect()
            ->route('evaluator.assignments.show', $affectation)
            ->with('status', 'Engagement enregistré. Le dossier est ouvert.');
    }

    public function save(
        SaveEvaluationRequest $request,
        EvaluationAssignment $assignment,
        SaveEvaluationDraft $sauvegarde,
    ): RedirectResponse {
        $evaluation = $this->feuille($request, $assignment);

        try {
            $sauvegarde->handle(
                evaluation: $evaluation,
                evaluateur: $request->user(),
                feuille: $request->feuille(),
                recommandation: $request->recommandation(),
                commentaire: $request->input('comment'),
            );
        } catch (DomainException $refus) {
            return back()->withErrors(['evaluation' => $this->message($refus)]);
        }

        return back()->with('status', 'Notation enregistrée.');
    }

    public function lock(
        SaveEvaluationRequest $request,
        EvaluationAssignment $assignment,
        SaveEvaluationDraft $sauvegarde,
        LockEvaluation $verrouiller,
    ): RedirectResponse {
        $evaluation = $this->feuille($request, $assignment);

        try {
            $sauvegarde->handle(
                evaluation: $evaluation,
                evaluateur: $request->user(),
                feuille: $request->feuille(),
                recommandation: $request->recommandation(),
                commentaire: $request->input('comment'),
            );

            $verrouiller->handle($evaluation, $request->user());
        } catch (DomainException $refus) {
            // La saisie est déjà écrite : le refus porte sur le verrouillage,
            // pas sur l'enregistrement, et rien n'est perdu.
            return back()->withErrors(['lock' => $this->message($refus)]);
        }

        return redirect()
            ->route('evaluator.assignments')
            ->with('status', 'Évaluation verrouillée.');
    }

    public function declareConflict(
        DeclareConflictRequest $request,
        EvaluationAssignment $assignment,
        ReleaseAssignment $lever,
    ): RedirectResponse {
        $affectation = $this->sienne($request, $assignment);

        try {
            $lever->handle(
                affectation: $affectation,
                motif: AssignmentStatus::CONFLICT,
                raison: (string) $request->input('reason'),
                actor: $request->user(),
            );
        } catch (DomainException $refus) {
            return back()->withErrors(['reason' => $this->message($refus)]);
        }

        return redirect()
            ->route('evaluator.assignments')
            ->with('status', 'Récusation enregistrée. Le dossier ne vous est plus affecté.');
    }

    /**
     * Le téléchargement d'une pièce du dossier affecté — §11.2.
     *
     * Une route à part, et non celle de l'administration : le lien admin est
     * derrière `role:admin`, et le réutiliser aurait donné à l'évaluateur des
     * liens qui échouent silencieusement sur la seule section — les pièces —
     * dont le §11.2 fait dépendre la faisabilité technique et le prototype.
     *
     * L'habilitation se lit sur l'affectation, pas sur le rôle : c'est
     * `sienne()` qui décide, donc un évaluateur ne peut télécharger que les
     * pièces d'un dossier qui lui est confié et dont il a signé la charte.
     */
    public function downloadDocument(
        Request $request,
        EvaluationAssignment $assignment,
        string $type,
    ): StreamedResponse {
        $affectation = $this->sienne($request, $assignment);

        abort_unless($affectation->charteAcceptee(), 404);

        $piece = DocumentType::tryFrom($type);

        abort_if($piece === null, 404);

        $ligne = $affectation->application?->attachments()->where('type', $piece->value)->first();

        abort_if($ligne === null, 404);

        return StoreApplicationDocument::disk()->download(
            $ligne->storage_key,
            $ligne->original_filename,
            ['Content-Type' => $ligne->mime_type],
        );
    }

    /**
     * L'affectation de l'utilisateur authentifié, ou 404.
     *
     * Une affectation levée est traitée comme absente : le dossier a cessé
     * d'être le sien, et le laisser ouvert « en lecture » donnerait accès à un
     * dossier qu'il ne peut plus juger.
     */
    private function sienne(Request $request, EvaluationAssignment $assignment): EvaluationAssignment
    {
        abort_unless($assignment->evaluator_id === $request->user()?->getKey(), 404);
        abort_unless($assignment->released_at === null, 404);

        return $assignment;
    }

    /** La feuille de l'affectation, ou 404 si la charte n'a pas été acceptée. */
    private function feuille(Request $request, EvaluationAssignment $assignment): Evaluation
    {
        $affectation = $this->sienne($request, $assignment);
        $evaluation = $affectation->evaluation()->first();

        // Écrire une note sans avoir signé la déclaration d'impartialité
        // reviendrait à contourner le §11.1 en postant directement.
        abort_if($evaluation === null, 404);

        return $evaluation;
    }

    /**
     * Un refus du domaine, dit à l'évaluateur.
     *
     * Les codes portent parfois la liste des critères fautifs après un
     * deux-points — c'est la partie qui rend le message actionnable, et elle est
     * conservée.
     */
    private function message(DomainException $refus): string
    {
        [$code, $detail] = array_pad(explode(':', $refus->getMessage(), 2), 2, '');
        $detail = trim($detail);

        return match ($code) {
            'CHARTER_NOT_ASSIGNEE', 'EVALUATION_NOT_OWNER' => 'Ce dossier ne vous est pas affecté.',
            'CHARTER_ASSIGNMENT_RELEASED', 'EVALUATION_ASSIGNMENT_RELEASED' => 'Ce dossier ne vous est plus affecté : il a été retiré ou vous vous en êtes récusé.',
            'EVALUATION_LOCKED', 'EVALUATION_ALREADY_LOCKED' => 'Cette évaluation est verrouillée : elle ne peut plus être modifiée.',
            'EVALUATION_INCOMPLETE' => 'Les huit critères doivent être notés avant le verrouillage. Il manque : '.$detail.'.',
            'EVALUATION_EXTREME_UNJUSTIFIED' => 'Une note de 0 ou de 5 doit être justifiée (§11.3). À compléter : '.$detail.'.',
            'EVALUATION_RECOMMENDATION_REQUIRED' => 'Une recommandation est attendue avant le verrouillage.',
            'EVALUATION_COMMENT_REQUIRED' => 'Un rejet comme une proposition de short-list doivent être justifiés (§11.3).',
            'RELEASE_REASON_REQUIRED' => 'Une récusation doit être motivée.',
            'RELEASE_ALREADY_RELEASED' => 'Ce dossier ne vous est déjà plus affecté.',
            default => 'Cette opération a été refusée : le dossier n’est plus dans l’état attendu.',
        };
    }
}
