<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\EligibilitySection;
use App\Domain\Auth\UserRole;
use App\Domain\Reference\NigerRegion;
use App\Domain\Reporting\IndicatorBreakdown;
use App\Domain\Reporting\RegionIntensity;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La carte de répartition du tableau de bord — §9.1, §13.4.
 *
 * La carte existait depuis l'origine, mais n'avait jamais reçu de données :
 * elle s'affichait en gris avec la mention « Aucun dossier à répartir », ce qui
 * était honnête tant qu'il n'y avait pas de dossiers et devenait faux ensuite.
 *
 * Ce que cette suite protège, par ordre d'importance :
 *
 *  1. **Le seuil de petits effectifs tient jusqu'à la couleur.** Une région
 *     sous le seuil est masquée par la ventilation ; la colorer malgré tout
 *     rendrait au regard ce que le masquage vient de retirer. C'est le seul
 *     test dont l'échec serait une fuite de données personnelles.
 *  2. **Les paliers sont relatifs**, jamais des seuils en dur : la même carte
 *     doit rester lisible à soixante candidatures comme à six cents.
 *  3. **La légende dit ce qui est vrai** — « aucun dossier » seulement quand il
 *     n'y en a pas.
 */
final class CarteRepartitionTest extends TestCase
{
    use RefreshDatabase;

    private function campagne(): Campaign
    {
        return Campaign::factory()->create(['closes_at' => now()->addDays(30)]);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    /** Dépose `$combien` dossiers déclarant `$region` comme zone d'intervention. */
    private function dossiers(Campaign $campagne, NigerRegion $region, int $combien): void
    {
        for ($i = 0; $i < $combien; $i++) {
            Application::factory()
                ->for($campagne, 'campaign')
                ->for(User::factory()->role(UserRole::CANDIDATE), 'candidate')
                ->withSection(ApplicationSection::ELIGIBILITY, [
                    EligibilitySection::INTERVENTION_REGION => $region->value,
                ])
                ->create();
        }
    }

    // — Le seuil de discrétion ——————————————————————————————————————

    /**
     * Une région sous le seuil n'est pas colorée.
     *
     * C'est l'assertion qui compte. Le §13.4 masque les petits effectifs parce
     * que le croisement d'une ou deux personnes avec une région permet de
     * remonter à quelqu'un. Une teinte pâle sur cette seule région dirait « il
     * y a une candidature ici » — exactement l'information protégée.
     */
    public function test_une_region_sous_le_seuil_reste_grise(): void
    {
        $campagne = $this->campagne();

        $this->dossiers($campagne, NigerRegion::NIAMEY, IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS + 3);
        $this->dossiers($campagne, NigerRegion::AGADEZ, IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS - 1);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(function ($page) {
                $paliers = $page->toArray()['props']['regionIntensities'];

                $this->assertArrayHasKey(NigerRegion::NIAMEY->value, $paliers);
                $this->assertArrayNotHasKey(
                    NigerRegion::AGADEZ->value,
                    $paliers,
                    'Une région sous le seuil du §13.4 ne doit pas être colorée : la teinte rendrait ce que le masquage retire.',
                );
            });
    }

    /** Le seuil de la carte est celui des indicateurs, jamais un second. */
    public function test_le_seuil_est_celui_du_paragraphe_13_4(): void
    {
        $campagne = $this->campagne();

        // Exactement au seuil : visible. Un de moins : masqué.
        $this->dossiers($campagne, NigerRegion::NIAMEY, IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertInertia(fn ($page) => $this->assertArrayHasKey(
                NigerRegion::NIAMEY->value,
                $page->toArray()['props']['regionIntensities'],
            ));
    }

    // — Les paliers ————————————————————————————————————————————————

    /**
     * L'échelle est relative au maximum, pas à des seuils en dur.
     *
     * Huit dossiers ne veulent pas dire la même chose sur soixante candidatures
     * que sur six cents. Une échelle fixe rendrait la carte illisible au début
     * de la collecte, puis uniformément foncée à la fin.
     */
    public function test_les_paliers_sont_relatifs_au_maximum(): void
    {
        $this->assertSame(RegionIntensity::HIGH, RegionIntensity::pour(100, 100));
        $this->assertSame(RegionIntensity::HIGH, RegionIntensity::pour(10, 10));

        $this->assertSame(RegionIntensity::ELEVATED, RegionIntensity::pour(60, 100));
        $this->assertSame(RegionIntensity::MEDIUM, RegionIntensity::pour(30, 100));
        $this->assertSame(RegionIntensity::LOW, RegionIntensity::pour(10, 100));
    }

    /** La région la plus fournie est toujours au palier haut. */
    public function test_la_region_la_plus_fournie_est_au_palier_haut(): void
    {
        $paliers = RegionIntensity::carte([
            NigerRegion::NIAMEY->value => 9,
            NigerRegion::ZINDER->value => 7,
            NigerRegion::MARADI->value => 1,
        ]);

        $this->assertSame(RegionIntensity::HIGH->value, $paliers[NigerRegion::NIAMEY->value]);
    }

    /** Une valeur masquée n'entre ni dans la carte ni dans le calcul du maximum. */
    public function test_une_valeur_masquee_n_influence_pas_l_echelle(): void
    {
        $avec = RegionIntensity::carte([
            NigerRegion::NIAMEY->value => 8,
            NigerRegion::AGADEZ->value => null,
        ]);

        $sans = RegionIntensity::carte([NigerRegion::NIAMEY->value => 8]);

        $this->assertSame($sans, $avec);
        $this->assertArrayNotHasKey(NigerRegion::AGADEZ->value, $avec);
    }

    // — La légende ——————————————————————————————————————————————————

    /** Sans dossier, la carte l'annonce plutôt que d'inventer une répartition. */
    public function test_sans_dossier_la_carte_ne_publie_aucun_palier(): void
    {
        $this->campagne();

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('regionIntensities', null));
    }

    /**
     * Dès qu'une région franchit le seuil, la carte cesse de dire qu'il n'y a rien.
     *
     * C'est le défaut d'origine : la mention « Aucun dossier à répartir » était
     * codée en dur, et restait affichée avec soixante-cinq candidatures en base.
     */
    public function test_avec_des_dossiers_la_carte_publie_des_paliers(): void
    {
        $campagne = $this->campagne();
        $this->dossiers($campagne, NigerRegion::ZINDER, IndicatorBreakdown::SEUIL_PETITS_EFFECTIFS + 1);

        $this->actingAs($this->admin())
            ->get('/admin/dashboard')
            ->assertInertia(function ($page) {
                $paliers = $page->toArray()['props']['regionIntensities'];

                $this->assertNotNull($paliers);
                $this->assertSame(RegionIntensity::HIGH->value, $paliers[NigerRegion::ZINDER->value]);
            });
    }

    /** Un candidat n'atteint pas cet écran : la répartition est interne (§13.4). */
    public function test_la_repartition_n_est_pas_publique(): void
    {
        $this->actingAs(User::factory()->role(UserRole::CANDIDATE)->create())
            ->get('/admin/dashboard')
            ->assertForbidden();
    }
}
