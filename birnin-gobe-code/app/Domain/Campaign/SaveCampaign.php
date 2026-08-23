<?php

namespace App\Domain\Campaign;

use App\Domain\Audit\AuditWriter;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d'une campagne par l'administration (ADR-008).
 *
 * Cas d'usage plutôt que méthode de contrôleur, sur le modèle de
 * `StartApplication` : la transaction, le contrôle du cycle de vie, l'invariant
 * de campagne ouverte et l'écriture d'audit tiennent ensemble ou pas du tout.
 *
 * `settings` n'est pas écrit ici. La colonne existe et le cahier (§9.2) prévoit
 * d'y loger compte à rebours, période de grâce, contacts et textes légaux, mais
 * rien ne les lit encore. Une campagne modifiée conserve donc les siens : une
 * phase qui n'expose pas un champ ne doit pas l'effacer au passage.
 */
final readonly class SaveCampaign
{
    public function __construct(
        private CampaignLifecycle $cycle,
        private AuditWriter $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, status: CampaignStatus, timezone: string, opens_at: ?\DateTimeInterface, closes_at: ?\DateTimeInterface}  $donnees
     */
    public function create(User $administrateur, array $donnees): Campaign
    {
        // Une campagne naît en préparation ou déjà ouverte ; on ne crée pas
        // directement une campagne close ou archivée, qui n'aurait jamais existé.
        if (! in_array($donnees['status'], [CampaignStatus::DRAFT, CampaignStatus::OPEN], strict: true)) {
            throw ValidationException::withMessages([
                'status' => __('Une campagne se crée en préparation ou ouverte.'),
            ]);
        }

        $this->refuserSecondeOuverture($donnees['status'], null);

        return $this->ecrire(function () use ($administrateur, $donnees): Campaign {
            $campagne = new Campaign;
            $campagne->fill($this->colonnes($donnees))->save();

            $this->audit->write(
                actorId: $administrateur->getKey(),
                action: 'CAMPAIGN_CREATED',
                targetType: Campaign::class,
                targetId: (string) $campagne->getKey(),
                oldValue: null,
                newValue: $this->trace($campagne),
                reason: null,
            );

            return $campagne;
        });
    }

    /**
     * @param  array{code: string, name: string, status: CampaignStatus, timezone: string, opens_at: ?\DateTimeInterface, closes_at: ?\DateTimeInterface}  $donnees
     */
    public function update(User $administrateur, Campaign $campagne, array $donnees): Campaign
    {
        $ancien = $campagne->status;
        $nouveau = $donnees['status'];

        // Le cycle de vie décide avant l'écriture. Une transition refusée ne
        // doit pas non plus enregistrer le changement de nom qui l'accompagnait.
        if (! $this->cycle->peutPasser($ancien, $nouveau)) {
            throw ValidationException::withMessages([
                'status' => __('Une campagne :depuis ne peut pas passer à :vers.', [
                    'depuis' => $ancien->label(),
                    'vers' => $nouveau->label(),
                ]),
            ]);
        }

        $this->refuserSecondeOuverture($nouveau, $campagne);

        $avant = $this->trace($campagne);

        return $this->ecrire(function () use ($administrateur, $campagne, $donnees, $ancien, $nouveau, $avant): Campaign {
            $campagne->fill($this->colonnes($donnees))->save();

            // Deux événements distincts quand le statut bouge : un changement de
            // statut est une décision, une correction de libellé n'en est pas
            // une, et les relire mélangés dans le journal ne rendrait service à
            // personne.
            $this->audit->write(
                actorId: $administrateur->getKey(),
                action: $ancien === $nouveau ? 'CAMPAIGN_UPDATED' : 'CAMPAIGN_STATUS_CHANGED',
                targetType: Campaign::class,
                targetId: (string) $campagne->getKey(),
                oldValue: $avant,
                newValue: $this->trace($campagne),
                reason: null,
            );

            return $campagne;
        });
    }

    /**
     * Refuse une seconde campagne ouverte (ADR-008).
     *
     * Double barrière : ce contrôle rend un message de validation lisible dans
     * le cas courant, l'index partiel `campaigns_une_seule_ouverte` tranche la
     * course entre deux requêtes simultanées — voir `ecrire()`.
     */
    private function refuserSecondeOuverture(CampaignStatus $vise, ?Campaign $exclue): void
    {
        if ($vise !== CampaignStatus::OPEN) {
            return;
        }

        $autre = Campaign::query()
            ->where('status', CampaignStatus::OPEN->value)
            ->when($exclue !== null, fn ($query) => $query->whereKeyNot($exclue->getKey()))
            ->first();

        if ($autre !== null) {
            throw ValidationException::withMessages([
                'status' => __('La campagne « :nom » (:code) est déjà ouverte. Clôturez-la avant d\'en ouvrir une autre.', [
                    'nom' => $autre->name,
                    'code' => $autre->code,
                ]),
            ]);
        }
    }

    /**
     * Exécute l'écriture en traduisant les violations d'unicité en erreurs de
     * validation : `code` et l'invariant de campagne ouverte sont tous deux
     * portés par un index, et un 500 n'apprendrait rien à l'administrateur.
     *
     * @param  callable(): Campaign  $ecriture
     */
    private function ecrire(callable $ecriture): Campaign
    {
        try {
            return DB::transaction($ecriture);
        } catch (UniqueConstraintViolationException $violation) {
            throw ValidationException::withMessages(
                str_contains($violation->getMessage(), 'campaigns_une_seule_ouverte')
                    ? ['status' => __('Une autre campagne vient d\'être ouverte. Rechargez la page.')]
                    : ['code' => __('Ce code est déjà utilisé par une autre campagne.')],
            );
        }
    }

    /** @param array<string, mixed> $donnees */
    private function colonnes(array $donnees): array
    {
        return [
            'code' => $donnees['code'],
            'name' => $donnees['name'],
            'status' => $donnees['status'],
            'timezone' => $donnees['timezone'],
            'opens_at' => $donnees['opens_at'],
            'closes_at' => $donnees['closes_at'],
        ];
    }

    /**
     * Ce que le journal d'audit conserve : les champs qui engagent, pas l'objet
     * entier. Les horodatages partent en ISO 8601 pour rester lisibles hors
     * contexte applicatif.
     *
     * @return array<string, string|null>
     */
    private function trace(Campaign $campagne): array
    {
        return [
            'code' => $campagne->code,
            'name' => $campagne->name,
            'status' => $campagne->status->value,
            'timezone' => $campagne->timezone,
            'opens_at' => $campagne->opens_at?->toIso8601String(),
            'closes_at' => $campagne->closes_at?->toIso8601String(),
        ];
    }
}
