<?php

namespace App\Console\Commands;

use App\Domain\Application\AttachmentScanStatus;
use App\Models\Attachment;
use Illuminate\Console\Command;

/**
 * Où en est l'analyse antivirus des pièces — §15.1.
 *
 *     php artisan attachments:status
 *
 * **L'alerte du §9.3 dit qu'il y a un problème ; celle-ci dit lequel.** Trois
 * états produisent le même compteur « pièces sans verdict », et ils n'appellent
 * pas le même geste :
 *
 *  - `PENDING` en nombre — la file d'attente n'est pas vidée. Le remède est le
 *    cron, pas l'antivirus.
 *  - `UNAVAILABLE` en nombre — aucun analyseur ne répond. Le cron n'y changera
 *    rien : il rejouera l'analyse pour obtenir le même verdict.
 *  - `NOT_SCANNED` en nombre — des pièces antérieures à la mise en service, que
 *    `attachments:scan` n'a jamais reprises.
 *
 * Sans cette distinction, on relance une commande de rattrapage qui ne peut
 * pas aider, on conclut qu'elle est cassée, et on cherche au mauvais endroit.
 */
final class EtatAnalyseAntivirus extends Command
{
    protected $signature = 'attachments:status';

    protected $description = 'Répartit les pièces par état d’analyse antivirus, et dit ce que chacun appelle (§15.1).';

    public function handle(): int
    {
        $total = Attachment::query()->count();

        if ($total === 0) {
            $this->info('Aucune pièce déposée.');

            return self::SUCCESS;
        }

        $comptes = Attachment::query()
            ->selectRaw('scan_status, count(*) as total')
            ->groupBy('scan_status')
            ->pluck('total', 'scan_status');

        $this->line(sprintf('%d pièce(s) déposée(s).', $total));
        $this->line('');

        foreach (AttachmentScanStatus::cases() as $etat) {
            $compte = (int) ($comptes[$etat->value] ?? 0);

            if ($compte === 0) {
                continue;
            }

            $this->line(sprintf('  %-22s %4d   %s', $etat->label(), $compte, $this->consigne($etat)));
        }

        $this->line('');
        $this->afficherLaConfiguration();

        return self::SUCCESS;
    }

    /** Ce que cet état appelle, en une phrase actionnable. */
    private function consigne(AttachmentScanStatus $etat): string
    {
        return match ($etat) {
            AttachmentScanStatus::CLEAN => 'téléchargeables.',
            AttachmentScanStatus::QUARANTINE => 'menace détectée ; fermées à tous, y compris au déposant.',
            AttachmentScanStatus::PENDING => 'la file n’est pas vidée : vérifier le cron « queue:work ».',
            AttachmentScanStatus::UNAVAILABLE => 'aucun analyseur n’a répondu : le rattrapage n’y changera rien.',
            AttachmentScanStatus::NOT_SCANNED => 'antérieures à la mise en service : « php artisan attachments:scan ».',
        };
    }

    private function afficherLaConfiguration(): void
    {
        $analyseur = (bool) config('scanning.enabled');
        $derogation = (bool) config('scanning.allow_unscanned_internal');

        $this->line('Analyseur configuré : '.($analyseur ? 'oui' : 'non'));

        if (! $analyseur) {
            $this->line('  Sans analyseur, chaque analyse rend « indisponible » et la pièce reste fermée.');
        }

        $this->line('Dérogation interne : '.($derogation ? 'ACTIVE' : 'inactive'));

        if ($derogation) {
            $this->warn('  Les rôles internes téléchargent des pièces non analysées. Chaque accès est journalisé.');
        }
    }
}
