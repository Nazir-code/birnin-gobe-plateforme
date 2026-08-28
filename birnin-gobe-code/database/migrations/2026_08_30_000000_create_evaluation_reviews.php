<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La revue d'écart entre évaluateurs — §11.3.
 *
 * **En ajout seul, comme `verification_decisions`.** Une revue est un acte
 * daté : elle ne se réécrit pas, et une seconde revue est une seconde ligne.
 * D'où l'absence d'`updated_at` — une colonne qui ne bougerait jamais donnerait
 * à croire qu'elle le peut.
 *
 * **Ce qui est stocké est l'acte, pas la divergence.** L'écart, lui, est
 * recalculé à chaque lecture depuis les notes verrouillées : le persister
 * obligerait à le corriger quand une évaluation supplémentaire arrive, donc à
 * écrire un mécanisme de mise à jour d'un chiffre qui se déduit déjà.
 *
 * `covered_evaluations` est la clé de tout le mécanisme : c'est le nombre
 * d'évaluations verrouillées **au moment de la revue**. Une évaluation ne se
 * déverrouille jamais, donc ce nombre ne peut que croître ; quand il croît, la
 * revue cesse de valoir et l'écart redevient à revoir. C'est ce qui évite
 * l'acquittement définitif qu'ADR-014 refuse pour les alertes : on ne fait pas
 * taire un écart, on le revoit tel qu'il est devenu.
 *
 * `observed_gap` fige l'écart maximal constaté ce jour-là. Il n'est jamais relu
 * pour décider quoi que ce soit — le calcul courant fait foi. Il sert à
 * répondre plus tard à « qu'avait-on sous les yeux en actant ce désaccord ? »,
 * question qu'un contrôle posera, et à laquelle un écart recalculé sur des
 * données depuis enrichies ne répondrait pas.
 *
 * `reviewed_by` n'a pas de clé étrangère, comme `audit_events.actor_id` et
 * `evaluation_assignments.assigned_by` : la suppression d'un compte de gestion
 * ne doit pas effacer la trace de ce qu'il a arbitré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evaluation_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();

            $table->string('outcome', 32);
            $table->text('reason');

            // L'état vu par la revue, figé.
            $table->unsignedSmallInteger('covered_evaluations');
            $table->decimal('observed_gap', 3, 2);

            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestampTz('created_at');

            // « La dernière revue de ce dossier » est la seule question posée à
            // cette table, et elle l'est une fois par ligne de l'écran.
            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_reviews');
    }
};
