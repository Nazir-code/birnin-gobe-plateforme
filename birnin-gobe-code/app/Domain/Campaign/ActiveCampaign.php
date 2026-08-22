<?php

namespace App\Domain\Campaign;

use App\Models\Campaign;

/**
 * Résout la campagne à laquelle une nouvelle candidature se rattache.
 *
 * Centralisé ici plutôt que dans un contrôleur : la campagne détermine à la
 * fois le droit de déposer et l'unicité `(campaign_id, candidate_id)`. Le
 * navigateur ne la choisit jamais.
 *
 * Une campagne n'est active que si elle est déclarée ouverte ET dans sa fenêtre
 * de dates. Les bornes nulles valent « pas de borne » : une campagne ouverte
 * sans date de clôture reste ouverte.
 */
final class ActiveCampaign
{
    public function resolve(): ?Campaign
    {
        return Campaign::query()
            ->where('status', CampaignStatus::OPEN->value)
            ->where(fn ($query) => $query->whereNull('opens_at')->orWhere('opens_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('closes_at')->orWhere('closes_at', '>=', now()))
            ->orderByDesc('opens_at')
            ->orderByDesc('id')
            ->first();
    }
}
