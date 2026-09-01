<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le résultat de l'analyse antivirus, à côté de son état — §15.1.
 *
 * `scan_status` existait déjà et disait *où en est* l'analyse. Il manquait de
 * quoi répondre à deux questions qu'un contrôle posera :
 *
 * - **quand** la pièce a été examinée. Sans date, « analysée, saine » ne dit pas
 *   si le verdict date d'avant ou d'après la mise à jour de la base de
 *   signatures qui aurait détecté la menace. C'est aussi ce qui permettra de
 *   réanalyser les pièces les plus anciennes en priorité.
 * - **quelle menace** a été détectée. Le responsable qui écarte une pièce doit
 *   pouvoir dire pourquoi ; « l'antivirus a dit non » n'est pas une réponse
 *   opposable.
 *
 * Les deux sont nullables : une pièce jamais analysée n'a ni date ni signature,
 * et une pièce saine n'a pas de signature. Un `''` par défaut ferait croire à
 * une menace sans nom.
 *
 * La signature n'est **jamais montrée au candidat** : c'est une donnée
 * d'exploitation, et inutilement inquiétante pour qui a envoyé un document
 * infecté à son insu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->timestampTz('scanned_at')->nullable()->after('scan_status');
            $table->string('scan_signature')->nullable()->after('scanned_at');

            // Pas d'index sur `scan_status` : la migration de création en pose
            // déjà un. « Les pièces qui attendent un verdict » — la question de
            // la commande de rattrapage et de l'alerte du §9.3 — est donc déjà
            // servie.
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropColumn(['scanned_at', 'scan_signature']);
        });
    }
};
