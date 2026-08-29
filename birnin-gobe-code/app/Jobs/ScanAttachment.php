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
