<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quelle occurrence d'un événement récurrent cette trace parle-t-elle — §8.3.
 *
 * **Sans cette colonne, le rappel de la veille de clôture ne partait jamais.**
 * Le §8.3 prévoit deux rappels, J-7 et J-1, et la garde anti-doublon demandait
 * « a-t-on déjà écrit à ce candidat pour cette campagne ? ». La réponse étant
 * oui dès le 23 septembre, le rappel du 29 était écarté pour tout le monde —
 * c'est-à-dire précisément celui qui fait déposer un dossier resté en brouillon.
 *
 * Le défaut était invisible à la relecture : le commentaire de la commande
 * annonçait « un seul rappel par candidat **et par échéance** », la
 * configuration déclarait bien deux jalons, et le test lançait la commande deux
 * fois le même jour — ce qui éprouve l'idempotence quotidienne, jamais le
 * passage d'un jalon au suivant.
 *
 * **`occurrence` reste nullable et sans signification imposée.** Les cinq autres
 * événements du §8.3 n'arrivent qu'une fois par dossier : leur trace n'a rien à
 * y écrire, et leur garde ne doit pas changer de comportement. Seul un événement
 * récurrent la renseigne — « J-7 », « J-1 » — et la garde ne filtre dessus que
 * lorsqu'on la lui donne.
 *
 * L'index reprend celui de la garde en y ajoutant la colonne : c'est cette
 * requête-là, exécutée une fois par brouillon chaque matin de jalon, qui la
 * traverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('occurrence', 32)->nullable()->after('event');

            $table->index(['recipient_id', 'event', 'occurrence'], 'notif_deliveries_garde_occurrence');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropIndex('notif_deliveries_garde_occurrence');
            $table->dropColumn('occurrence');
        });
    }
};
