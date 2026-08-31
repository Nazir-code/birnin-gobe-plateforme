<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le journal des tâches en file d'attente qui ont épuisé leurs tentatives.
 *
 * **Cette table était déclarée sans exister.** `config/queue.php` désigne
 * `failed_jobs` avec le pilote `database-uuids` depuis le premier jour, et
 * aucune migration ne la créait. Le document de passation l'avait relevé
 * (action n° 2, criticité haute) en le classant « sans effet » : à l'époque
 * `grep -r "dispatch("` ne rendait rien, donc rien ne pouvait échouer.
 *
 * **Ce n'est plus vrai.** Deux tâches sont désormais mises en file — l'analyse
 * antivirus des pièces (§15.1) et les six notifications du §8.3. Sans cette
 * table, la première tâche à épuiser ses essais fait lever un
 * `SQLSTATE[42P01] relation « failed_jobs » does not exist` **au moment
 * d'enregistrer son échec** : la tâche est perdue, l'erreur d'origine avec
 * elle, et le journal ne conserve que l'erreur SQL qui a masqué la vraie.
 *
 * Concrètement, aujourd'hui : un courriel de rejet qu'un serveur SMTP en panne
 * refuse trois fois disparaît sans laisser de trace exploitable, alors que le
 * candidat, lui, attend toujours d'apprendre sa décision.
 *
 * `uuid` est unique parce que le pilote `database-uuids` retrouve une tâche par
 * cet identifiant — c'est ce que `queue:retry <uuid>` prend en argument.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
