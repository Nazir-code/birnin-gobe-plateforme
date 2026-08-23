<?php

namespace App\Http\Presenters;

use App\Domain\Campaign\CampaignLifecycle;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Eligibility\CampaignEligibilityRules;
use App\Domain\Eligibility\EligibilityRule;
use App\Models\Campaign;

/**
 * Met une campagne en forme pour les props Inertia de l'administration.
 *
 * Même contrat que `ApplicationPresenter` : les dates partent en ISO 8601 et le
 * navigateur les formate, les statuts partent comme valeurs d'enum accompagnées
 * de leur libellé. Aucun libellé français n'est une valeur métier (ADR-004).
 */
final readonly class CampaignPresenter
{
    public function __construct(private CampaignLifecycle $cycle) {}

    /**
     * @param  bool  $active  vrai si `ActiveCampaign` désigne cette campagne
     * @return array{
     *     id: int, code: string, name: string,
     *     status: string, statusLabel: string,
     *     timezone: string, opensAt: string|null, closesAt: string|null,
     *     updatedAt: string|null, window: string, active: bool,
     *     editUrl: string, eligibilityUrl: string, criteriaPublished: int, criteriaTotal: int
     * }
     */
    public function row(Campaign $campagne, bool $active): array
    {
        $criteres = CampaignEligibilityRules::forCampaign($campagne);

        return [
            'id' => $campagne->getKey(),
            'code' => $campagne->code,
            'name' => $campagne->name,
            'status' => $campagne->status->value,
            'statusLabel' => $campagne->status->label(),
            'timezone' => $campagne->timezone,
            'opensAt' => $campagne->opens_at?->toIso8601String(),
            'closesAt' => $campagne->closes_at?->toIso8601String(),
            'updatedAt' => $campagne->updated_at?->toIso8601String(),
            'window' => $this->fenetre($campagne),
            'active' => $active,
            'editUrl' => route('admin.campaigns.edit', $campagne),
            'eligibilityUrl' => route('admin.campaigns.eligibility.edit', $campagne),
            // Combien des cinq règles cette édition a réellement arrêtées. La
            // liste des campagnes le montre parce qu'une campagne ouverte dont
            // aucun critère n'est publié n'écarte personne : c'est une
            // information d'exploitation, pas un détail de configuration.
            'criteriaPublished' => $this->criteresPublies($criteres),
            'criteriaTotal' => count(EligibilityRule::cases()),
        ];
    }

    /** @see EligibilityRule les cinq règles évaluées par le moteur */
    private function criteresPublies(CampaignEligibilityRules $regles): int
    {
        return count(array_filter([
            $regles->hasAgeRange(),
            $regles->requiresNigerLink !== null,
            $regles->regions !== null,
            $regles->candidateTypes !== null,
            $regles->hasTeamSizeRange(),
        ]));
    }

    /**
     * Le formulaire d'édition : valeurs telles que les attend `datetime-local`,
     * donc lues dans le fuseau de la campagne et non dans celui du serveur.
     *
     * @return array{
     *     id: int|null, code: string, name: string, status: string,
     *     timezone: string, opensAt: string, closesAt: string,
     *     statusOptions: list<array{value: string, label: string}>,
     *     eligibilityUrl: string|null
     * }
     */
    public function form(?Campaign $campagne): array
    {
        $statut = $campagne?->status ?? CampaignStatus::DRAFT;

        return [
            'id' => $campagne?->getKey(),
            'code' => $campagne->code ?? '',
            'name' => $campagne->name ?? '',
            'status' => $statut->value,
            'timezone' => $campagne->timezone ?? config('app.timezone', 'Africa/Niamey'),
            'opensAt' => $this->saisie($campagne, 'opens_at'),
            'closesAt' => $this->saisie($campagne, 'closes_at'),
            'statusOptions' => $this->statutsProposables($campagne),
            // Absente à la création : les critères se fixent sur une campagne
            // qui existe, et proposer le lien avant l'enregistrement ferait
            // perdre la saisie en cours.
            'eligibilityUrl' => $campagne === null
                ? null
                : route('admin.campaigns.eligibility.edit', $campagne),
        ];
    }

    /**
     * Statuts que le formulaire a le droit de proposer.
     *
     * Ce n'est qu'un confort d'affichage : `SaveCampaign` revalide la transition
     * côté serveur. Une liste réduite n'est jamais une autorisation (ADR-003).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statutsProposables(?Campaign $campagne): array
    {
        // À la création, seuls « préparation » et « ouverte » ont un sens : une
        // campagne close ou archivée qui n'aurait jamais existé n'en a aucun.
        $statuts = $campagne === null
            ? [CampaignStatus::DRAFT, CampaignStatus::OPEN]
            : $this->cycle->atteignablesDepuis($campagne->status);

        return array_map(
            static fn (CampaignStatus $statut): array => [
                'value' => $statut->value,
                'label' => $statut->label(),
            ],
            $statuts,
        );
    }

    /**
     * État de la fenêtre de dépôt, indépendamment du statut administratif.
     *
     * Les deux se combinent : une campagne n'accepte de candidature que
     * déclarée `OPEN` **et** dans sa fenêtre — c'est la règle d'`ActiveCampaign`.
     * Les distinguer permet de dire à l'administrateur *pourquoi* une campagne
     * ouverte ne reçoit rien.
     */
    private function fenetre(Campaign $campagne): string
    {
        if ($campagne->opens_at === null) {
            return 'sans-calendrier';
        }

        if ($campagne->opens_at->getTimestamp() > now()->getTimestamp()) {
            return 'a-venir';
        }

        if ($campagne->closes_at !== null && $campagne->closes_at->getTimestamp() < now()->getTimestamp()) {
            return 'echue';
        }

        return 'en-cours';
    }

    private function saisie(?Campaign $campagne, string $champ): string
    {
        $instant = $campagne?->{$champ};

        if ($instant === null) {
            return '';
        }

        return $instant->setTimezone(new \DateTimeZone($campagne->timezone))->format('Y-m-d\TH:i');
    }
}
