<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Verification\DecideAdmissibility;
use App\Domain\Verification\SaveVerificationChecks;
use App\Domain\Verification\VerificationQueueQuery;
use App\Http\Presenters\AdminVerificationPresenter;
use App\Http\Requests\Admin\DecideAdmissibilityRequest;
use App\Http\Requests\Admin\SaveVerificationChecksRequest;
use App\Http\Requests\Admin\VerificationQueueRequest;
use App\Models\Application;
use App\Models\Campaign;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contrôle d'admissibilité — §10 du cahier des charges.
 *
 * Quatre routes : la file, l'écran de contrôle, l'enregistrement de la grille
 * et la décision. C'est le premier endroit de l'administration qui **écrit** sur
 * une candidature, et c'est assumé : jusqu'au dépôt le dossier appartient au
 * candidat, après le dépôt son admissibilité appartient à l'administration.
 * L'écriture ne touche d'ailleurs jamais les réponses — elle ajoute un verdict
 * à côté du dossier et fait bouger son statut, jamais son contenu. Aucune route
 * de cette classe ne réécrit une réponse ni une pièce.
 *
 * **La consultation n'est pas journalisée**, comme sur la liste des
 * candidatures et le journal d'audit : le journal sert à retrouver des
 * décisions, et y verser chaque ouverture de dossier les noierait.
 *
 * Le contrôleur reste mince. Les filtres sont dans `VerificationQueueQuery`, la
 * forme des saisies dans les deux `FormRequest`, la mise en forme dans
 * `AdminVerificationPresenter`, et les règles d'admissibilité dans les deux cas
 * d'usage — qui les rejouent sous verrou, parce que ce qui a été affiché
 * n'engage pas ce qui est écrit.
 */
final class VerificationController
{
    public function index(VerificationQueueRequest $request, AdminVerificationPresenter $presenter): Response
    {
        $page = $request->toQueueQuery()->paginate();

        return Inertia::render('Admin/Verification/Index', [
            'applications' => $page
                ->through(fn (Application $application): array => $presenter->queueRow($application))
                ->items(),
            'pagination' => [
                'currentPage' => $page->currentPage(),
                'lastPage' => $page->lastPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'previousUrl' => $page->previousPageUrl(),
                'nextUrl' => $page->nextPageUrl(),
            ],
            'filters' => $request->filters(),
            'hasActiveFilters' => $request->hasActiveFilters(),
            // Une file vide est le but, pas une panne : l'écran doit pouvoir le
            // dire autrement que « aucun résultat », qui se lit comme un filtre
            // trop étroit.
            'totalWaiting' => VerificationQueueQuery::totalOuverts(),
            'options' => $this->options(),
            'resetUrl' => route('admin.verification.index'),
        ]);
    }

    /**
     * L'écran unique du §10.1 : résumé, formulaire, pièces, règles applicables,
     * anomalies automatiques et historique.
     */
    public function show(Application $application, AdminVerificationPresenter $presenter): Response
    {
        $application->load(VerificationQueueQuery::relationsDuDossier());

        return Inertia::render('Admin/Verification/Show', $presenter->dossier($application));
    }

    public function storeChecks(
        SaveVerificationChecksRequest $request,
        Application $application,
        SaveVerificationChecks $sauvegarde,
    ): RedirectResponse {
        try {
            $sauvegarde->handle($application, $request->grille(), $request->user());
        } catch (DomainException $refus) {
            return back()->withErrors(['checks' => $this->message($refus)]);
        }

        // Retour sur le même écran : une grille se remplit contrôle après
        // contrôle, et renvoyer à la file obligerait à rouvrir le dossier pour
        // continuer.
        return back()->with('status', 'Grille enregistrée.');
    }

