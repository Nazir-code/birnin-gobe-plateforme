<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index sur `applications.updated_at` (Admin Phase 3).
 *
 * Audit des index déjà présents sur la table, avant d'en ajouter un :
 *
 *   status                        index simple, posé à la création ;
 *   candidate_id                  index simple, posé avec la clé étrangère ;
 *   (campaign_id, candidate_id)   contrainte d'unicité — son index sert aussi
 *                                 le filtre par campagne, `campaign_id` en
 *                                 étant la colonne de tête ;
 *   submission_number             contrainte d'unicité.
 *
 * Les quatre filtres de la liste sont donc déjà couverts. Il manquait le tri :
 * l'écran de consultation s'ouvre sur `ORDER BY updated_at DESC LIMIT 25`, à
 * chaque chargement et à chaque page. Sans index, PostgreSQL trie la table
 * entière pour n'en rendre que vingt-cinq lignes.
 *
 * Un seul index, et un B-tree : les filtres sur le `jsonb` des réponses passent
 * par un `EXISTS` sur `application_sections`, que l'unicité
 * `(application_id, section)` sert déjà. Poser un GIN sur `answers` sans avoir
 * mesuré coûterait à chaque sauvegarde automatique — c'est-à-dire toutes les
 * quelques secondes pendant qu'un candidat saisit — pour un gain non établi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropIndex(['updated_at']);
        });
    }
};
