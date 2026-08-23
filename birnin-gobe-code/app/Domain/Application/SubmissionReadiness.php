<?php

namespace App\Domain\Application;

use App\Domain\Campaign\CampaignStatus;
use App\Domain\Eligibility\EligibilityOutcome;
use App\Domain\Eligibility\EvaluateEligibility;
use App\Models\Application;
use App\Models\Campaign;
use Illuminate\Support\Carbon;

/**
 * Une candidature est-elle officiellement déposable, et sinon pourquoi.
 *
 * **À ne pas confondre avec `ApplicationProgress`.** Les deux comptent des
 * sections, mais ne répondent pas à la même question, et les confondre serait
 * la faute la plus coûteuse de cette phase :
 *
 *   `ApplicationProgress`   « où en est le candidat dans son formulaire ? »
 *                           Se mesure sur le **parcours ouvert** — ce que le
 *                           produit propose aujourd'hui. Elle atteint 100 %
 *                           quand les étapes ouvertes sont faites.
 *
 *   `SubmissionReadiness`   « ce dossier peut-il être déposé ? »
 *                           Se mesure sur le **dossier final** — ce que le
 *                           concours exige, que ce soit développé ou non.
 *
 * Tant que les étapes 5 à 8 n'ont pas d'écran, un candidat peut légitimement
 * afficher une progression pleine sur le parcours ouvert *et* rester non
 * déposable. C'est voulu : lier le dépôt au parcours ouvert ouvrirait la
 * soumission de dossiers amputés de la solution, de l'impact et du plan de mise
 * en œuvre — irrattrapable une fois le numéro attribué.
 *
 * Conséquence assumée aujourd'hui : **aucune candidature n'est déposable**, et
 * la route de dépôt refuse. Le moteur existe, il est testé, et il s'ouvrira de
 * lui-même à mesure que les sections seront livrées — sans qu'une ligne d'ici
 * ne change.
 *
 * Le verdict n'est jamais persisté : il se recalcule à chaque lecture, comme
 * `EligibilityAssessment`. Un dossier figé « déposable » deviendrait faux le
 * jour où la campagne se clôt.
 */
