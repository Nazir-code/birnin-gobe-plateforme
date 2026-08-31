<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Les tâches planifiées — ADR-018 §7, ADR-019.
 *
 * **Pourquoi tester une déclaration.** Une commande planifiée est le seul type
 * de code qui ne s'exécute jamais pendant le développement : personne ne
 * l'appelle, aucune route n'y mène, et son absence ne casse rien de visible.
 * Elle se remarque des semaines plus tard, quand le rappel n'est pas parti ou
 * que la table a doublé de volume. Une ligne supprimée par erreur dans
 * `routes/console.php` ne laisserait donc aucune trace jusque-là.
 *
 * Ce que cette suite vérifie est volontairement mince : que les tâches sont
 * déclarées, et qu'elles portent le fuseau du concours. Elle ne rejoue pas leur
 * contenu — `NotificationsTransactionnellesTest` s'en charge pour le rappel de
 * clôture, et `queue:prune-failed` est une commande du framework.
 */
final class TachesPlanifieesTest extends TestCase
{
    /** @return list<Event> */
    private function taches(): array
    {
        return app(Schedule::class)->events();
    }

    private function tache(string $fragment): ?Event
    {
        foreach ($this->taches() as $tache) {
            if (str_contains((string) $tache->command, $fragment)) {
                return $tache;
            }
        }

        return null;
    }

    public function test_le_rappel_de_cloture_est_planifie_chaque_jour(): void
    {
        $tache = $this->tache('notifications:rappel-cloture');

        $this->assertNotNull($tache, 'Sans planification, le rappel du §8.3 ne part jamais.');
        $this->assertSame('0 9 * * *', $tache->expression, 'Neuf heures : un rappel lu la nuit se perd.');
        $this->assertSame(config('app.timezone'), $tache->timezone);
    }

    /**
     * `failed_jobs` n'a aucune purge automatique.
     *
     * Sans cette tâche, chaque échec définitif y reste pour toujours avec sa
     * charge sérialisée — et celle d'une notification contient le dossier et le
     * destinataire. Une table qui ne fait que croître pèse sur les sauvegardes,
     * et surtout conserve des données personnelles bien au-delà de leur utilité.
     */
    public function test_le_journal_des_taches_en_echec_est_purge(): void
    {
        $tache = $this->tache('queue:prune-failed');

        $this->assertNotNull($tache, 'La table créée par ADR-019 doit être purgée, sinon elle ne fait que croître.');
        $this->assertStringContainsString('--hours', (string) $tache->command);
        $this->assertSame(config('app.timezone'), $tache->timezone);
    }
}
