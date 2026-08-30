<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Les compteurs du tableau de bord — §9.1.
 *
 * Ce que cette suite protège :
 *
 * 1. **Chaque compteur mène à ce qu'il a compté.** Un chiffre qui ouvre une
 *    liste plus large fait douter du chiffre. C'est la règle déjà posée pour les
 *    alertes du §9.3, et elle vaut ici pour la même raison : le lien doit
 *    montrer exactement le périmètre mesuré.
 *
 * 2. **Les cinq compteurs comptent réellement.** « Admissibles » et « Alertes
 *    actives » ont longtemps affiché un tiret et la mention « à venir » ; c'était
 *    vrai, puis ça a cessé de l'être sans que rien ne le signale. Ce test rend
 *    l'oubli visible la prochaine fois.
 *
 * 3. **Le compteur d'alertes est le même chiffre que l'écran d'alertes.** Deux
 *    calculs séparés finiraient par afficher deux nombres pour la même chose, et
 *    le tableau de bord perdrait sa crédibilité sur tout le reste.
 */
final class TableauDeBordAdminTest extends TestCase
{
    use RefreshDatabase;

    private ?Campaign $campagne = null;

    private int $numero = 0;

    private function admin(): User
    {
        return User::factory()->role(UserRole::ADMIN)->create();
    }

    /** `campaigns_une_seule_ouverte` n'autorise qu'une campagne ouverte. */
    private function campagne(): Campaign
    {
        return $this->campagne ??= Campaign::factory()->create();
    }

    private function dossier(ApplicationStatus $statut): Application
    {
        return Application::factory()
            ->for($this->campagne(), 'campaign')
            ->for(User::factory()->role(UserRole::CANDIDATE)->create(), 'candidate')
            ->status($statut)
            ->create($statut === ApplicationStatus::DRAFT ? [] : [
                'submission_number' => sprintf('BG-2026-%03d', ++$this->numero),
                'submitted_at' => now()->subDays(2),
            ]);
    }

    public function test_chaque_compteur_porte_la_destination_de_ce_qu_il_compte(): void
    {
        $admin = $this->admin();
        $this->campagne();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                // Chaque compteur a son URL, et chacune est filtrée sur le
                // statut qu'elle annonce.
                $this->assertStringContainsString('/admin/applications', $props['applications']['url']);
                $this->assertStringContainsString('status='.ApplicationStatus::DRAFT->value, $props['applications']['draftsUrl']);
                $this->assertStringContainsString('status='.ApplicationStatus::SUBMITTED->value, $props['applications']['submittedUrl']);
                $this->assertStringContainsString('status='.ApplicationStatus::ADMISSIBLE->value, $props['applications']['admissibleUrl']);
                $this->assertStringContainsString('/admin/alerts', $props['alerts']['url']);
            });
    }

    /**
     * Le chiffre annoncé est celui que la destination montre.
     *
     * C'est la propriété qui compte : compter trois dossiers soumis et ouvrir
     * une liste de douze détruit la confiance dans tous les autres compteurs.
     */
    public function test_le_compteur_et_sa_liste_donnent_le_meme_nombre(): void
    {
        $admin = $this->admin();

        $this->dossier(ApplicationStatus::DRAFT);
        $this->dossier(ApplicationStatus::DRAFT);
        $this->dossier(ApplicationStatus::SUBMITTED);
        $this->dossier(ApplicationStatus::ADMISSIBLE);
        $this->dossier(ApplicationStatus::ADMISSIBLE);
        $this->dossier(ApplicationStatus::ADMISSIBLE);

        $annonces = [];

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertInertia(function (AssertableInertia $page) use (&$annonces): void {
                $annonces = $page->toArray()['props']['applications'];
            });

        $this->assertSame(6, $annonces['total']);
        $this->assertSame(2, $annonces['drafts']);
        $this->assertSame(1, $annonces['submitted']);
        $this->assertSame(3, $annonces['admissible']);

        // Et la liste filtrée rend exactement le même nombre de lignes.
        foreach ([
            [$annonces['draftsUrl'], $annonces['drafts']],
            [$annonces['submittedUrl'], $annonces['submitted']],
            [$annonces['admissibleUrl'], $annonces['admissible']],
        ] as [$url, $compte]) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->has('applications', $compte));
        }
    }

    /** Plus aucun compteur n'affiche « à venir » : les cinq sont livrés. */
    public function test_les_cinq_compteurs_sont_de_vrais_comptages(): void
    {
        $admin = $this->admin();
        $this->campagne();

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertInertia(function (AssertableInertia $page): void {
                $props = $page->toArray()['props'];

                foreach (['total', 'drafts', 'submitted', 'admissible'] as $cle) {
                    $this->assertIsInt($props['applications'][$cle], "« {$cle} » doit être compté, pas annoncé « à venir ».");
                }

                $this->assertIsInt($props['alerts']['count']);
            });
    }

    /** Le tableau de bord et l'écran d'alertes ne peuvent pas se contredire. */
    public function test_le_compteur_d_alertes_egale_celui_de_l_ecran_d_alertes(): void
    {
        $admin = $this->admin();

        // Des dossiers déposés et jamais contrôlés : de quoi lever au moins une
        // alerte de retard de contrôle.
        $this->dossier(ApplicationStatus::SUBMITTED)->forceFill(['submitted_at' => now()->subDays(30)])->save();

        $surLeTableau = 0;

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertInertia(function (AssertableInertia $page) use (&$surLeTableau): void {
                $surLeTableau = $page->toArray()['props']['alerts']['count'];
            });

        $this->actingAs($admin)
            ->get('/admin/alerts')
            ->assertInertia(function (AssertableInertia $page) use ($surLeTableau): void {
                $this->assertCount(
                    $surLeTableau,
                    $page->toArray()['props']['alerts'],
                    'Deux chiffres différents pour la même chose feraient douter des deux.',
                );
            });
    }
}
