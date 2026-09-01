<?php

namespace App\Jobs;

use App\Domain\Application\AttachmentScanStatus;
use App\Domain\Application\Scanning\ScanVerdict;
use App\Domain\Application\Scanning\VirusScanner;
use App\Domain\Application\StoreApplicationDocument;
use App\Models\Attachment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Analyse une pièce déposée — §15.1.
 *
 * **En file d'attente, et pas dans la requête de dépôt.** Une analyse antivirus
 * prend de quelques centaines de millisecondes à plusieurs secondes selon le
 * poids du fichier ; la faire pendant le téléversement ajouterait cette attente
 * à un geste déjà lent sur un réseau mobile nigérien, et un `clamd` momentanément
 * lent ferait échouer des dépôts parfaitement valides. Le fichier est donc
 * accepté, marqué `PENDING`, et son verdict tombe ensuite. Rien ne se télécharge
 * entre-temps : c'est `AttachmentScanStatus` qui le garantit, pas la vitesse du
 * job.
 *
 * **Un échec d'analyseur n'est pas un échec de job.** Si `clamd` est éteint, le
 * verdict est `UNAVAILABLE` et le job se termine normalement : la pièce porte un
 * état durable, lisible, et la commande de rattrapage la reprendra. Faire
 * échouer le job accumulerait des tentatives dans la file sans que personne ne
 * voie l'état réel des pièces.
 *
 * **La pièce peut avoir disparu.** Un candidat qui remplace un document entre le
 * dépôt et l'analyse supprime la ligne et le fichier ; le job le constate et
 * s'arrête sans bruit. C'est le cas normal, pas une anomalie.
 *
 * **Et si le job échoue quand même ?** `handle()` traite les cas prévus, pas
 * l'imprévu : une base injoignable, un disque objet en panne, un dépassement de
 * mémoire. Après trois essais, le job est abandonné. Sans `failed()`, la pièce
 * resterait `PENDING` — « analyse en cours » — pour toujours : un état qui dit
 * « repassez dans un instant » à quelqu'un qui attendrait indéfiniment, et une
 * ligne dans `failed_jobs` que personne ne rapproche jamais du fichier concerné.
 */
final class ScanAttachment implements ShouldQueue
{
    use Queueable;

    /** Trois essais, pour absorber un redémarrage de conteneur. */
    public int $tries = 3;

    public function __construct(public int $attachmentId) {}

    public function handle(VirusScanner $analyseur): void
    {
        $piece = Attachment::query()->find($this->attachmentId);

        if ($piece === null) {
            return; // Remplacée ou supprimée entre-temps.
        }

        $disque = StoreApplicationDocument::disk();

        if (! $disque->exists($piece->storage_key)) {
            $this->consigner($piece, ScanVerdict::unavailable('Fichier absent du disque au moment de l’analyse.'));

            return;
        }

        $contenu = $disque->get($piece->storage_key);

        if ($contenu === null) {
            $this->consigner($piece, ScanVerdict::unavailable('Fichier illisible sur le disque.'));

            return;
        }

        $this->consigner($piece, $analyseur->scan($contenu));
    }

    /**
     * Le job est abandonné après son dernier essai — §15.1, ADR-019.
     *
     * **`UNAVAILABLE`, jamais `CLEAN`.** C'est la même règle que partout
     * ailleurs sur l'analyse antivirus : ce qui n'a pas été vérifié ne s'ouvre
     * pas. La pièce reste fermée au téléchargement, l'alerte
     * `pieces.non_analysees` la voit, et la commande de rattrapage peut la
     * reprendre — alors que `PENDING` la laisserait dans un état qui promet un
     * verdict imminent qui ne viendra jamais.
     *
     * **Un verdict déjà rendu n'est pas effacé.** Si une tentative précédente a
     * conclu `CLEAN` ou `QUARANTINE`, le signal d'abandon arrive après coup et
     * ne doit pas défaire ce qui a été établi — c'est la même garde
     * qu'ADR-019 pose sur les traces d'envoi : le premier à conclure fixe
     * l'issue. `seRejoue()` nomme exactement les états qui attendent encore.
     *
     * La pièce peut aussi avoir disparu entre-temps ; c'est le cas normal du
     * remplacement de document, et il ne doit pas faire échouer la gestion d'un
     * échec.
     */
    public function failed(?Throwable $erreur): void
    {
        $piece = Attachment::query()->find($this->attachmentId);

        if ($piece === null || ! $piece->scan_status->seRejoue()) {
            return;
        }

        $this->consigner($piece, ScanVerdict::unavailable(sprintf(
            'L’analyse n’a pas abouti après %d tentatives : %s',
            $this->tries,
            $erreur?->getMessage() ?? 'cause inconnue',
        )));
    }

    /**
     * Écrit le verdict, et le journalise quand il compte.
     *
     * **Une menace détectée va dans les journaux applicatifs, pas dans le
     * journal d'audit.** Le §13.3 recense des décisions humaines ; une détection
     * antivirus est un fait technique, et l'y verser mêlerait deux natures
     * d'événements dans l'écran qui sert à retrouver qui a décidé quoi. Le
     * responsable la voit par l'alerte de pilotage du §9.3.
     */
    private function consigner(Attachment $piece, ScanVerdict $verdict): void
    {
        $piece->forceFill([
            'scan_status' => $verdict->status->value,
            'scanned_at' => now(),
            'scan_signature' => $verdict->signature,
        ])->save();

        if ($verdict->estInfecte()) {
            Log::warning('Pièce placée en quarantaine par l’analyse antivirus.', [
                'attachment_id' => $piece->getKey(),
                'application_id' => $piece->application_id,
                'signature' => $verdict->signature,
            ]);

            return;
        }

        if ($verdict->status === AttachmentScanStatus::UNAVAILABLE) {
            Log::notice('Analyse antivirus indisponible : la pièce reste fermée au téléchargement.', [
                'attachment_id' => $piece->getKey(),
                'detail' => $verdict->detail,
            ]);
        }
    }
}
