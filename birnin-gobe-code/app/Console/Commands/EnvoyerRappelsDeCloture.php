<?php

namespace App\Console\Commands;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Campaign\ActiveCampaign;
use App\Domain\Notification\NotificationEvent;
use App\Domain\Notification\SendNotification;
use App\Models\Application;
use App\Notifications\Candidat\RappelDeCloture;
use Illuminate\Console\Command;

/**
 * Le rappel de clôture du §8.3, ligne 2.
 *
 *     php artisan notifications:rappel-cloture
 *     php artisan notifications:rappel-cloture --dry-run
 *
 * **Le destinataire est « brouillons autorisés »**, au sens du cahier des
 * charges : un dossier encore en brouillon, sur la campagne ouverte. Un dossier
 * déjà déposé n'a rien à rattraper, et le relancer inquiéterait pour rien.
 *
 * **Un seul rappel par candidat et par échéance.** La commande tourne tous les
 * jours ; sans garde, elle enverrait le même message chaque matin pendant une
 * semaine, et le candidat cesserait de les lire — puis les filtrerait, y compris
 * le dernier, qui est celui qui compte. La trace des envois répond à la
 * question « l'a-t-on déjà prévenu ? », et c'est l'une des raisons pour
 * lesquelles cette table existe.
 *
 * **« Par échéance » n'a longtemps été qu'une intention.** La garde interrogeait
 * la campagne entière, sans le jalon : dès le rappel de J-7 envoyé, celui de J-1
 * était écarté pour tout le monde — le dernier, « celui qui compte », ne partait
 * jamais. Le jalon est désormais nommé (`J-7`, `J-1`) et porté par la trace ;
 * c'est lui qui rend la phrase ci-dessus vraie.
 *
 * **Les jalons sont des constantes**, pas des réglages de campagne : le §8.3
 * demande un rappel sans fixer de délai, et le §9.2 ne fait pas figurer ce seuil
 * parmi les paramètres administrables. L'exposer donnerait à croire qu'il a été
 * arbitré — même raisonnement que les seuils d'alerte d'ADR-014.
 *
 * **`--dry-run` existe parce qu'un envoi ne se rattrape pas.** Avant la première
 * campagne réelle, on voudra savoir combien de personnes seraient jointes sans
 * les joindre.
 */
final class EnvoyerRappelsDeCloture extends Command
{
    protected $signature = 'notifications:rappel-cloture
                            {--dry-run : Compter les destinataires sans rien envoyer}';

    protected $description = 'Rappelle la clôture aux candidats dont le dossier est encore en brouillon (§8.3).';

    public function handle(ActiveCampaign $campagnes, SendNotification $notifier): int
    {
        $campagne = $campagnes->resolve();

        if ($campagne === null || $campagne->closes_at === null) {
            $this->info('Aucune campagne ouverte, ou aucune date de clôture arrêtée : rien à rappeler.');

            return self::SUCCESS;
        }

        // Comparaison de jours calendaires, pas d'heures : « J-7 » est une
        // notion de date. Mesurer l'écart en heures ferait tomber une clôture
        // fixée à 23 h dans le jalon J-8 le matin même, et le rappel ne
        // partirait jamais.
        $restants = (int) now()->startOfDay()->diffInDays($campagne->closes_at->startOfDay(), absolute: false);

        if ($restants < 0) {
            $this->info('La clôture est passée : le rappel n’a plus d’objet.');

            return self::SUCCESS;
        }

        $jalons = (array) config('notifications.closing_reminder_days', []);

        if (! in_array($restants, $jalons, strict: true)) {
            $this->info(sprintf('J-%d : aucun jalon de rappel aujourd’hui (jalons : J-%s).', $restants, implode(', J-', $jalons)));

            return self::SUCCESS;
        }

        // Le jalon nomme l'occurrence, et c'est lui qui rend la garde correcte.
        // Sans ce nom, « a-t-on déjà prévenu cette personne ? » portait sur la
        // campagne entière : quiconque avait reçu le rappel de J-7 était réputé
        // prévenu à J-1, et le rappel de la veille — le seul qui fasse encore
        // déposer un brouillon — n'atteignait personne.
        $jalon = 'J-'.$restants;

        $simulation = (bool) $this->option('dry-run');
        $envoyes = 0;
        $ignores = 0;

        Application::query()
            ->where('campaign_id', $campagne->getKey())
            ->where('status', ApplicationStatus::DRAFT->value)
            ->with(['candidate', 'campaign'])
            ->chunkById(200, function ($dossiers) use (&$envoyes, &$ignores, $notifier, $restants, $jalon, $simulation, $campagne): void {
                foreach ($dossiers as $dossier) {
                    $candidat = $dossier->candidate;

                    // Déjà prévenu à ce jalon, ou sans compte rattaché.
                    if ($candidat === null || $notifier->dejaEnvoye(NotificationEvent::CLOSING_REMINDER, $candidat, $campagne, $jalon)) {
                        $ignores++;

                        continue;
                    }

                    if (! $simulation) {
                        $notifier->handle(
                            evenement: NotificationEvent::CLOSING_REMINDER,
                            destinataire: $candidat,
                            message: new RappelDeCloture($dossier, $restants),
                            dossier: $dossier,
                            campagne: $campagne,
                            occurrence: $jalon,
                        );
                    }

                    $envoyes++;
                }
            });

        $this->info(sprintf(
            'J-%d — %d rappel(s) %s, %d ignoré(s) (déjà prévenus ou sans compte).',
            $restants,
            $envoyes,
            $simulation ? 'à envoyer' : 'envoyé(s)',
            $ignores,
        ));

        return self::SUCCESS;
    }
}
