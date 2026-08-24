<?php

namespace Tests\Feature;

use App\Domain\Campaign\CampaignStatus;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Ce que la page d'accueil annonce au public, et ce qu'elle refuse d'annoncer.
 *
 * L'écran se servait dans `resources/js/data/demo.ts` : une édition
 * « BIRNIN GOBE 2026 », une clôture au 30 juin 2026, un décompte figé à
 * 24 j 12 h 45 m 30 s et quatre statistiques rondes — « 5 000+ jeunes
 * impactés » et les suivantes. Des valeurs de maquette, servies au public comme
 * des informations officielles.
 *
 * Le risque n'est pas cosmétique. Un candidat qui lit une date limite fausse sur
 * le site officiel et dépose après la vraie clôture n'a commis aucune erreur ;
 * c'est la plateforme qui l'a induit en erreur. Et une statistique inventée sur
 * un site institutionnel engage l'institution.
 *
 * D'où les deux règles éprouvées ici : ce qui est affiché vient de la base, et
 * ce qui n'a pas de source n'est pas affiché.
 */
final class AccueilPublicTest extends TestCase
{
    use RefreshDatabase;

    private function campagne(array $attributs = []): Campaign
    {
        return Campaign::factory()->create($attributs);
    }

    // — La campagne réelle ————————————————————————————————————————

    /** L'accueil annonce l'édition ouverte, telle qu'elle est en base. */
    public function test_l_accueil_annonce_la_vraie_campagne(): void
    {
        $campagne = $this->campagne([
            'name' => 'BIRNIN GOBE — Édition de vérité',
            'code' => 'BG-VRAI',
            'timezone' => 'Africa/Niamey',
            'closes_at' => now()->addDays(12),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Public/Home')
                ->where('campaign.name', $campagne->name)
                ->where('campaign.code', $campagne->code)
                ->where('campaign.timezone', 'Africa/Niamey')
                // Comparé à la valeur relue en base : c'est celle que la page
                // sert, et un `timestamptz` se relit dans le décalage de la
                // connexion, pas dans celui de l'instance en mémoire. Même
                // instant, écriture différente.
                ->where('campaign.closesAt', $campagne->fresh()->closes_at->toIso8601String()));
    }

    /**
     * Aucune édition ouverte : l'accueil le dit, il n'invente pas.
     *
     * `null` est une réponse. Elle vaut mieux qu'une date par défaut, qui
     * ferait croire à un dépôt possible.
     */
    public function test_sans_campagne_ouverte_l_accueil_n_annonce_rien(): void
    {
        $this->campagne(['status' => CampaignStatus::DRAFT]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('campaign', null));
    }

    /** Une édition close n'est pas une édition ouverte. */
    public function test_une_campagne_close_n_est_pas_annoncee(): void
    {
        $this->campagne([
            'status' => CampaignStatus::OPEN,
            'opens_at' => now()->subDays(60),
            'closes_at' => now()->subDay(),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('campaign', null));
    }

    /** Une édition dont l'ouverture est à venir non plus. */
    public function test_une_campagne_pas_encore_ouverte_n_est_pas_annoncee(): void
    {
        $this->campagne([
            'status' => CampaignStatus::OPEN,
            'opens_at' => now()->addWeek(),
            'closes_at' => now()->addMonths(2),
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('campaign', null));
    }

    // — Ce qui ne doit plus sortir ————————————————————————————————

    /**
     * Aucune valeur de maquette dans la page servie.
     *
     * On inspecte le HTML rendu, pas seulement les propriétés Inertia : c'est
     * ce que le visiteur reçoit qui compte, et une chaîne codée en dur dans le
     * composant n'apparaîtrait dans aucune propriété.
     */
    public function test_l_accueil_ne_sert_aucune_donnee_de_demonstration(): void
    {
        $this->campagne(['name' => 'BIRNIN GOBE — Édition de vérité', 'code' => 'BG-VRAI']);

        $rendu = $this->get('/')->assertOk()->getContent();

        foreach ([
            '30 juin 2026',        // ancienne date limite figée
            '5 000+',              // « jeunes impactés »
            '1 200+',              // « projets accompagnés »
            'Jeunes impactés',
            'Projets accompagnés',
            'Partenaires engagés',
        ] as $trace) {
            $this->assertStringNotContainsString($trace, $rendu, "La page sert encore « {$trace} ».");
        }
    }

    /** Le décompte n'est pas figé : la page reçoit une date, pas des chiffres. */
    public function test_l_accueil_ne_sert_aucun_compte_a_rebours_fige(): void
    {
        $this->campagne(['closes_at' => now()->addDays(5)]);

        $reponse = $this->get('/')->assertOk();

        $reponse->assertInertia(fn ($page) => $page->missing('campaign.countdown'));
        $this->assertStringNotContainsString('24 j', $reponse->getContent());
    }

    // — La limitation des inscriptions ————————————————————————————

    /**
     * Le formulaire d'inscription est limité, comme les deux connexions.
     *
     * Onze tentatives depuis la même origine : les dix premières passent, la
     * onzième est refusée. La limite porte sur l'origine et non sur l'e-mail —
     * compter par e-mail ne protégerait de rien, puisqu'un script en change à
     * chaque envoi, ce que reproduit exactement cette boucle.
     */
    public function test_les_inscriptions_sont_limitees_par_origine(): void
    {
        RateLimiter::clear('inscription|127.0.0.1');

        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', $this->inscription($i))->assertRedirect();
            $this->post('/logout');
        }

        $this->assertSame(10, User::query()->count());

        $this->post('/register', $this->inscription(11))
            ->assertSessionHasErrors('email');

        $this->assertSame(10, User::query()->count());
    }

    /**
     * Une tentative invalide compte aussi.
     *
     * Sans cela, il suffirait d'envoyer des données volontairement mauvaises
     * pour marteler le formulaire sans jamais être décompté, puis d'envoyer les
     * bonnes une fois le champ libre.
     */
    public function test_une_tentative_invalide_est_decomptee(): void
    {
        RateLimiter::clear('inscription|127.0.0.1');

        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', ['name' => '', 'email' => 'pas-un-email', 'password' => 'x'])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, User::query()->count());

        // Le quota est épuisé : même une inscription parfaitement formée passe
        // désormais par le refus de la limitation.
        $this->post('/register', $this->inscription(99))->assertSessionHasErrors('email');

        $this->assertSame(0, User::query()->count());
    }

    /** @return array<string, string> */
    private function inscription(int $rang): array
    {
        return [
            'name' => "Candidat {$rang}",
            'email' => "candidat-{$rang}@example.test",
            'password' => 'MotDePasseSolide!2026',
            'password_confirmation' => 'MotDePasseSolide!2026',
        ];
    }
}
