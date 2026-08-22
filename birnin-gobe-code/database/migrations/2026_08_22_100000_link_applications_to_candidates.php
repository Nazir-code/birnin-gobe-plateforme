<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache réellement une candidature à son candidat.
 *
 * La colonne `candidate_id` existait déjà (migration 2026_08_20_000001) mais
 * sans contrainte : rien n'empêchait d'écrire un identifiant inexistant. Elle
 * n'est pas réécrite — la migration d'origine est déjà appliquée — la clé
 * étrangère est ajoutée ici.
 *
 * `restrictOnDelete` plutôt que `cascadeOnDelete` : une candidature est une
 * pièce du dossier de la compétition. Supprimer un compte ne doit pas faire
 * disparaître silencieusement le dossier déposé ; l'effacement éventuel devra
 * être une décision explicite, tracée par l'audit.
 *
 * L'index dédié est nécessaire : l'unique `(campaign_id, candidate_id)` ne sert
 * pas les recherches menées par candidat seul, qui sont le cas courant côté
 * espace candidat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->index('candidate_id');
            $table->foreign('candidate_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropForeign(['candidate_id']);
            $table->dropIndex(['candidate_id']);
        });
    }
};
