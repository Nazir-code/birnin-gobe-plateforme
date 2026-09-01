<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation des dossiers aux évaluateurs — §11.1.
 *
 * Une ligne par couple (dossier, évaluateur). Le §11.1 prévoit « un nombre
 * minimal d'évaluations » par dossier : un même dossier va donc à plusieurs
 * évaluateurs, et un même évaluateur reçoit plusieurs dossiers. C'est une table
 * de liaison, pas une colonne sur `applications`.
 *
 * **L'unicité est partielle, et c'est le cœur de la table.** Un couple ne peut
 * exister qu'une fois *en vigueur* ; en revanche, un dossier retiré à un
 * évaluateur puis réaffecté doit pouvoir l'être. Un unique simple sur
 * `(application_id, evaluator_id)` interdirait la seconde affectation et
 * forcerait à effacer la première — donc à perdre la trace du retrait. L'index
 * unique est donc conditionné à `released_at IS NULL`.
 *
 * `released_at` porte les deux façons de sortir : le retrait décidé par le
 * responsable et le conflit déclaré. Elles se distinguent par `status`, jamais
 * par la présence de la date — un conflit doit rester lisible comme un conflit,
 * puisque le §11.1 demande que l'affectation « tienne compte des conflits
 * déclarés ».
 *
 * `evaluator_id` est une vraie clé étrangère, à la différence d'`actor_id`
 * ailleurs : une affectation ne vaut que par l'évaluateur qui la porte, et une
 * affectation orpheline n'aurait aucun sens à conserver. La trace de la
 * décision, elle, vit dans `audit_events`, qui survit à la suppression du
 * compte.
 */
return new class extends Migration
{
    private const INDEX = 'evaluation_assignments_en_vigueur';

    public function up(): void
    {
        Schema::create('evaluation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 32);

            // Qui a affecté. Sans clé étrangère, comme dans `audit_events` : la
            // suppression d'un compte de gestion ne doit pas emporter
            // l'affectation qu'il a décidée.
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestampTz('assigned_at');

            $table->timestampTz('released_at')->nullable();
            $table->text('release_reason')->nullable();

            $table->timestampsTz();

            // La file d'un évaluateur, et la couverture d'un dossier : les deux
            // sens sont interrogés, donc les deux sont indexés.
            $table->index(['evaluator_id', 'status']);
            $table->index(['application_id', 'status']);
        });

        // Un couple en vigueur ne peut exister qu'une fois. Index partiel, pour
        // que la réaffectation après retrait reste possible. Le schéma builder
        // de Laravel ne produit pas d'index partiel : c'est une spécificité
        // PostgreSQL, écrite telle quelle — comme dans
        // `enforce_single_open_campaign`.
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX.
            ' ON evaluation_assignments (application_id, evaluator_id) WHERE released_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        Schema::dropIfExists('evaluation_assignments');
    }
};
