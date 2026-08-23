<?php

use App\Domain\Campaign\CampaignStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Une seule campagne peut porter le statut `OPEN` (ADR-007).
 *
 * `ActiveCampaign` renvoie **une** campagne. Quand plusieurs sont ouvertes, il
 * en choisit une par tri — un départage arbitraire, invisible, qui décide vers
 * quelle campagne partent toutes les candidatures. L'invariant rend ce choix
 * inutile : il ne peut y avoir qu'une candidate.
 *
 * Index partiel plutôt que vérification applicative seule : entre la lecture
 * « aucune autre campagne n'est ouverte » et l'écriture, une requête
 * concurrente peut en ouvrir une. Seule la base tranche cette course. La
 * vérification applicative existe quand même, dans `SaveCampaign`, pour rendre
 * un message de validation plutôt qu'une erreur SQL.
 *
 * L'unicité porte sur `status` restreint aux lignes `OPEN` : toutes ces lignes
 * portant la même valeur, il ne peut y en avoir qu'une.
 */
return new class extends Migration
{
    private const INDEX = 'campaigns_une_seule_ouverte';

    public function up(): void
    {
        // Le schéma builder de Laravel ne produit pas d'index partiel : c'est
        // une spécificité PostgreSQL, écrite telle quelle.
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON campaigns (status) WHERE status = %s',
            self::INDEX,
            DB::getPdo()->quote(CampaignStatus::OPEN->value),
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
