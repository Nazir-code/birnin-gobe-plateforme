<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'instant où l'on a su ce qu'était devenu un message — §8.3, §9.3.
 *
 * **Une trace créée avant de connaître son issue.** Les six messages du §8.3
 * partent en file d'attente (`MessageTransactionnel implements ShouldQueue`) :
 * quand `SendNotification` écrit sa ligne, le message n'est pas encore parti,
 * il est confié au répartiteur. La ligne naît donc `QUEUED`, et se referme
 * plus tard en `SENT` ou en `FAILED`, depuis le processus qui a réellement
 * tenté l'envoi.
 *
 * **Cela ne contredit pas la règle d'ajout seul du modèle.** `UPDATED_AT` reste
 * nul et une seconde tentative reste une seconde ligne : ce n'est pas un envoi
 * qu'on réécrit, c'est un envoi qu'on referme. `created_at` dit quand on a
 * confié le message, `settled_at` quand on a su. Les deux questions sont
 * différentes, et l'écart entre elles est précisément ce qui révèle un
 * répartiteur arrêté.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->timestampTz('settled_at')->nullable()->after('created_at');

            // « Quels messages sont confiés depuis trop longtemps ? » — la
            // question de l'alerte du §9.3 sur un répartiteur à l'arrêt.
            $table->index(['status', 'created_at']);
        });

        // Les lignes écrites avant cet incrément l'ont été après coup, une fois
        // l'issue connue : les laisser ouvertes les ferait compter comme des
        // messages en attente, et allumerait une alerte pour un passé révolu.
        DB::table('notification_deliveries')
            ->whereNull('settled_at')
            ->update(['settled_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('settled_at');
        });
    }
};
