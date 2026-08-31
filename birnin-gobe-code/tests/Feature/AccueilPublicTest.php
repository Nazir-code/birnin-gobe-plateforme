<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\CampaignStatus;
use App\Domain\Evaluation\EvaluationCriterion;
use App\Models\Application;
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

    // — Les chiffres clés viennent de la base ————————————————————

    /**
     * Aucun candidat, aucune candidature : la page affiche des zéros.
     *
     * Zéro est une valeur exacte, et une plateforme qui vient d'ouvrir n'a rien
     * d'autre à annoncer. C'est précisément le moment où la tentation d'arrondir
     * est la plus forte — « 5 000+ jeunes impactés » était née comme ça.
     */
    public function test_sans_donnees_les_chiffres_valent_zero(): void
    {
        $this->campagne();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.candidates', 0)
                ->where('stats.draftApplications', 0)
                ->where('stats.submittedApplications', 0)
                ->where('stats.themes', 4));
    }

    /** Les candidats sont comptés, les comptes internes ne le sont pas. */
    public function test_les_candidats_sont_comptes_sans_les_comptes_internes(): void
    {
        $this->campagne();

        User::factory()->count(3)->create();
        User::factory()->role(UserRole::ADMIN)->create();
        User::factory()->role(UserRole::EVALUATOR)->create();
        User::factory()->role(UserRole::JURY)->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.candidates', 3));
    }

    /** Brouillons et dossiers déposés sont comptés séparément. */
    public function test_les_candidatures_sont_comptees_par_statut(): void
    {
        $campagne = $this->campagne();

        // Un candidat par dossier : l'unique `(campaign_id, candidate_id)`
        // interdit deux candidatures du même candidat sur la même édition.
        foreach (range(1, 2) as $ignore) {
            Application::factory()->for($campagne)->for(User::factory(), 'candidate')->create();
        }

        Application::factory()
            ->for($campagne)
            ->for(User::factory(), 'candidate')
            ->status(ApplicationStatus::SUBMITTED)
            ->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.draftApplications', 2)
                ->where('stats.submittedApplications', 1));
    }

    // — Le contenu officiel ————————————————————————————————————————

    /** Les quatre thématiques officielles, avec leurs deux volets. */
    public function test_l_accueil_porte_les_quatre_thematiques_officielles(): void
    {
        $this->campagne();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('themes', 4)
                ->where('themes.0.title', 'Gestion urbaine et services de base')
                ->where('themes.1.title', 'Gestion cadastrale')
                ->where('themes.2.title', 'État civil et services administratifs')
                ->where('themes.3.title', 'Cartographie, géolocalisation, risques et résilience')
                // Les deux volets restent distincts, et le premier est cité au mot près.
                ->where('themes.0.problems', 'Signalement et suivi des déchets, voirie, caniveaux, éclairage, équipements, interventions et relation citoyenne.')
                ->where('themes.0.results', 'Collecte terrain, priorisation, affectation, traçabilité et tableau de bord opérationnel.'));
    }

    /** Les thématiques de maquette ont disparu du rendu public. */
    public function test_les_anciennes_thematiques_de_maquette_ont_disparu(): void
    {
        $this->campagne();

        $rendu = $this->get('/')->assertOk()->getContent();

        foreach (['Agroalimentaire', 'Énergies renouvelables et résilience', 'Culture & Créativité'] as $trace) {
            $this->assertStringNotContainsString($trace, $rendu, "La page sert encore « {$trace} ».");
        }
    }

    /**
     * Les huit critères annoncés au public sont ceux du §11.2.
     *
     * Ce test protège une promesse, pas un affichage. La page portait autrefois
     * sa propre liste — « Impact usager », « Sécurité », « Équipe et pitch » —
     * qui ne correspondait à aucun critère du cahier des charges et omettait
     * l'inclusion. Un candidat lisait donc qu'il serait jugé sur autre chose que
     * ce que les évaluateurs notent. L'assertion porte sur `EvaluationCriterion`
     * et non sur des libellés recopiés : recopier serait refaire la seconde
     * liste que ce test existe pour interdire.
     */
    public function test_l_accueil_porte_les_criteres_reels_du_paragraphe_11_2(): void
    {
        $this->campagne();

        $this->get('/')
            ->assertOk()
            ->assertInertia(function ($page) {
                $page->has('criteria', count(EvaluationCriterion::cases()));

                foreach (EvaluationCriterion::cases() as $rang => $critere) {
                    $page->where("criteria.{$rang}.key", $critere->value);
                    $page->where("criteria.{$rang}.title", $critere->label());
                    // Les éléments d'appréciation, mot pour mot : ce ne sont pas
                    // que des titres, et c'est ce texte qui dit sur quoi on note.
                    $page->where("criteria.{$rang}.question", $critere->elements());
                }
            });
    }

    /**
     * La pondération du §11.2 ne quitte pas le serveur.
     *
     * ADR-015 l'affichait ; le porteur du concours a tranché l'inverse. Ce test
     * porte sur les **props**, pas sur le rendu, et c'est tout son intérêt :
     * retirer la pastille dans le composant React aurait laissé `weight` dans
     * la charge Inertia, donc en clair dans le HTML servi à chaque visiteur.
     * La pondération aurait été masquée à l'œil et lisible à qui regarde la
     * source — l'illusion du retrait, qui est pire que l'affichage assumé.
     *
     * Le total de 100 points reste vérifié sur l'enum lui-même, dans
     * `EspaceEvaluateurTest` : c'est un invariant de la grille, indépendant de
     * ce que le portail choisit d'en publier.
     */
    public function test_l_accueil_ne_publie_pas_la_ponderation(): void
    {
        $this->campagne();

        $this->get('/')
            ->assertInertia(function ($page) {
                foreach (array_keys(EvaluationCriterion::cases()) as $rang) {
                    $page->missing("criteria.{$rang}.weight");
                }
            })
            // Et pas davantage dans le HTML rendu, où la charge Inertia voyage.
            ->assertDontSee('"weight"', false);
    }

    /**
     * Les critères d'évaluation ne se font pas passer pour de l'éligibilité.
     *
     * Les deux notions cohabitent sur la page. Sans cette phrase, un candidat
     * pourrait croire qu'il faut satisfaire les huit critères pour avoir le
     * droit de déposer, alors qu'ils servent à juger un dossier déjà recevable.
     */
    public function test_les_criteres_sont_annonces_comme_criteres_d_evaluation(): void
    {
        $this->campagne();

        // La distinction est portée par l'écran — voir le spec Playwright
        // `accueil-public`. Ce qui se vérifie ici, c'est que le serveur ne
        // confond pas les deux jeux de règles : les critères d'évaluation sont
        // du contenu, et aucune règle d'éligibilité n'est servie à la page.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('criteria', 8)->missing('eligibility'));
    }

    // — Les libellés attendus ——————————————————————————————————————

    /** « Clôture des candidatures », et pas « Prochaine clôture ». */
    public function test_le_libelle_de_cloture_est_exact(): void
    {
        $this->campagne();

        $rendu = $this->get('/')->assertOk()->getContent();

        // Le libellé est rendu par React ; ce qui se vérifie côté serveur, c'est
        // qu'aucune trace de l'ancien intitulé ne subsiste dans ce qui est servi.
        // Le libellé exact est éprouvé à l'écran par le spec Playwright.
        $this->assertStringNotContainsString('Prochaine clôture', $rendu);
    }

    /** Le sélecteur de langue a disparu : aucune i18n n'existe derrière. */
    public function test_le_selecteur_de_langue_a_disparu(): void
    {
        $this->campagne();

        $rendu = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('>FR ', $rendu);
        $this->assertStringNotContainsString('ChevronDown', $rendu);
    }

    /** Le CTA nomme l'action et mène à l'inscription. */
    public function test_le_cta_public_mene_a_l_inscription(): void
    {
        $this->campagne();

        $rendu = $this->get('/')->assertOk()->getContent();

        // Rendu par React : le libellé s'éprouve à l'écran. Ici on vérifie que
        // l'ancien intitulé n'est plus servi nulle part.
        $this->assertStringNotContainsString('>Candidater<', $rendu);
    }

    // — La limitation des inscriptions ————————————————————————————

    /**
     * Le formulaire d'inscription est limité, comme les deux connexions.
     *
     * Soixante et une tentatives depuis la même origine : les soixante
     * premières passent, la suivante est refusée. La limite porte sur l'origine
     * et non sur l'e-mail — compter par e-mail ne protégerait de rien, puisqu'un
     * script en change à chaque envoi, ce que reproduit exactement cette boucle.
     *
     * Le seuil est haut à dessein : les opérateurs mobiles nigériens partagent
     * leurs adresses publiques, et un seuil serré fermerait la porte à des
     * candidats légitimes. Voir `LimiteurDInscriptions`.
     */
    public function test_les_inscriptions_sont_limitees_par_origine(): void
    {
        RateLimiter::clear('inscription|127.0.0.1');

        for ($i = 1; $i <= 60; $i++) {
            $this->post('/register', $this->inscription($i))->assertRedirect();
            $this->post('/logout');
        }

        $this->assertSame(60, User::query()->count());

        $this->post('/register', $this->inscription(61))
            ->assertSessionHasErrors('email');

        $this->assertSame(60, User::query()->count());
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

        for ($i = 1; $i <= 60; $i++) {
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
