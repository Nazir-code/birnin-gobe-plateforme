<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons d'invitation des comptes internes — ADR-022.
 *
 * **Même forme que `password_reset_tokens`, table distincte.** Le courtier de
 * Laravel attend exactement ces trois colonnes ; ce qui change n'est pas le
 * schéma mais la durée de vie, réglée dans `config/auth.php` à sept jours
 * contre soixante minutes.
 *
 * Pourquoi ne pas partager la table : la durée de validité y serait alors
 * portée par le courtier utilisé à la vérification, et non par le jeton. Un
 * lien de réinitialisation ordinaire pourrait alors être présenté au courtier
 * des invitations et vivre sept jours. Une table par durée rend cela
 * impossible sans rien à surveiller.
 *
 * `email` est la clé primaire, comme pour les réinitialisations : une personne
 * n'a qu'une invitation en cours, et en émettre une seconde remplace la
 * première — c'est le comportement attendu quand un administrateur relance
 * quelqu'un qui n'a pas répondu.
 *
 * Le jeton est stocké **haché** par le courtier : un vol de la table ne donne
 * aucun lien utilisable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_invitations', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_invitations');
    }
};
