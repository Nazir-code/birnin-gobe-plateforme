<?php

namespace App\Console\Commands;

use App\Domain\Application\AttachmentScanStatus;
use App\Jobs\ScanAttachment;
use App\Models\Attachment;
use Illuminate\Console\Command;

/**
 * Remet en file les pièces sans verdict — §15.1.
 *
 *     php artisan attachments:scan
 *     php artisan attachments:scan --limit=200
 *
 * Trois situations la rendent nécessaire, et aucune n'est exceptionnelle :
 *
 * 1. **Les pièces antérieures à la mise en service** (`NOT_SCANNED`). Elles
 *    existent, elles sont fermées au téléchargement, et elles le resteront tant
 *    que personne ne les aura examinées.
 * 2. **Les analyses qu'un `clamd` éteint a laissées en `UNAVAILABLE`.** L'état
 *    est durable par choix : il fallait qu'une panne se voie plutôt qu'elle ne
 *    se rejoue indéfiniment dans la file. Le prix est cette relance.
 * 3. **Les `PENDING` orphelines**, quand un worker est mort entre la mise en
 *    file et la consommation.
 *
 * `QUARANTINE` et `CLEAN` ne sont jamais repris : le verdict est rendu, et
 * relancer une pièce infectée ne ferait que redétecter la même menace. Une
 * réanalyse après mise à jour des signatures est un autre besoin, qui suppose
 * d'arbitrer une périodicité — voir ce qui reste ouvert dans ADR-017.
 *
 * La limite existe pour ne pas noyer la file : sur une campagne, la reprise
 * porte potentiellement sur des milliers de pièces, et un `clamd` unique les
 * traite en série.
 */
final class RelancerAnalyseAntivirus extends Command
{
    protected $signature = 'attachments:scan {--limit=500 : Nombre maximal de pièces remises en file}';

    protected $description = 'Remet en file d’analyse antivirus les pièces sans verdict (§15.1).';

    public function handle(): int
    {
        $limite = max(1, (int) $this->option('limit'));

        $etats = array_map(
            static fn (AttachmentScanStatus $etat): string => $etat->value,
            array_filter(
                AttachmentScanStatus::cases(),
                static fn (AttachmentScanStatus $etat): bool => $etat->seRejoue(),
            ),
        );

        // Les plus anciennes d'abord : ce sont celles qui bloquent depuis le
        // plus longtemps un vérificateur ou un évaluateur.
        $pieces = Attachment::query()
            ->whereIn('scan_status', $etats)
            ->orderBy('created_at')
            ->limit($limite)
            ->pluck('id');

        if ($pieces->isEmpty()) {
            $this->info('Aucune pièce en attente de verdict.');

            return self::SUCCESS;
        }

        foreach ($pieces as $identifiant) {
            ScanAttachment::dispatch($identifiant);
        }

        $this->info(sprintf('%d pièce(s) remise(s) en file d’analyse.', $pieces->count()));

        if (! config('scanning.enabled')) {
            $this->warn('Aucun analyseur n’est configuré : ces pièces reviendront « analyse indisponible ».');
        }

        return self::SUCCESS;
    }
}
