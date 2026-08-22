<?php

namespace Database\Seeders;

use App\Domain\Campaign\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Seeder;

/**
 * Campagne de travail pour les environnements de développement.
 *
 * Une candidature se rattache obligatoirement à une campagne ouverte. La table
 * `campaigns` étant vide et le back-office qui permettra de la créer n'étant pas
 * encore développé, ce semis donne à l'environnement local de quoi dérouler le
 * parcours complet.
 *
 * Les dates sont relatives à l'exécution, et non figées : un calendrier codé en
 * dur expire, et un environnement de développement qui expire silencieusement
 * coûte plus de temps qu'il n'en fait gagner. Les vraies dates de la compétition
 * seront saisies depuis l'administration, jamais ici.
 *
 * `firstOrCreate` : rejouer le semis ne réécrit pas une campagne existante ni
 * les candidatures qui s'y rattachent.
 */
final class CampaignSeeder extends Seeder
{
    public function run(): void
    {
        Campaign::query()->firstOrCreate(
            ['code' => 'BG-2026'],
            [
                'name' => 'BIRNIN GOBE 2026',
                'status' => CampaignStatus::OPEN,
                'timezone' => 'Africa/Niamey',
                'opens_at' => now()->subMonth(),
                'closes_at' => now()->addDays(90),
                'settings' => [],
            ],
        );
    }
}
