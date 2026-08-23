<?php

namespace App\Domain\Campaign;

use DomainException;

/**
 * Transitions légales du statut d'une campagne (ADR-007).
 *
 * Le cahier des charges (§9.2) impose un statut administrable ; il ne décrit
 * pas le cycle. Celui-ci est déduit de ce que le statut commande réellement :
 * `ActiveCampaign` n'accepte de candidature que sur une campagne `OPEN`.
 *
 *     DRAFT ──→ OPEN ──→ CLOSED ──→ ARCHIVED
 *       │                  │
 *       └──→ ARCHIVED ←────┘
 *
 * Volontairement plus permissif qu'`ApplicationStateMachine` sur un point :
 * `CLOSED → OPEN` est autorisé. Une clôture est une décision administrative,
 * pas un fait irréversible — prolonger un délai annoncé, ou revenir sur une
 * clôture déclenchée d'un jour trop tôt, sont des situations réelles. L'interdire
 * obligerait à corriger la base à la main, ce qui est moins sûr, pas plus.
 *
 * Ce qui reste interdit :
 *  - `OPEN → DRAFT` : revenir en préparation alors que des dossiers existent
 *    déjà n'a pas de sens, et effacerait la lisibilité du calendrier.
 *  - `OPEN → ARCHIVED` : on archive ce qui est clos, on ne saute pas la clôture.
 *  - toute sortie d'`ARCHIVED` : l'archivage est l'état terminal.
 */
final class CampaignLifecycle
{
    /** @var array<string, list<CampaignStatus>> */
    private const TRANSITIONS = [
        CampaignStatus::DRAFT->value => [CampaignStatus::OPEN, CampaignStatus::ARCHIVED],
        CampaignStatus::OPEN->value => [CampaignStatus::CLOSED],
        CampaignStatus::CLOSED->value => [CampaignStatus::OPEN, CampaignStatus::ARCHIVED],
        CampaignStatus::ARCHIVED->value => [],
    ];

    /**
     * Statuts atteignables depuis `$depuis`, celui d'origine compris.
     *
     * Le statut courant est inclus parce que l'écran d'édition sert aussi à
     * corriger un nom ou une date sans toucher au statut : le formulaire doit
     * pouvoir le renvoyer tel quel.
     *
     * @return list<CampaignStatus>
     */
    public function atteignablesDepuis(CampaignStatus $depuis): array
    {
        return [$depuis, ...self::TRANSITIONS[$depuis->value]];
    }

    public function peutPasser(CampaignStatus $depuis, CampaignStatus $vers): bool
    {
        return in_array($vers, $this->atteignablesDepuis($depuis), strict: true);
    }

    /**
     * @throws DomainException si la transition n'est pas prévue par le cycle
     */
    public function assertPeutPasser(CampaignStatus $depuis, CampaignStatus $vers): void
    {
        if (! $this->peutPasser($depuis, $vers)) {
            throw new DomainException(sprintf(
                'CAMPAIGN_TRANSITION_ILLEGALE: %s -> %s',
                $depuis->value,
                $vers->value,
            ));
        }
    }
}
