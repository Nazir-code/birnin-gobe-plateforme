<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Séquence PostgreSQL des numéros de dépôt.
 *
 * Un numéro de candidature est un identifiant officiel : il figure sur l'accusé
 * de dépôt, il sert de référence au candidat comme au vérificateur, et il ne
 * doit jamais désigner deux dossiers. Le calculer depuis la table — un
 * `MAX(...) + 1` — se casse dès que deux candidats valident à la même seconde :
 * les deux lisent le même maximum, et l'un des deux perd son numéro sur la
 * contrainte d'unicité, au moment précis où il croyait déposer.
 *
 * `nextval()` ne se casse pas : PostgreSQL le sert hors transaction, sans
 * verrou, et deux appels concurrents ne rendent jamais la même valeur.
 *
 * Contrepartie assumée : une transaction annulée après avoir tiré son numéro le
 * consomme quand même — la suite peut donc comporter des trous. C'est le bon
 * échange. Un numéro manquant se constate ; deux dossiers portant le même
 * numéro se découvrent trop tard.
 *
 * La colonne `applications.submission_number` porte déjà son `UNIQUE` depuis la
 * migration initiale : la séquence évite la collision, la contrainte garantit
 * qu'aucun chemin ne la contourne.
 */
return new class extends Migration
{
    public const SEQUENCE = 'application_submission_numbers';

    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS '.self::SEQUENCE.' START WITH 1 INCREMENT BY 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS '.self::SEQUENCE);
    }
};
