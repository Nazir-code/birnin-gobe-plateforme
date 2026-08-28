<?php

namespace App\Http\Presenters;

use App\Domain\Application\ApplicationProgress;
use App\Domain\Application\ApplicationSection;
use App\Domain\Verification\AdmissibilityDecision;
use App\Domain\Verification\AutomaticFinding;
use App\Domain\Verification\AutomaticFindings;
use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationQueueQuery;
use App\Models\Application;
use App\Models\User;
use App\Models\VerificationCheck;
use App\Models\VerificationDecision;
use Illuminate\Support\Collection;

/**
 * Met la file de vérification et l'écran de contrôle en forme — §10.1.
 *
 * **Le dossier n'est pas remis en forme ici.** `AdminApplicationPresenter`
 * rend déjà les neuf sections, les pièces et le verdict d'éligibilité pour
 * l'écran de consultation ; le contrôle d'admissibilité lit exactement le même
 * dossier. Le déléguer plutôt que le recopier est ce qui garantit que le
 * vérificateur voit ce que le candidat a relu à l'étape 9 — deux mises en forme
 * finiraient par diverger, et la divergence porterait sur la pièce même qui
 * fonde la décision.
 *
 * Ce que cette classe ajoute, et qui n'existe nulle part ailleurs : la grille
 * du §10.2 avec ce qui y est déjà coché, les signalements automatiques, et
 * l'historique des décisions.
 *
 * **Les acteurs sont résolus en une fois**, comme dans `AdminAuditPresenter` :
 * `actor_id` n'a pas de clé étrangère — un compte interne supprimé ne doit pas
 * effacer la trace de ce qu'il a contrôlé — donc aucune relation ne les charge,
 * et une résolution ligne par ligne coûterait une requête par coche.
 */
