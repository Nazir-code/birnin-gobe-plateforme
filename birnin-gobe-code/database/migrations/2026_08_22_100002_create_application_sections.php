<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réponses du formulaire, une ligne par section de candidature.
 *
 * Deux options écartées :
 *  - une colonne par champ sur `applications` : le formulaire compte neuf
 *    sections et le cahier des charges prévoit qu'il soit paramétrable par
 *    campagne. Chaque ajustement de contenu deviendrait une migration, et la
 *    table dépasserait rapidement la cinquantaine de colonnes ;
 *  - un unique `jsonb` sur `applications` : chaque sauvegarde automatique
 *    réécrirait la ligne entière de la candidature, y compris les réponses des
 *    autres sections, et l'avancement par section n'aurait pas de support.
 *
 * Une ligne par section donne la granularité de l'autosave (on n'écrit que la
 * section éditée), un emplacement naturel pour `completed_at` — d'où se déduit
 * la progression — et laisse `answers` libre d'évoluer avec le formulaire.
 * La validation reste côté serveur, explicite et par section : `jsonb` n'est
 * pas une porte d'entrée pour des données non validées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('section', 64);
            $table->jsonb('answers')->default('{}');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            // Une seule ligne par section : c'est ce qui rend la sauvegarde
            // idempotente, quel que soit le nombre de requêtes d'autosave.
            $table->unique(['application_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_sections');
    }
};