final readonly class SubmissionReadiness
{
    /**
     * @param  list<SubmissionBlocker>  $blockers
     * @param  list<ApplicationSection>  $missingSections
     */
    private function __construct(
        public bool $ready,
        public array $blockers,
        public array $missingSections,
        public EligibilityOutcome $eligibility,
    ) {}

    /**
     * Les sections sans lesquelles le dossier n'est pas un dossier.
     *
     * Les neuf étapes du concours, **moins « Relecture / envoi »**. Cette
     * dernière n'est pas une section de contenu : c'est l'écran depuis lequel on
     * dépose. L'exiger achevée pour autoriser le dépôt demanderait au candidat
     * de terminer l'envoi avant de pouvoir envoyer — la condition ne serait
     * jamais satisfaite, et le dépôt resterait fermé pour toujours.
     *
     * Les déclarations et pièces, elles, restent exigées : elles vivent à
     * l'étape 8 « Pièces / déclarations », qui est bien du contenu.
     *
     * @return list<ApplicationSection>
     */
    public static function requiredSections(): array
    {
        return array_values(array_filter(
            ApplicationSection::cases(),
            static fn (ApplicationSection $section): bool => $section !== ApplicationSection::REVIEW,
        ));
    }

    /**
     * Verdict pour une candidature, à l'instant où on le demande.
     *
     * L'éligibilité est celle de **la campagne du dossier** — jamais celle de
     * la campagne active. Un dossier déposé sous les règles 2026 se juge sur
     * les règles 2026, même si une autre édition est ouverte entre-temps
     * (ADR-007).
     */
    public static function for(Application $application, EvaluateEligibility $moteur): self
    {
        $motifs = [];

        if ($application->status !== ApplicationStatus::DRAFT) {
            $motifs[] = SubmissionBlocker::ALREADY_SUBMITTED;
        }

        foreach (self::motifsDeCampagne($application->campaign) as $motif) {
            $motifs[] = $motif;
        }

        $verdict = $moteur->forApplication($application);

        if ($verdict->outcome->blocksNextSections()) {
            $motifs[] = SubmissionBlocker::ELIGIBILITY_BLOCKING;
        }

        // Redondant en pratique — l'étape 1 ne peut pas être achevée avec des
        // réponses manquantes, donc `SECTIONS_INCOMPLETE` couvrirait déjà le
        // cas. Nommé quand même : un dossier ne part pas au jury avec des
        // questions d'éligibilité sans réponse, et le motif exact vaut mieux
        // qu'un « étapes non terminées » qui enverrait chercher ailleurs.
        if ($verdict->outcome === EligibilityOutcome::INCOMPLETE) {
            $motifs[] = SubmissionBlocker::ELIGIBILITY_INCOMPLETE;
        }

        $manquantes = self::sectionsManquantes($application);

        if ($manquantes !== []) {
            $motifs[] = SubmissionBlocker::SECTIONS_INCOMPLETE;
        }

        return new self(
            ready: $motifs === [],
            blockers: array_values($motifs),
            missingSections: $manquantes,
            eligibility: $verdict->outcome,
        );
    }

    /**
     * Fenêtre de dépôt de l'édition, jugée dans **son** fuseau.
     *
     * Les colonnes sont des `timestamptz` : PostgreSQL les stocke en UTC et
     * Laravel les rend en `CarbonImmutable`. La comparaison est donc déjà juste
     * à l'instant près, quel que soit le fuseau du serveur — c'est le sens du
     * `tz`. Les dates sont malgré tout ramenées dans le fuseau de la campagne
     * avant comparaison : une date limite annoncée « le 21 novembre à minuit »
     * l'est à Niamey, et c'est cette lecture-là qui doit faire foi le jour où
     * l'application tournera ailleurs.
     *
     * Une borne nulle vaut « pas de borne » — même convention qu'`ActiveCampaign`.
     *
     * @return list<SubmissionBlocker>
     */
    private static function motifsDeCampagne(?Campaign $campagne): array
    {
        if ($campagne === null) {
            return [SubmissionBlocker::CAMPAIGN_NOT_OPEN];
        }

        if ($campagne->status !== CampaignStatus::OPEN) {
            return [SubmissionBlocker::CAMPAIGN_NOT_OPEN];
        }

        $fuseau = $campagne->timezone ?: config('app.timezone');
        $maintenant = Carbon::now($fuseau);

        if ($campagne->opens_at !== null && $maintenant->lt($campagne->opens_at->setTimezone($fuseau))) {
            return [SubmissionBlocker::CAMPAIGN_NOT_YET_OPEN];
        }

        if ($campagne->closes_at !== null && $maintenant->gt($campagne->closes_at->setTimezone($fuseau))) {
            return [SubmissionBlocker::DEADLINE_PASSED];
        }

        return [];
    }

    /**
     * Sections exigées qui n'ont pas de date d'achèvement.
     *
     * `completed_at` est la seule preuve qu'une section est faite : une ligne
     * de réponses partielles n'en est pas une. Une section non développée n'a
     * évidemment aucune ligne — elle ressort donc manquante, ce qui est le
     * comportement voulu.
     *
     * @return list<ApplicationSection>
     */
    private static function sectionsManquantes(Application $application): array
    {
        $achevees = $application->sections()
            ->whereNotNull('completed_at')
            ->pluck('section')
            ->map(static fn (ApplicationSection $section): string => $section->value)
            ->all();

        return array_values(array_filter(
            self::requiredSections(),
            static fn (ApplicationSection $section): bool => ! in_array($section->value, $achevees, strict: true),
        ));
    }

    /**
     * @return array{
     *     ready: bool,
     *     blockers: list<array{code: string, label: string}>,
     *     missingSections: list<array{key: string, label: string, position: int}>,
     *     eligibility: string
     * }
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->ready,
            'blockers' => array_map(
                static fn (SubmissionBlocker $motif): array => ['code' => $motif->value, 'label' => $motif->label()],
                $this->blockers,
            ),
            'missingSections' => array_map(
                static fn (ApplicationSection $section): array => [
                    'key' => $section->value,
                    'label' => $section->label(),
                    'position' => $section->position(),
                ],
                $this->missingSections,
            ),
            'eligibility' => $this->eligibility->value,
        ];
    }
}