final readonly class AdminVerificationPresenter
{
    public function __construct(
        private AdminApplicationPresenter $dossierPresenter,
        private AutomaticFindings $signalements,
    ) {}

    /**
     * Une ligne de la file.
     *
     * @return array{
     *     id: int, candidateName: string, candidateEmail: string,
     *     campaignName: string, submissionNumber: string|null,
     *     status: string, statusLabel: string,
     *     submittedAt: string|null, waitingDays: int|null,
     *     checksDone: int, checksTotal: int,
     *     completionPercent: int, showUrl: string
     * }
     */
    public function queueRow(Application $application): array
    {
        $depot = $application->submitted_at;

        return [
            'id' => $application->getKey(),
            'candidateName' => $application->candidate?->name ?? '—',
            'candidateEmail' => $application->candidate?->email ?? '—',
            'campaignName' => $application->campaign?->name ?? '—',
            'submissionNumber' => $application->submission_number,
            'status' => $application->status->value,
            'statusLabel' => $application->status->label(),
            'submittedAt' => $depot?->toIso8601String(),
            // L'ancienneté est ce qu'une file doit rendre visible : c'est elle
            // qui dit qu'un dossier a été oublié, pas sa date brute.
            'waitingDays' => $depot === null ? null : (int) $depot->diffInDays(now()),
            'checksDone' => (int) ($application->verification_checks_count ?? 0),
            'checksTotal' => count(VerificationControl::cases()),
            'completionPercent' => ApplicationProgress::percentFromCompleted(
                (int) ($application->completed_sections_count ?? 0),
            ),
            'showUrl' => route('admin.verification.show', $application),
        ];
    }

    /**
     * L'écran unique du §10.1 : « résumé, formulaire, pièces, règles
     * applicables, anomalies automatiques et historique ».
     *
     * @return array<string, mixed>
     */
    public function dossier(Application $application): array
    {
        $acteurs = $this->acteurs($application);

        return [
            'application' => $this->dossierPresenter->detail($application),
            'checks' => $this->grille($application, $acteurs),
            'findings' => array_map(
                static fn (AutomaticFinding $signalement): array => $signalement->toArray(),
                $this->signalements->for($application),
            ),
            'decisions' => $this->historique($application, $acteurs),
            'matrix' => VerificationControl::matrix(),
            'decisionOptions' => AdmissibilityDecision::options(),
            // Les motifs proposés au rejet sont les contrôles, jamais une
            // seconde liste : le §10.3 veut un motif codifié, et le code d'un
            // motif est le contrôle qui a bloqué.
            'reasonOptions' => VerificationControl::options(),
            'sectionsOrder' => array_map(
                static fn (ApplicationSection $section): string => $section->value,
                ApplicationSection::openPath(),
            ),
            'editable' => $this->modifiable($application),
            'queueUrl' => route('admin.verification.index'),
            'saveChecksUrl' => route('admin.verification.checks.store', $application),
            'decideUrl' => route('admin.verification.decision.store', $application),
        ];
    }

    /**
     * La grille, contrôle par contrôle, dans l'ordre du §10.2.
     *
     * Les sept lignes sont rendues même vides : une grille qui n'afficherait
     * que ce qui est coché ferait passer un contrôle oublié pour un contrôle
     * absent du référentiel.
     *
     * @param  Collection<int, User>  $acteurs
     * @return list<array{control: string, outcome: string|null, observation: string|null, actor: string|null, recordedAt: string|null}>
     */
    private function grille(Application $application, Collection $acteurs): array
    {
        $coches = $application->verificationChecks->keyBy(
            static fn (VerificationCheck $ligne): string => $ligne->control->value,
        );

        return array_map(
            function (VerificationControl $controle) use ($coches, $acteurs): array {
                $ligne = $coches->get($controle->value);

                return [
                    'control' => $controle->value,
                    'outcome' => $ligne?->outcome->value,
                    'observation' => $ligne?->observation,
                    'actor' => $this->nomActeur($acteurs, $ligne?->actor_id),
                    'recordedAt' => $ligne?->updated_at?->toIso8601String(),
                ];
            },
            VerificationControl::cases(),
        );
    }

    /**
     * L'historique des décisions, du plus récent au plus ancien.
     *
     * L'observation interne y figure, le message au candidat aussi — ce sont
     * deux textes distincts (§10.3) et l'écran de contrôle est interne. Ils ne
     * quittent jamais cette route : rien ici n'alimente un écran candidat.
     *
     * @param  Collection<int, User>  $acteurs
     * @return list<array<string, mixed>>
     */
    private function historique(Application $application, Collection $acteurs): array
    {
        return $application->verificationDecisions
            ->sortByDesc('id')
            ->values()
            ->map(fn (VerificationDecision $decision): array => [
                'id' => $decision->getKey(),
                'decision' => $decision->decision->value,
                'decisionLabel' => $decision->decision->label(),
                'primaryReason' => $decision->primary_reason?->value,
                'primaryReasonLabel' => $decision->primary_reason?->label(),
                'secondaryReason' => $decision->secondary_reason?->value,
                'secondaryReasonLabel' => $decision->secondary_reason?->label(),
                'internalNote' => $decision->internal_note,
                'candidateMessage' => $decision->candidate_message,
                'respondBy' => $decision->respond_by?->toDateString(),
                'previousStatusLabel' => $decision->previous_status->label(),
                'newStatusLabel' => $decision->new_status->label(),
                'actor' => $this->nomActeur($acteurs, $decision->actor_id),
                'decidedAt' => $decision->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Le dossier accepte-t-il encore une coche ou une décision ?
     *
     * La même règle que `SaveVerificationChecks::assertModifiable()`, mais
     * l'écran ne s'y fie pas : c'est le serveur qui refuse. Ici, elle sert
     * seulement à ne pas afficher un formulaire qui serait rejeté.
     */
    private function modifiable(Application $application): bool
    {
        return in_array($application->status, VerificationQueueQuery::statutsOuverts(), true);
    }

    /**
     * Les comptes cités par la grille et l'historique, en une seule requête.
     *
     * @return Collection<int, User>
     */
    private function acteurs(Application $application): Collection
    {
        $identifiants = $application->verificationChecks
            ->pluck('actor_id')
            ->merge($application->verificationDecisions->pluck('actor_id'))
            ->filter()
            ->unique()
            ->all();

        return User::query()
            ->whereIn('id', $identifiants)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, User>  $acteurs
     */
    private function nomActeur(Collection $acteurs, ?int $identifiant): ?string
    {
        if ($identifiant === null) {
            return null;
        }

        // Un compte supprimé garde ses coches : la ligne le dit plutôt que de
        // laisser un vide, qui se lirait comme un contrôle sans auteur.
        return $acteurs->get($identifiant)?->name ?? 'Compte supprimé';
    }
}