    public function storeDecision(
        DecideAdmissibilityRequest $request,
        Application $application,
        DecideAdmissibility $decider,
    ): RedirectResponse {
        try {
            $decider->handle(
                application: $application,
                decision: $request->decision(),
                actor: $request->user(),
                primaryReason: $request->primaryReason(),
                secondaryReason: $request->secondaryReason(),
                internalNote: $request->input('internal_note'),
                candidateMessage: $request->input('candidate_message'),
                respondBy: $request->respondBy(),
            );
        } catch (DomainException $refus) {
            return back()->withErrors(['decision' => $this->message($refus)]);
        }

        // Retour à la file : la décision prise, le dossier en sort, et le
        // vérificateur enchaîne sur le suivant. C'est le geste attendu d'une
        // file, à la différence de la grille qu'on remplit par étapes.
        return redirect()
            ->route('admin.verification.index')
            ->with('status', 'Décision enregistrée.');
    }

    /**
     * Un refus du domaine, dit au vérificateur.
     *
     * Les codes du domaine sont faits pour les journaux et les tests ; ils ne
     * sont pas montrés tels quels. Un code inconnu rend une phrase générique
     * plutôt que le code brut : l'écran d'un vérificateur n'est pas une console.
     */
    private function message(DomainException $refus): string
    {
        $code = explode(':', $refus->getMessage(), 2)[0];

        return match ($code) {
            'VERIFICATION_CLOSED' => 'Ce dossier a déjà été décidé : sa grille n’est plus modifiable.',
            'VERIFICATION_GRID_INCOMPLETE' => 'Les sept contrôles doivent être renseignés avant toute décision (§10.2).',
            'VERIFICATION_BLOCKING_REMAINS' => 'Un contrôle bloquant subsiste : il doit être levé avant de déclarer le dossier recevable.',
            'VERIFICATION_PRIMARY_REASON_REQUIRED' => 'Un rejet doit porter un motif principal (§10.3).',
            'VERIFICATION_REASON_NOT_BLOCKING' => 'Le motif retenu ne correspond à aucun contrôle bloquant de la grille.',
            'VERIFICATION_REASONS_IDENTICAL' => 'Le motif secondaire doit différer du motif principal.',
            'VERIFICATION_CANDIDATE_MESSAGE_REQUIRED' => 'Le candidat doit recevoir un message, distinct de l’observation interne.',
            'VERIFICATION_RESPOND_BY_REQUIRED' => 'Une demande de clarification doit fixer une date limite de réponse.',
            'VERIFICATION_RESPOND_BY_PAST' => 'La date limite ne peut pas être passée.',
            'VERIFICATION_OBSERVATION_REQUIRED' => 'Un verdict qui n’est pas conforme doit être expliqué par une observation.',
            'VERIFICATION_OUTCOME_MISMATCH' => 'Ce verdict n’appartient pas au contrôle visé.',
            default => 'Cette opération a été refusée : le dossier n’est plus dans l’état attendu.',
        };
    }

    /**
     * Ce que les listes déroulantes ont le droit de proposer.
     *
     * Les statuts sont ceux du contrôle d'admissibilité, pas les quinze de
     * l'enum : proposer « finaliste » dans une file de vérification ferait
     * croire que la file en contient.
     *
     * @return array{campaigns: list<array{value: string, label: string}>, statuses: list<array{value: string, label: string}>, scopes: list<array{value: string, label: string}>, sorts: list<array{value: string, label: string}>}
     */
    private function options(): array
    {
        $statuts = array_merge(
            VerificationQueueQuery::statutsOuverts(),
            VerificationQueueQuery::statutsTraites(),
        );

        return [
            'campaigns' => Campaign::query()
                ->orderByRaw('opens_at IS NULL')
                ->orderByDesc('opens_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (Campaign $campagne): array => [
                    'value' => (string) $campagne->getKey(),
                    'label' => $campagne->name.' ('.$campagne->code.')',
                ])
                ->all(),
            'statuses' => array_map(
                static fn (ApplicationStatus $statut): array => [
                    'value' => $statut->value,
                    'label' => $statut->label(),
                ],
                $statuts,
            ),
            'scopes' => VerificationQueueQuery::scopeOptions(),
            'sorts' => VerificationQueueQuery::sortOptions(),
        ];
    }
}
