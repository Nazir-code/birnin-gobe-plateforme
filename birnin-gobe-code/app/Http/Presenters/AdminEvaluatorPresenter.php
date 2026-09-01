<?php

namespace App\Http\Presenters;

use App\Domain\Evaluation\AssignmentBoardQuery;
use App\Domain\Evaluation\AssignmentStatus;
use App\Domain\Evaluation\EvaluationSettings;
use App\Models\Application;
use App\Models\EvaluationAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Met le tableau d'affectation en forme — §11.1.
 *
 * Trois choses, et une seule règle qui les gouverne : **la couverture d'un
 * dossier peut être inconnue**. Tant que le comité n'a pas arrêté le nombre
 * minimal d'évaluations (§9.2), `couvert` vaut `null` et l'écran le dit. Rendre
 * `false` ferait apparaître en rouge des dossiers que personne n'a déclarés
 * insuffisamment couverts, et pousserait à sur-affecter contre un seuil
 * imaginaire.
 *
 * Les évaluateurs écartés d'un dossier — déjà affectés, ou en conflit déclaré —
 * sont calculés pour toute la page en une requête et rendus par dossier : c'est
 * ce qui permet à l'écran de ne pas proposer une affectation que le serveur
 * refusera.
 */
final readonly class AdminEvaluatorPresenter
{
    /**
     * Une ligne du tableau des dossiers à affecter.
     *
     * @param  array<int, list<int>>  $ecartes
     * @return array{
     *     id: int, submissionNumber: string|null, candidateName: string,
     *     campaignName: string, status: string, statusLabel: string,
     *     submittedAt: string|null, assignmentCount: int,
     *     covered: bool|null, excludedEvaluators: list<int>, showUrl: string
     * }
     */
    public function dossierRow(Application $application, EvaluationSettings $reglages, array $ecartes): array
    {
        $affectations = (int) ($application->affectations_en_vigueur ?? 0);

        return [
            'id' => $application->getKey(),
            'submissionNumber' => $application->submission_number,
            'candidateName' => $application->candidate?->name ?? '—',
            'campaignName' => $application->campaign?->name ?? '—',
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'submittedAt' => $application->submitted_at?->toIso8601String(),
            'assignmentCount' => $affectations,
            'covered' => $reglages->couvert($affectations),
            'excludedEvaluators' => array_values($ecartes[$application->getKey()] ?? []),
            'showUrl' => route('admin.applications.show', $application),
        ];
    }

    /**
     * Un évaluateur, avec sa charge et l'état de son accès.
     *
     * **`invitationPending` distingue un compte actif d'un compte jamais
     * activé** — ADR-022. Rien ne les séparait à l'écran : un évaluateur créé
     * mais qui n'a jamais reçu son invitation apparaissait comme les autres,
     * et un responsable pouvait lui affecter des dossiers qu'il n'ouvrirait
     * jamais. La présence d'un jeton non consommé est la seule preuve dont on
     * dispose, et elle suffit : le jeton disparaît dès que le mot de passe est
     * défini.
     *
     * @return array{id: int, name: string, email: string, load: int, accepted: int, conflicts: int, invitationPending: bool}
     */
    public function evaluateurRow(User $evaluateur, bool $invitationEnAttente = false): array
    {
        return [
            'id' => $evaluateur->getKey(),
            'name' => $evaluateur->name,
            'email' => $evaluateur->email,
            'invitationPending' => $invitationEnAttente,
            'load' => (int) ($evaluateur->charge_courante ?? 0),
            'accepted' => (int) ($evaluateur->prises_en_charge ?? 0),
            'conflicts' => (int) ($evaluateur->conflits_declares ?? 0),
        ];
    }

    /**
     * Les affectations en vigueur, pour la troisième colonne de l'écran.
     *
     * @param  Collection<int, EvaluationAssignment>  $affectations
     * @return list<array<string, mixed>>
     */
    public function affectations(Collection $affectations): array
    {
        return $affectations
            ->map(static fn (EvaluationAssignment $ligne): array => [
                'id' => $ligne->getKey(),
                'applicationId' => $ligne->application_id,
                'submissionNumber' => $ligne->application?->submission_number,
                'candidateName' => $ligne->application?->candidate?->name ?? '—',
                'evaluatorId' => $ligne->evaluator_id,
                'evaluatorName' => $ligne->evaluator?->name ?? 'Compte supprimé',
                'status' => $ligne->status->value,
                'statusLabel' => $ligne->status->label(),
                'assignedAt' => $ligne->assigned_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Les motifs de levée proposés à l'écran.
     *
     * Seuls les états qui sortent de la couverture sont offerts : proposer
     * « affecté » comme motif de retrait n'aurait aucun sens, et le cas d'usage
     * le refuserait.
     *
     * @return list<array{value: string, label: string, definitive: bool}>
     */
    public static function motifsDeLevee(): array
    {
        return array_values(array_map(
            static fn (AssignmentStatus $statut): array => [
                'value' => $statut->value,
                'label' => $statut->label(),
                // Un conflit interdit la réaffectation ; un retrait non. L'écran
                // doit le dire avant le clic, pas après.
                'definitive' => $statut === AssignmentStatus::CONFLICT,
            ],
            array_filter(
                AssignmentStatus::cases(),
                static fn (AssignmentStatus $statut): bool => ! $statut->compteDansLaCouverture(),
            ),
        ));
    }

    /** @return list<array{value: string, label: string}> */
    public static function sortOptions(): array
    {
        return AssignmentBoardQuery::sortOptions();
    }
}
