<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La notation de présélection — §11.2 et §11.3.
 *
 * Trois écritures de schéma, qui forment un seul geste : rendre une évaluation
 * possible.
 *
 * **1. `evaluation_assignments.accepted_at`.** Le §11.1 est explicite : « avant
 * d'accéder à un dossier, chaque évaluateur accepte la charte, la
 * confidentialité et la déclaration d'impartialité ». L'acceptation appartient
 * au couple (dossier, évaluateur), pas au compte : on ne s'engage pas une fois
 * pour toutes, on s'engage dossier par dossier, parce que c'est l'impartialité
 * *sur ce dossier* qu'on déclare. La colonne vit donc sur l'affectation. Elle
 * double `status = ACCEPTED`, et c'est voulu : le statut dit l'état, la date dit
 * quand — et c'est la date qu'on produira si l'engagement est contesté.
 *
 * **2. `evaluations`.** Une par affectation, d'où l'unique sur
 * `evaluation_assignment_id`. Rattacher l'évaluation à l'affectation plutôt
 * qu'au couple (dossier, évaluateur) a une conséquence utile : une affectation
 * levée emporte le brouillon avec elle, et une réaffectation ultérieure repart
 * d'une feuille vierge. C'est la bonne règle — le brouillon d'une personne qui
 * s'est récusée ne doit pas ressurgir dans une notation qui n'est plus la
 * sienne.
 *
 * `total_score` est `numeric(5,2)` et **nullable** : il n'existe qu'au
 * verrouillage. Un brouillon incomplet n'a pas de note sur 100, et écrire 0 en
 * attendant afficherait une note fausse à l'administration.
 *
 * **3. `evaluation_scores`.** Une ligne par critère, plutôt qu'un document JSON
 * sur `evaluations`. C'est le même choix que `verification_checks` : les notes
 * seront comparées entre évaluateurs (« écart supérieur à un seuil configurable
 * déclenche une revue », §11.3), et comparer critère par critère à travers un
 * `jsonb` transformerait chaque revue d'écart en requête acrobatique.
 *
 * `score` est nullable : un critère non encore noté n'est pas un critère noté
 * zéro. Zéro est une note du §11.3 — « absent ou non recevable » — et la
 * confondre avec l'absence de saisie ferait apparaître comme jugé ce qui n'a pas
 * été lu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_assignments', function (Blueprint $table): void {
            $table->timestampTz('accepted_at')->nullable()->after('assigned_at');
        });

        Schema::create('evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_assignment_id')->unique()->constrained()->cascadeOnDelete();

            // Redondants avec l'affectation, et assumés : ce sont les deux axes
            // par lesquels on interroge la table — « les évaluations de ce
            // dossier », « les évaluations de cette personne » — et passer par
            // une jointure pour chacune coûterait sans rien garantir de plus.
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 32);
            $table->string('recommendation', 32)->nullable();
            $table->text('comment')->nullable();
            $table->decimal('total_score', 5, 2)->nullable();
            $table->timestampTz('locked_at')->nullable();

            $table->timestampsTz();

            $table->index(['application_id', 'status']);
            $table->index(['evaluator_id', 'status']);
        });

        Schema::create('evaluation_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('evaluation_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 64);

            // Nullable : « pas encore noté » n'est pas « noté zéro ».
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('comment')->nullable();

            $table->timestampsTz();

            $table->unique(['evaluation_id', 'criterion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evaluation_scores');
        Schema::dropIfExists('evaluations');

        Schema::table('evaluation_assignments', function (Blueprint $table): void {
            $table->dropColumn('accepted_at');
        });
    }
};
