<?php

namespace App\Domain\Alerting;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Evaluation\EvaluationSettings;
use App\Domain\Verification\AdmissibilityDecision;
use App\Models\Application;
use App\Models\Attachment;
use App\Models\Campaign;
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
 * **Ce qui n'est pas alerté, et pourquoi.** Le §13.1 cite « échecs de
 * notification » ; aucun envoi n'existe, donc rien n'échoue, et une alerte
 * toujours à zéro apprendrait à ignorer l'écran. Le §9.3 cite aussi les
 * « anomalies » de saisie ; celles que la plateforme sait voir sont déjà
 * présentées dossier par dossier au vérificateur (`AutomaticFindings`), et les
 * remonter ici en doublon disperserait la même information sur deux écrans.
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
     * @return list<Alert>
     */
    public function pour(?Campaign $campagne): array
    {
        $alertes = array_filter([
            $this->controleEnRetard($campagne),
            $this->clarificationsDepassees($campagne),
            $this->recevablesSansEvaluateur($campagne),
            $this->dossiersSousCouverts($campagne),
            $this->clotureImminente($campagne),
            $this->clotureFranchieAvecFileOuverte($campagne),
            $this->piecesNonAnalysees($campagne),
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
        $compte = Attachment::query()
            ->where('scan_status', '!=', AttachmentScanStatus::CLEAN->value)
            ->when($campagne !== null, fn (Builder $q) => $q->whereHas(
                'application',
                fn (Builder $a) => $a->where('campaign_id', $campagne->getKey()),
            ))
            ->count();

        if ($compte === 0) {
            return null;
        }

        return new Alert(
            key: 'pieces.non_analysees',
            severity: AlertSeverity::INFO,
            label: 'Pièces jamais analysées',
            detail: sprintf('%d pièce(s) déposée(s) n’ont fait l’objet d’aucune analyse antivirus.', $compte),
            action: 'Ouvrir les pièces dans la visionneuse, jamais depuis le poste de travail. L’analyse antivirus n’est pas branchée.',
            count: $compte,
            url: null,
        );
    }

    /** @return Builder<Application> */
    private function dossiers(?Campaign $campagne): Builder
    {
        return Application::query()
            ->when($campagne !== null, fn (Builder $q) => $q->where('campaign_id', $campagne->getKey()));
    }
}
