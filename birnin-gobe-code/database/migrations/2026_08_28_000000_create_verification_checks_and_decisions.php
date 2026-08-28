<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contrôle d'admissibilité — §10 du cahier des charges.
 *
 * Deux tables, et la distinction entre elles est le fond du sujet.
 *
 * `verification_checks` porte **l'état courant** de la grille du §10.2 : sept
 * contrôles, un verdict chacun, révisables tant que la décision n'est pas
 * prise. Une ligne par contrôle et par dossier, mise à jour sur place — un
 * vérificateur qui se reprend corrige sa coche, il n'empile pas des coches
 * contradictoires que l'écran devrait ensuite départager.
 *
 * `verification_decisions` porte **l'historique des décisions**, et il est en
 * ajout seul. Aucune mise à jour, aucune suppression : le §10.3 exige qu'une
 * modification de la liste des admissibles « crée une nouvelle version,
 * identifie l'auteur et nécessite une validation habilitée ». Une décision
 * qu'on réécrit ne laisserait pas de version à comparer.
 *
 * Pourquoi ne pas tout ranger dans un `jsonb` sur `applications` : la grille se
 * filtre (la file affiche les dossiers dont un contrôle bloque), l'historique
 * se compte, et les deux se lisent sans charger le dossier. Un document JSON
 * sur la candidature aurait rendu chaque coche dépendante d'une réécriture de
 * la ligne du dossier, et le §10.3 impossible à servir.
 *
 * `actor_id` suit la convention d'`audit_events` : entier nullable **sans clé
 * étrangère**. La suppression d'un compte interne ne doit pas emporter la trace
 * de ce qu'il a contrôlé ni de ce qu'il a décidé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('control', 64);
            $table->string('outcome', 64);
            $table->text('observation')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampsTz();

            // Un contrôle, un verdict. C'est cet index qui rend la sauvegarde
            // de la grille idempotente, quel que soit le nombre d'allers-retours.
            $table->unique(['application_id', 'control']);
        });

        Schema::create('verification_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 64);

            // Motifs codifiés, jamais saisis : ce sont des contrôles du §10.2.
            // Un motif libre finirait par exister en douze orthographes et ne
            // serait plus dénombrable dans les rapports du §13.
            $table->string('primary_reason', 64)->nullable();
            $table->string('secondary_reason', 64)->nullable();

            // Le §10.3 sépare explicitement ces deux textes « afin d'éviter la
            // divulgation d'informations sensibles ». Deux colonnes, donc, et
            // jamais une seule que l'écran afficherait des deux côtés.
            $table->text('internal_note')->nullable();
            $table->text('candidate_message')->nullable();

            // Clarification : la date limite de réponse (§10.3).
            $table->date('respond_by')->nullable();

            $table->string('previous_status', 64);
            $table->string('new_status', 64);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('created_at');

            // L'historique d'un dossier se lit du plus récent au plus ancien ;
            // la file, elle, compte les décisions par dossier.
            $table->index(['application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_decisions');
        Schema::dropIfExists('verification_checks');
    }
};
