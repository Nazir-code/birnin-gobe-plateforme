<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Étape courante du formulaire, portée par le serveur.
 *
 * « Reprendre ma candidature » doit ramener le candidat là où il s'est arrêté,
 * y compris depuis un autre appareil ou après vidage du navigateur : cette
 * information ne peut donc pas vivre dans le `localStorage`.
 *
 * La valeur stockée est une clé stable de `ApplicationSection` (`challenge`…),
 * jamais un libellé français ni un numéro d'étape — renuméroter les sections ne
 * doit pas réécrire les lignes existantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('current_step', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn('current_step');
        });
    }
};
