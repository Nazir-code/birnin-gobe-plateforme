<?php

namespace App\Domain\Application;

use App\Domain\Audit\AuditWriter;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Domain\Notification\NotificationEvent;
use App\Domain\Notification\SendNotification;
use App\Models\Application;
use App\Models\User;
use App\Notifications\Candidat\SoumissionRecue;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Dépôt officiel d'une candidature — `DRAFT → SUBMITTED`.
 *
 * Le seul point du code où un dossier cesse d'être modifiable et reçoit son
 * numéro. Tout y est fait ou rien : numéro, copie figée, horodatage, statut et
 * journal partent dans la même transaction. Un dossier à demi soumis — un
 * numéro attribué sans copie, un statut changé sans journal — serait impossible
 * à rattraper proprement, et le candidat n'aurait aucun moyen de le savoir.
 *
 * Trois garanties, et la façon dont elles tiennent :
 *
 *  - **Atomicité** : une seule `DB::transaction`.
 *  - **Un seul dépôt** : la ligne est verrouillée (`lockForUpdate`) avant
 *    d'être relue. Deux requêtes simultanées — double-clic, renvoi réseau — ne
 *    peuvent pas lire toutes les deux `DRAFT` : la seconde attend la première,
 *    la voit `SUBMITTED`, et ressort sans rien écrire.
 *  - **Idempotence** : cette seconde tentative ne lève pas d'erreur. Elle rend
 *    la candidature déjà déposée, telle quelle. Un candidat qui a cliqué deux
 *    fois a déposé une fois, et ne doit pas voir un échec.
 *
 * Ce qui n'est pas ici, volontairement : l'accusé de réception par courriel et
 * le reçu PDF. Ils appartiennent à la file d'attente, après le `commit` — les
 * déclencher dans la transaction enverrait un accusé pour un dépôt qui peut
 * encore être annulé.
 */
final readonly class SubmitApplication
{
    public function __construct(
        private ApplicationStateMachine $stateMachine,
        private EvaluateEligibility $eligibilite,
        private AuditWriter $audit,
        private SendNotification $notifier,
    ) {}

    /**
     * @throws DomainException si le dossier n'est pas déposable
     */
    public function handle(Application $application, User $actor): Application
    {
        $depose = DB::transaction(function () use ($application, $actor): Application {
            // Relecture sous verrou : à partir d'ici, aucune autre requête ne
            // peut décider du sort de ce dossier avant le `commit`.
            $verrouille = Application::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $verrouille->load(['candidate', 'campaign', 'sections']);

            // Déjà déposé : on ressort tel quel. Pas de second numéro, pas de
            // seconde copie, pas de second événement d'audit.
            if ($verrouille->status !== ApplicationStatus::DRAFT) {
                return $verrouille;
            }

            $this->assertDeposable($verrouille, $actor);

            // La machine à états reste consultée même si la garde ci-dessus a
            // déjà écarté tout autre statut : c'est elle qui fait foi sur les
            // transitions légales, et la contourner ici créerait un second
            // endroit où l'on décide de ce qui est permis.
            $this->stateMachine->assertCanTransition($verrouille->status, ApplicationStatus::SUBMITTED);

            $numero = SubmissionNumber::next($verrouille->campaign);
            $depuis = now();

            $verrouille->forceFill([
                'status' => ApplicationStatus::SUBMITTED->value,
                'submission_number' => $numero,
                'submitted_at' => $depuis,
                'submitted_snapshot' => SubmissionSnapshot::build(
                    $verrouille,
                    $this->eligibilite->forApplication($verrouille),
                    $numero,
                    $depuis->toIso8601String(),
                ),
            ])->save();

            // Le journal porte la décision, pas le dossier : la copie complète
            // vit déjà dans `submitted_snapshot`, et la recopier ici gonflerait
            // le journal métier sans rien y ajouter de consultable.
            $this->audit->write(
                actorId: $actor->getKey(),
                action: 'APPLICATION_SUBMITTED',
                targetType: Application::class,
                targetId: (string) $verrouille->getKey(),
                oldValue: ['status' => ApplicationStatus::DRAFT->value],
                newValue: [
                    'status' => ApplicationStatus::SUBMITTED->value,
                    'submission_number' => $numero,
                    'submitted_at' => $depuis->toIso8601String(),
                ],
                reason: null,
            );

            return $verrouille;
        });

        // §8.3, ligne 3. Après le `commit`, jamais dedans : une transaction qui
        // échouerait après l'envoi laisserait un candidat en possession d'un
        // accusé de dépôt pour un dossier non déposé.
        $this->notifier->handle(
            evenement: NotificationEvent::SUBMISSION_RECEIVED,
            destinataire: $depose->candidate()->first() ?? $actor,
            message: new SoumissionRecue($depose),
            dossier: $depose,
        );

        return $depose;
    }

    /**
     * Le serveur reste l'autorité.
     *
     * Le même moteur que celui qui renseigne l'écran est rejoué ici, sous
     * verrou : une campagne peut s'être close, une règle d'éligibilité avoir
     * changé, une section avoir été vidée entre l'affichage du bouton et le
     * clic. Ce qui a été montré au candidat n'engage pas le dépôt ; ce qui est
     * vrai à l'instant de l'écriture, si.
     *
     * @throws DomainException
     */
    private function assertDeposable(Application $application, User $actor): void
    {
        // L'appartenance est déjà tenue par `ApplicationPolicy` sur la route.
        // Elle est revérifiée ici parce que ce cas d'usage est appelable hors
        // HTTP — une commande, une reprise — où aucune policy ne s'applique.
        if ($application->candidate_id !== $actor->getKey()) {
            throw new DomainException('SUBMISSION_FORBIDDEN');
        }

        $verdict = SubmissionReadiness::for($application, $this->eligibilite);

        if (! $verdict->ready) {
            $motifs = implode(',', array_map(
                static fn (SubmissionBlocker $motif): string => $motif->value,
                $verdict->blockers,
            ));

            throw new DomainException("SUBMISSION_NOT_READY: {$motifs}");
        }
    }
}
