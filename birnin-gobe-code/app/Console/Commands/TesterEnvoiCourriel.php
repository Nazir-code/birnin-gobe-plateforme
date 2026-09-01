<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Vérifie que la messagerie sortante fonctionne — §8.3.
 *
 *     php artisan mail:test moi@exemple.ne
 *
 * **Pourquoi une commande plutôt qu'un dépôt de test.** Les six messages du
 * §8.3 sont mis en file d'attente : déposer une candidature pour voir si le
 * courriel part met en jeu deux choses à la fois — la configuration SMTP et le
 * processus qui vide la file. Quand rien n'arrive, on ne sait pas laquelle a
 * échoué. Cette commande envoie **en direct, sans passer par la file**, et
 * isole donc la première.
 *
 * C'est la séquence de mise en service : d'abord `mail:test`, qui dit si le
 * serveur accepte ; ensuite un dépôt réel, qui dit si la file tourne. Prises
 * séparément, les deux pannes ont des symptômes identiques.
 *
 * **La configuration résolue est affichée avant l'envoi**, mot de passe exclu.
 * Une erreur de port ou de schéma produit un message de connexion illisible ;
 * relire les valeurs réellement chargées coûte moins cher que de les deviner.
 *
 * **Aucune trace n'est écrite dans `notification_deliveries`.** Cette table
 * recense ce qui a été envoyé à des candidats et à des évaluateurs ; y verser
 * des essais de mise en service la rendrait impropre à répondre à la seule
 * question qu'on lui posera — « cette personne a-t-elle été prévenue ? ».
 */
final class TesterEnvoiCourriel extends Command
{
    protected $signature = 'mail:test {destinataire : Adresse qui recevra le message d’essai}';

    protected $description = 'Envoie un courriel d’essai en direct, pour vérifier la configuration SMTP (§8.3).';

    public function handle(): int
    {
        $destinataire = trim((string) $this->argument('destinataire'));

        if (filter_var($destinataire, FILTER_VALIDATE_EMAIL) === false) {
            $this->error(sprintf('« %s » n’est pas une adresse électronique valide.', $destinataire));

            return self::FAILURE;
        }

        $this->afficherLaConfiguration();

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER vaut « log » : le message ira dans storage/logs, pas sur le réseau.');
        }

        try {
            // `raw` et non une notification : on teste le transport, pas un
            // gabarit. Un envoi direct — jamais mis en file — pour qu'une panne
            // remonte ici plutôt que dans un worker.
            Mail::raw($this->corps(), function (Message $message) use ($destinataire): void {
                $message->to($destinataire)->subject('Essai d’envoi — BIRNIN GOBE');
            });
        } catch (Throwable $erreur) {
            $this->error('L’envoi a échoué : '.$erreur->getMessage());
            $this->line('');
            $this->line('Pistes usuelles :');
            $this->line('  · port 465 exige MAIL_SCHEME=smtps ; port 587 exige MAIL_SCHEME=smtp');
            $this->line('  · MAIL_USERNAME est l’adresse complète, pas seulement la partie avant @');
            $this->line('  · l’hébergeur peut bloquer le port sortant depuis un autre réseau');

            return self::FAILURE;
        }

        $this->info(sprintf('Message remis au serveur pour %s.', $destinataire));
        $this->line('');
        $this->warn('« Remis au serveur » n’est pas « arrivé en boîte de réception ».');
        $this->line('Vérifiez la réception, y compris les indésirables : sans SPF ni DKIM sur le');
        $this->line('domaine, un message accepté par SMTP peut être classé indésirable — et la');
        $this->line('plateforme n’a alors aucun moyen de le savoir.');

        return self::SUCCESS;
    }

    private function afficherLaConfiguration(): void
    {
        $transport = config('mail.default');
        $reglages = config('mail.mailers.'.$transport, []);

        $this->line('Configuration chargée :');

        foreach ([
            'Transport' => $transport,
            'Serveur' => $reglages['host'] ?? '—',
            'Port' => $reglages['port'] ?? '—',
            'Chiffrement' => $reglages['scheme'] ?? '— (STARTTLS implicite sur 587)',
            'Utilisateur' => $reglages['username'] ?? '—',
            // Jamais la valeur : un mot de passe n'a pas à passer dans une
            // sortie de terminal, qui se copie-colle dans un ticket.
            'Mot de passe' => ($reglages['password'] ?? '') === '' ? 'absent' : 'défini',
            'Expéditeur' => config('mail.from.address').' ('.config('mail.from.name').')',
        ] as $cle => $valeur) {
            $this->line(sprintf('  %-14s %s', $cle, $valeur));
        }

        $this->line('');
    }

    private function corps(): string
    {
        return implode("\n", [
            'Ceci est un essai de configuration de la messagerie BIRNIN GOBE.',
            '',
            'Si vous recevez ce message, le serveur sortant accepte les envois de la',
            'plateforme. Cela ne garantit pas encore que les notifications du candidat',
            'partent : celles-ci passent par une file d’attente, qui doit être vidée par',
            'un processus dédié.',
            '',
            'Envoyé le '.now()->timezone(config('app.timezone'))->format('d/m/Y à H\hi').'.',
        ]);
    }
}
