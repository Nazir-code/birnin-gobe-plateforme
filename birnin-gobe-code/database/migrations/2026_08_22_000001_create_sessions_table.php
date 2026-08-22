<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions en base plutôt qu'en fichiers.
 *
 * Le driver `file` écrivait dans `storage/framework/sessions`, qu'aucun volume
 * Docker ne persiste : toute recréation de conteneur déconnectait l'ensemble
 * des utilisateurs, et le driver aurait de toute façon empêché de faire tourner
 * plusieurs répliques du service `app`.
 *
 * PostgreSQL est déjà une dépendance obligatoire et persistée : aucun composant
 * d'infrastructure n'est ajouté. Redis reste la cible de production possible,
 * c'est alors un simple changement de `SESSION_DRIVER`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
