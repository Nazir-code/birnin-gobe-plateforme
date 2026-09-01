<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La trace des notifications transactionnelles — §8.3, §13.3.
 *
 * **Pourquoi persister, alors qu'ADR-014 pose que ce qui se recalcule ne se
 * persiste pas ?** Parce qu'un envoi ne se recalcule pas. C'est un fait daté, et
 * la question qu'on posera — « le candidat a-t-il été prévenu de son rejet, et
 * quand ? » — n'a pas d'autre source. Une plateforme qui écarte un dossier sans
 * pouvoir prouver qu'elle l'a dit ne peut pas défendre sa décision.
 *
 * **Une ligne par couple (événement, canal), pas par notification.** Le §8.3
 * demande « Email/SMS » sur quatre événements : un même fait produit donc
 * plusieurs tentatives, dont certaines n'aboutissent pas. Les fondre en une
 * ligne obligerait à choisir un statut unique pour deux issues différentes —
 * et « partiellement envoyé » n'apprend rien à qui cherche si le candidat a été
 * joint.
 *
 * `status` porte la différence qui compte : `SENT` (parti), `FAILED` (tenté,
 * échoué — le §9.3 veut alerter là-dessus) et `SKIPPED` (jamais tenté, faute de
 * fournisseur ou de destinataire). Les confondre ferait passer une absence de
 * SMS pour une panne, et noierait les vraies pannes dans le bruit.
 *
 * `recipient_id` n'a **pas** de clé étrangère, comme `audit_events.actor_id` :
 * la suppression d'un compte ne doit pas effacer la preuve qu'on l'a prévenu.
 * `recipient_address` conserve l'adresse réellement visée — un candidat qui
 * change d'adresse ne doit pas faire croire que l'ancien message est parti vers
 * la nouvelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->string('event', 48);
            $table->string('channel', 16);
            $table->string('status', 16);

            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_role', 24);
            // Peut être absente : un envoi ignoré faute de fournisseur n'a visé
            // aucune adresse, et en inscrire une laisserait croire à une tentative.
            $table->string('recipient_address')->nullable();

            // Le dossier ou la campagne concernés, quand il y en a un. Un compte
            // créé n'en a pas encore.
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();

            // Ce qui explique un `FAILED` ou un `SKIPPED`. Jamais le contenu du
            // message : une trace d'envoi n'est pas une copie du courrier.
            $table->text('detail')->nullable();

            $table->timestampTz('created_at');

            // « A-t-on prévenu cette personne de cet événement ? » — la question
            // du rappel de clôture, qui ne doit pas partir deux fois.
            $table->index(['recipient_id', 'event']);
            $table->index(['event', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
