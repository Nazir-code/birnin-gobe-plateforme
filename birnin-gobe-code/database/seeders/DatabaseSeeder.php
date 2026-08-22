<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Semis par défaut : uniquement ce dont un environnement local a besoin pour
 * fonctionner. Aucun compte, aucune candidature de démonstration — les données
 * de test se créent par les vrais parcours ou par les factories.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CampaignSeeder::class);
    }
}
