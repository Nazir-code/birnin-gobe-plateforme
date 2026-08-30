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
