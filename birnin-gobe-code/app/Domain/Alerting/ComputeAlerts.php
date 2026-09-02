<?php

namespace App\Domain\Alerting;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Evaluation\DivergenceQuery;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Verification\AdmissibilityDecision;
use App\Models\Application;
use App\Models\Attachment;
use App\Models\Campaign;
use App\Models\NotificationDelivery;
use App\Models\VerificationDecision;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les alertes de pilotage — §9.3, « alertes sur retards et anomalies ».
 *
 * Toutes recalculées à la lecture, toutes chiffrées, toutes reliées à l'écran
 * qui permet d'agir. Une alerte qui ne dit pas combien ni où n'est qu'une
 * inquiétude.
 *
 * **Les seuils de retard sont ici, en constantes nommées, et pas dans un
 * réglage.** Le §9.3 demande des alertes sur les retards sans fixer de délai,
 * et le §9.2 ne fait pas figurer ces seuils parmi les paramètres administrables.
 * Les inventer comme réglage donnerait à croire qu'ils ont été arbitrés ; les
 * écrire ici, nommés et commentés, dit qu'ils sont des valeurs de lancement.
 * Le jour où le comité les fixera, ils rejoindront `campaigns.settings`.
 *
 * **Ce qui n'est pas alerté, et pourquoi.** Le §9.3 cite les « anomalies » de
 * saisie ; celles que la plateforme sait voir sont déjà présentées dossier par
 * dossier au vérificateur (`AutomaticFindings`), et les remonter ici en doublon
 * disperserait la même information sur deux écrans.
 */
