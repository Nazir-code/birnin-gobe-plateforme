<?php

namespace Database\Factories;

use App\Domain\Campaign\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 *
 * Même parti pris que `UserFactory` : valeurs déterministes, pas de Faker. Un
 * test qui échoue doit échouer identiquement à chaque exécution.
 */
final class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    private static int $compteur = 0;

    public function definition(): array
    {
        $n = ++self::$compteur;

        return [
            'code' => "BG-TEST-{$n}",
            'name' => "Campagne de test {$n}",
            'status' => CampaignStatus::OPEN,
            'timezone' => 'Africa/Niamey',
            'opens_at' => now()->subDay(),
            'closes_at' => now()->addDays(30),
            'settings' => [],
        ];
    }

    /** Campagne en préparation : elle ne doit accepter aucune candidature. */
    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => CampaignStatus::DRAFT]);
    }

    /** Campagne dont la fenêtre de dépôt est passée. */
    public function closed(): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignStatus::OPEN,
            'opens_at' => now()->subDays(60),
            'closes_at' => now()->subDay(),
        ]);
    }
}
