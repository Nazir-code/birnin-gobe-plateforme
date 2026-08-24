<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Donne aux pièces jointes la seule colonne qui leur manquait : laquelle des
 * pièces du §7.2 chaque fichier est.
 *
 * La table `attachments` existe depuis le squelette initial, avec exactement
 * les métadonnées qu'une pièce demande — nom d'origine, type MIME, taille,
 * empreinte, emplacement de stockage. Elle n'avait simplement jamais été
 * utilisée, faute d'écran. Créer une seconde table à côté aurait laissé au
 * dépôt deux tables pour une même chose, et la question « laquelle fait foi ? »
 * se serait posée au pire moment.
 *
 * Ajout seul, aucune colonne touchée : les dossiers existants n'ont aucune
 * pièce, la colonne part donc nullable et sera renseignée par la seule voie qui
 * écrit ici — `StoreApplicationDocument`.
 *
 * L'index porte sur le couple `(application_id, type)` parce que c'est la seule
 * question posée : « ce dossier a-t-il déjà déposé cette pièce ? ». Il n'est pas
 * unique — le remplacement supprime l'ancienne ligne avant d'écrire la nouvelle,
 * et une contrainte unique transformerait une seconde tentative après un échec
 * partiel en erreur de base plutôt qu'en simple remplacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('type', 64)->nullable()->after('application_id');
            $table->index(['application_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropIndex(['application_id', 'type']);
            $table->dropColumn('type');
        });
    }
};