final readonly class ComputeAlerts
{
    /**
     * Au-delà, un dossier déposé qui n'a pas été ouvert est un retard.
     *
     * Sept jours : une semaine ouvrée complète. En deçà, on signalerait le
     * fonctionnement normal d'une file.
     */
    public const JOURS_AVANT_RETARD_DE_CONTROLE = 7;

    /** Une clôture qui approche appelle une vérification de la file. */
    public const JOURS_AVANT_CLOTURE = 7;

    /**
     * Au-delà, un message confié au répartiteur et jamais parti est une panne.
     *
     * Une heure : la marge est large pour un envoi qui prend normalement
     * quelques secondes, et c'est voulu. Un seuil serré transformerait chaque
     * pointe de charge — les vingt courriels d'un lot d'affectation, l'afflux
     * de la veille de clôture — en alerte `CRITICAL` qui se résout seule, et un
     * responsable qui voit ce compteur clignoter sans conséquence apprend à ne
     * plus le regarder. Une heure d'attente, en revanche, ne se rattrape pas
     * toute seule : quelque chose est arrêté.
     */
    public const HEURES_AVANT_FILE_SUSPECTE = 1;

    /**
     * @return list<Alert>
     */
    public function pour(?Campaign $campagne): array
    {
        $alertes = array_filter([
            $this->controleEnRetard($campagne),
            $this->clarificationsDepassees($campagne),
            $this->recevablesSansEvaluateur($campagne),
            $this->dossiersSousCouverts($campagne),
            $this->ecartsARevoir($campagne),
            $this->clotureImminente($campagne),
            $this->clotureFranchieAvecFileOuverte($campagne),
            $this->piecesNonAnalysees($campagne),
            $this->piecesEnQuarantaine($campagne),
            $this->notificationsEnEchec($campagne),
            $this->notificationsEnAttente($campagne),
        ]);

        // Le plus grave d'abord ; à gravité égale, le plus nombreux.
        usort($alertes, static fn (Alert $a, Alert $b): int => [$a->severity->rang(), -$a->count] <=> [$b->severity->rang(), -$b->count]);

        return array_values($alertes);
    }

    /** Dossiers déposés qu'aucun vérificateur n'a ouverts depuis trop longtemps. */
    private function controleEnRetard(?Campaign $campagne): ?Alert
    {
        $compte = $this->dossiers($campagne)
            ->where('status', ApplicationStatus::SUBMITTED->value)
            ->where('submitted_at', '<', now()->subDays(self::JOURS_AVANT_RETARD_DE_CONTROLE))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'controle.retard',
            severity: AlertSeverity::WARNING,
            label: 'Dossiers en attente de contrôle depuis plus de '.self::JOURS_AVANT_RETARD_DE_CONTROLE.' jours',
            detail: sprintf('%d dossier(s) déposé(s) n’ont pas encore été ouverts par un vérificateur.', $compte),
            action: 'Ouvrir la file de vérification et reprendre par les plus anciens.',
            count: $compte,
            url: route('admin.verification.index'),
        );
    }

    /**
     * Clarifications dont la date limite est passée.
     *
     * C'est l'alerte la plus grave de l'écran, et pour une raison précise : un
     * délai que l'administration a elle-même fixé au candidat, puis laissé
     * expirer sans suite, laisse ce candidat sans réponse et sans recours.
     */
    private function clarificationsDepassees(?Campaign $campagne): ?Alert
    {
        $compte = VerificationDecision::query()
            ->where('decision', AdmissibilityDecision::CLARIFICATION->value)
            ->whereNotNull('respond_by')
            ->whereDate('respond_by', '<', now()->toDateString())
            ->whereHas('application', function (Builder $q) use ($campagne): void {
                $q->where('status', ApplicationStatus::CLARIFICATION_REQUESTED->value);

                if ($campagne !== null) {
                    $q->where('campaign_id', $campagne->getKey());
                }
            })
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'clarification.depassee',
            severity: AlertSeverity::CRITICAL,
            label: 'Délais de clarification dépassés',
            detail: sprintf('%d dossier(s) attendent une réponse dont la date limite est passée.', $compte),
            action: 'Trancher ces dossiers ou prolonger le délai : un candidat ne doit pas rester sans suite.',
            count: $compte,
            url: route('admin.verification.index', ['status' => ApplicationStatus::CLARIFICATION_REQUESTED->value]),
        );
    }

    /** Dossiers recevables auxquels personne n'a été affecté. */
    private function recevablesSansEvaluateur(?Campaign $campagne): ?Alert
    {
        $compte = $this->dossiers($campagne)
            ->where('status', ApplicationStatus::ADMISSIBLE->value)
            ->whereDoesntHave('assignments', fn (Builder $q) => $q->whereNull('released_at'))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'evaluation.sans_evaluateur',
            severity: AlertSeverity::WARNING,
            label: 'Dossiers recevables sans évaluateur',
            detail: sprintf('%d dossier(s) déclarés recevables n’ont encore été confiés à personne.', $compte),
            action: 'Répartir ces dossiers depuis l’écran des évaluateurs.',
            count: $compte,
            url: route('admin.evaluators.index'),
        );
    }

    /**
     * Dossiers affectés en deçà du minimum d'évaluations.
     *
     * Silencieuse tant que le minimum n'est pas arrêté : sans seuil décidé, il
     * n'y a pas de sous-couverture, seulement une inconnue. Alerter sur un
     * seuil inventé ferait courir après un objectif que personne n'a fixé.
     */
    private function dossiersSousCouverts(?Campaign $campagne): ?Alert
    {
        $reglages = EvaluationSettings::fromCampaign($campagne);

        if ($reglages->minEvaluations === null) {
            return null;
        }

        $compte = $this->dossiers($campagne)
            ->whereIn('status', [
                ApplicationStatus::ADMISSIBLE->value,
                ApplicationStatus::IN_EVALUATION->value,
            ])
            ->withCount(['assignments as en_vigueur' => fn (Builder $q) => $q->whereNull('released_at')])
            ->get()
            ->filter(static fn (Application $dossier): bool => (int) $dossier->en_vigueur < $reglages->minEvaluations)
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'evaluation.sous_couverture',
            severity: AlertSeverity::WARNING,
            label: 'Dossiers sous le minimum d’évaluations',
            detail: sprintf(
                '%d dossier(s) portent moins de %d évaluation(s) en vigueur.',
                $compte,
                $reglages->minEvaluations,
            ),
            action: 'Compléter les affectations pour atteindre le minimum arrêté pour cette campagne.',
            count: $compte,
            url: route('admin.evaluators.index'),
        );
    }

    /**
     * Dossiers dont l'écart entre évaluateurs dépasse le seuil et attend une revue.
     *
     * Silencieuse tant que le seuil n'est pas arrêté, exactement comme la
     * sous-couverture : sans seuil décidé, aucun écart n'est excessif — il est
     * seulement non comparé. Signaler une divergence contre un seuil inventé
     * ferait rouvrir des notations qui n'avaient rien d'anormal.
     *
     * Elle est `WARNING` et non `CRITICAL` : un écart n'a de conséquence ni
     * pour un candidat ni pour le calendrier tant que la présélection n'est pas
     * close. Il en aurait une si l'on classait sans l'avoir arbitré, mais le
     * classement relève du §12, qui n'existe pas encore.
     */
    private function ecartsARevoir(?Campaign $campagne): ?Alert
    {
        if (EvaluationSettings::fromCampaign($campagne)->scoreGapThreshold === null) {
            return null;
        }

        $compte = DivergenceQuery::totalARevoir($campagne);

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'evaluation.ecarts_a_revoir',
            severity: AlertSeverity::WARNING,
            label: 'Écarts de notation à revoir',
            detail: sprintf(
                '%d dossier(s) portent un écart entre évaluateurs supérieur au seuil arrêté.',
                $compte,
            ),
            action: 'Comparer les notations et arbitrer : demander un avis de plus, ou acter le désaccord (§11.3).',
            count: $compte,
            url: route('admin.divergences.index'),
        );
    }

    /**
     * Notifications dont l'envoi a échoué — §9.3, §8.3.
     *
     * ADR-014 avait explicitement écarté cette alerte : aucun envoi n'existait,
     * donc rien ne pouvait échouer, et un compteur bloqué à zéro apprend à
     * ignorer l'écran. Les envois existent désormais, et l'alerte avec eux.
     *
     * **Ne compte que les échecs, jamais les canaux non servis.** Un SMS qui ne
     * part pas faute de fournisseur n'est pas une panne : c'est une décision qui
     * n'a pas été prise, et elle se lit dans les paramètres du §9.2. La compter
     * ici produirait une alerte permanente, exactement ce qu'ADR-014 refusait.
     *
     * `CRITICAL` : un candidat qu'on n'a pas pu prévenir d'un rejet ou d'un
     * délai de réponse subit une conséquence réelle, et le temps joue contre
     * lui. C'est la définition que `AlertSeverity` donne de ce niveau.
     */
    private function notificationsEnEchec(?Campaign $campagne): ?Alert
    {
        $compte = NotificationDelivery::query()
            ->enEchec()
            ->when($campagne !== null, fn (Builder $q) => $q->where('campaign_id', $campagne->getKey()))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'notifications.echecs',
            severity: AlertSeverity::CRITICAL,
            label: 'Notifications non délivrées',
            detail: sprintf('%d message(s) n’ont pas pu être envoyés à leur destinataire.', $compte),
            action: 'Vérifier la configuration d’envoi, puis prévenir les personnes concernées par un autre moyen.',
            count: $compte,
            url: null,
        );
    }

    /**
     * Des messages confiés au répartiteur et jamais partis — §9.3.
     *
     * **C'est la seule alerte qui voit un `worker` arrêté.** Un envoi qui
     * échoue produit un `FAILED`, et l'alerte précédente le remonte ; un envoi
     * que personne ne dépile ne produit rien du tout. Sans ce compteur, la
     * panne la plus totale — le conteneur `worker` à l'arrêt, aucune
     * notification ne partant plus — serait aussi la plus silencieuse : tous
     * les écrans resteraient verts pendant que plus aucun candidat n'est
     * prévenu de quoi que ce soit.
     *
     * `CRITICAL`, pour la même raison que les échecs : un candidat qu'on n'a
     * pas prévenu d'un rejet ou d'un délai de réponse subit une conséquence
     * réelle, et le temps joue contre lui. Que le message soit tombé ou qu'il
     * dorme dans une file ne change rien pour lui.
     *
     * **Non filtrée par campagne.** Une file arrêtée l'est pour tout le monde,
     * et le message de création de compte — qui n'a pas de campagne — serait
     * invisible sous un filtre. L'action à mener n'est pas non plus de l'ordre
     * de l'édition en cours : elle est sur le serveur.
     */
    private function notificationsEnAttente(?Campaign $campagne): ?Alert
    {
        $compte = NotificationDelivery::query()
            ->enAttenteDepuis(now()->subHours(self::HEURES_AVANT_FILE_SUSPECTE))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'notifications.file_bloquee',
            severity: AlertSeverity::CRITICAL,
            label: 'Messages en attente d’envoi',
            detail: sprintf(
                '%d message(s) sont confiés depuis plus de %d heure(s) sans être partis.',
                $compte,
                self::HEURES_AVANT_FILE_SUSPECTE,
            ),
            action: 'Vérifier que le service d’envoi en file d’attente tourne, puis reprendre les messages en attente.',
            count: $compte,
            url: null,
        );
    }

    /** La clôture approche : la file doit être à jour avant l'afflux final. */
    private function clotureImminente(?Campaign $campagne): ?Alert
    {
        $cible = $campagne ?? Campaign::query()->where('status', CampaignStatus::OPEN->value)->first();
        $cloture = $cible?->closes_at;

        if ($cloture === null || $cloture->isPast()) {
            return null;
        }

        $jours = (int) now()->diffInDays($cloture);

        if ($jours > self::JOURS_AVANT_CLOTURE) {
            return null;
        }

        return new Alert(
            key: 'campagne.cloture_imminente',
            severity: AlertSeverity::INFO,
            label: 'Clôture des dépôts imminente',
            detail: sprintf('La campagne « %s » ferme dans %d jour(s).', $cible->name, $jours),
            action: 'Vider la file de vérification avant l’afflux des derniers dépôts.',
            count: $jours,
            url: route('admin.campaigns.edit', $cible),
        );
    }

    /** Clôture passée alors que des dossiers attendent encore un contrôle. */
    private function clotureFranchieAvecFileOuverte(?Campaign $campagne): ?Alert
    {
        $cible = $campagne ?? Campaign::query()->where('status', CampaignStatus::OPEN->value)->first();

        if ($cible?->closes_at === null || ! $cible->closes_at->isPast()) {
            return null;
        }

        $compte = Application::query()
            ->where('campaign_id', $cible->getKey())
            ->whereIn('status', [
                ApplicationStatus::SUBMITTED->value,
                ApplicationStatus::PENDING_REVIEW->value,
            ])
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'campagne.cloture_franchie',
            severity: AlertSeverity::CRITICAL,
            label: 'Clôture passée, file de contrôle non vidée',
            detail: sprintf(
                'La campagne « %s » est close et %d dossier(s) attendent encore une décision d’admissibilité.',
                $cible->name,
                $compte,
            ),
            action: 'Trancher ces dossiers : le calendrier d’évaluation en dépend.',
            count: $compte,
            url: route('admin.verification.index'),
        );
    }

    /**
     * Pièces jamais analysées.
     *
     * Cette alerte ne se résout pas par un geste de gestion : elle dit qu'aucun
     * antivirus n'est branché. Elle reste en `INFO` pour cette raison — la
     * hisser en critique ferait crier au loup tous les jours sans que personne
     * ne puisse l'éteindre depuis cet écran.
     */
    private function piecesNonAnalysees(?Campaign $campagne): ?Alert
    {
        $compte = $this->pieces($campagne)
            ->whereIn('scan_status', array_map(
                static fn (AttachmentScanStatus $etat): string => $etat->value,
                array_filter(
                    AttachmentScanStatus::bloquants(),
                    static fn (AttachmentScanStatus $etat): bool => $etat !== AttachmentScanStatus::QUARANTINE,
                ),
            ))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'pieces.non_analysees',
            severity: AlertSeverity::WARNING,
            label: 'Pièces sans verdict antivirus',
            detail: sprintf('%d pièce(s) attendent un verdict : elles ne sont pas téléchargeables.', $compte),
            action: AttachmentScanStatus::derogationActive()
                ? 'Dérogation active : ces pièces sont ouvertes aux rôles internes sans analyse, et chaque accès est journalisé.'
                : 'Diagnostiquer avec « php artisan attachments:status » : la file et l’absence d’analyseur n’appellent pas le même geste.',
            count: $compte,
            url: null,
        );
    }

    /**
     * Pièces mises en quarantaine — §15.1.
     *
     * Séparée de la précédente, et plus grave : « on ne sait pas encore » et
     * « une menace a été trouvée » n'appellent pas le même geste. Les fondre
     * dans un seul compteur ferait disparaître le second cas dans le premier, et
     * c'est le second qui demande qu'on prévienne le candidat et qu'on décide du
     * sort de son dossier.
     *
     * Elle n'est pas `CRITICAL` : le fichier ne peut plus être téléchargé par
     * personne, donc la menace est déjà contenue. Ce qui reste à faire est
     * administratif.
     */
    private function piecesEnQuarantaine(?Campaign $campagne): ?Alert
    {
        $compte = $this->pieces($campagne)
            ->where('scan_status', AttachmentScanStatus::QUARANTINE->value)
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'pieces.quarantaine',
            severity: AlertSeverity::WARNING,
            label: 'Pièces en quarantaine',
            detail: sprintf('%d pièce(s) déposée(s) portent une menace détectée par l’antivirus.', $compte),
            action: 'Le téléchargement est déjà fermé. Demander au candidat de redéposer la pièce, sans jamais ouvrir l’originale.',
            count: $compte,
            url: null,
        );
    }

    /** @return Builder<Attachment> */
    private function pieces(?Campaign $campagne): Builder
    {
        return Attachment::query()->when($campagne !== null, fn (Builder $q) => $q->whereHas(
            'application',
            fn (Builder $a) => $a->where('campaign_id', $campagne->getKey()),
        ));
    }

    /** @return Builder<Application> */
    private function dossiers(?Campaign $campagne): Builder
    {
        return Application::query()
            ->when($campagne !== null, fn (Builder $q) => $q->where('campaign_id', $campagne->getKey()));
    }
}
