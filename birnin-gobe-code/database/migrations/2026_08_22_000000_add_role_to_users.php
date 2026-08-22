<?php

use App\Domain\Auth\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle porté par l'utilisateur, base du contrôle d'accès par espace (ADR-003).
 *
 * Colonne `string` plutôt qu'un type ENUM natif PostgreSQL : ajouter une valeur
 * à un ENUM PostgreSQL demande un ALTER TYPE, là où l'enum PHP `UserRole` reste
 * la source de vérité et se fait valider à l'écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 32)->default(UserRole::CANDIDATE->value)->index()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
