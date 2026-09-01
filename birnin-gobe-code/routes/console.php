<?php

use Illuminate\Support\Facades\Schedule;

/*
| Tâches planifiées.
|
| Le conteneur `scheduler` du docker-compose fait tourner `schedule:work`, donc
| tout ce qui est déclaré ici s'exécute réellement en développement comme en
| production.
*/

// Rappel de clôture (§8.3, ligne 2). Une fois par jour : la commande décide
// elle-même si le jour est un jalon, et elle sait ne pas prévenir deux fois la
// même personne. La faire tourner plus souvent n'enverrait rien de plus.
//
// Neuf heures, heure de Niamey : un rappel reçu la nuit est lu au matin parmi
// vingt autres messages, et celui-ci demande une action dans la journée.
Schedule::command('notifications:rappel-cloture')
    ->dailyAt('09:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();

// Purge du journal des tâches en échec (table créée par ADR-019, purge décidée
// par ADR-020).
//
// `failed_jobs` n'a aucune purge automatique : sans cette ligne, chaque échec
// définitif y reste pour toujours, avec sa charge sérialisée complète — et la
// charge d'une notification contient le dossier et le destinataire. Une table
// qui ne fait que croître finit par peser sur les sauvegardes, et surtout elle
// conserve des données personnelles bien au-delà de leur utilité.
//
// Sept jours : au-delà, une tâche échouée ne se rejoue plus utilement. Un
// courriel d'admissibilité vieux d'une semaine ne se renvoie pas tel quel —
// on reprend contact autrement. Le délai laisse largement le temps de voir
// l'alerte du §9.3 et d'agir.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 7])
    ->weeklyOn(1, '03:30')
    ->timezone(config('app.timezone'));
