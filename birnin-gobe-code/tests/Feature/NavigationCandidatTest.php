<?php

namespace Tests\Feature;

use App\Domain\Application\ApplicationSection;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Auth\UserRole;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Points d'entrée de la navigation candidate.
 *
 * Le menu comptait sept entrées, dont quatre pointaient sur `#`. Un candidat qui
 * clique et ne voit rien se produire ne conclut pas « ce module arrive plus
 * tard » : il conclut que la plateforme est cassée, et il doute aussi des
 * entrées qui, elles, fonctionnent. C'est ce retour d'usage que corrige cette
 * phase.
 *
 * **Ce que ce fichier ne teste pas, et pourquoi.** Le menu est rendu par React,
 * et l'application n'a pas de rendu serveur : le HTML que Laravel renvoie ne
 * contient aucune étiquette de menu. Un `assertDontSee('Mes messages')` passerait
 * donc même si l'entrée était toujours là — un test qui ne peut pas échouer est
 * pire qu'absent, parce qu'on lui fait confiance. Ce qui est visible à l'écran
 * est vérifié par `tests/E2E/navigation-candidat.spec.ts`, dans un vrai
 * navigateur.
 *
 * Restent ici les deux choses que le serveur décide réellement : vers quoi les
 * points d'entrée résolvent, et pour qui.
 */
final class NavigationCandidatTest extends TestCase
{
    use RefreshDatabase;

    private function candidat(): User
    {
        return User::factory()->create();
    }

    private function campagne(): Campaign
    {
        return Campaign::factory()->create();
    }

    /** Ouvre un brouillon par le vrai parcours HTTP, jamais par la factory. */
    private function brouillon(User $candidat, Campaign $campagne): Application
    {
        $this->actingAs($candidat)->post('/candidate/application')->assertRedirect();

        return Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->where('campaign_id', $campagne->getKey())
            ->sole();
    }

    // — « Mon profil » ————————————————————————————————————————————

    /**
     * L'entrée mène à l'étape 2 du dossier — la vraie page Profil, celle qui a
     * ses champs, sa validation et sa sauvegarde. Aucun second système de profil
     * n'a été créé : cette route ne fait que résoudre puis rediriger.
     */
    public function test_mon_profil_mene_a_la_section_profil_du_dossier(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);

        $this->actingAs($candidat)->get('/candidate/profile')
            ->assertRedirect("/candidate/application/{$application->getKey()}/profile");
    }

    public function test_la_page_profil_repond_reellement(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);

        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/profile")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Candidate/Application/Profile'));
    }

    /**
     * Sans dossier, il n'y a pas encore de profil à remplir.
     *
     * Le candidat atterrit sur le tableau de bord, là où se trouve le bouton qui
     * ouvre le dossier — et rien n'est écrit en base au passage : une navigation
     * ne crée pas de candidature.
     */
    public function test_mon_profil_sans_dossier_renvoie_au_tableau_de_bord_sans_rien_creer(): void
    {
        $candidat = $this->candidat();
        $this->campagne();

        $this->actingAs($candidat)->get('/candidate/profile')
            ->assertRedirect('/candidate/dashboard');

        $this->assertSame(0, Application::query()->count());
    }

    // — « Ma candidature » ————————————————————————————————————————

    /**
     * La reprise existante est respectée : l'entrée suit `current_step`, elle
     * ne code aucune étape en dur.
     */
    #[DataProvider('etapesDeReprise')]
    public function test_ma_candidature_reprend_a_l_etape_en_cours(ApplicationSection $section, string $chemin): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);

        $application->forceFill(['current_step' => $section])->save();

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect("/candidate/application/{$application->getKey()}/{$chemin}");
    }

    /** @return array<string, array{ApplicationSection, string}> */
    public static function etapesDeReprise(): array
    {
        return [
            'eligibilite' => [ApplicationSection::ELIGIBILITY, 'eligibility'],
            'profil' => [ApplicationSection::PROFILE, 'profile'],
            'structure' => [ApplicationSection::TEAM, 'team'],
            'defi' => [ApplicationSection::CHALLENGE, 'challenge'],
            'solution' => [ApplicationSection::SOLUTION, 'solution'],
            'impact' => [ApplicationSection::IMPACT, 'impact'],
            'plan' => [ApplicationSection::IMPLEMENTATION, 'implementation'],
        ];
    }

    public function test_ma_candidature_sans_dossier_renvoie_au_tableau_de_bord_sans_rien_creer(): void
    {
        $candidat = $this->candidat();
        $this->campagne();

        $this->actingAs($candidat)->get('/candidate/application')
            ->assertRedirect('/candidate/dashboard');

        $this->assertSame(0, Application::query()->count());
    }

    // — Dossier déposé ————————————————————————————————————————————

    /**
     * Un dossier soumis ne repart pas dans un formulaire.
     *
     * Les écrans de section sont des formulaires, et `ApplicationPolicy::update`
     * refuse toute écriture une fois le dossier déposé : y renvoyer le candidat
     * lui présenterait des champs qu'il croirait pouvoir corriger jusqu'à ce que
     * l'enregistrement échoue. Le tableau de bord, lui, dit l'état réel —
     * statut, complétude, étapes. C'est le meilleur écran de lecture que le
     * produit possède aujourd'hui ; l'écran de relecture définitif appartient à
     * l'étape 9, développée ailleurs.
     */
    #[DataProvider('entreesDeNavigation')]
    public function test_un_dossier_depose_mene_a_un_ecran_de_lecture_et_non_a_un_formulaire(string $entree): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);

        $application->forceFill([
            'status' => ApplicationStatus::SUBMITTED,
            'current_step' => ApplicationSection::CHALLENGE,
        ])->save();

        $this->actingAs($candidat)->get($entree)->assertRedirect('/candidate/dashboard');
    }

    /** @return array<string, array{string}> */
    public static function entreesDeNavigation(): array
    {
        return [
            'ma candidature' => ['/candidate/application'],
            'mon profil' => ['/candidate/profile'],
        ];
    }

    /**
     * Le dossier déposé reste consultable directement par son propriétaire.
     *
     * Ce sont les liens du menu qui changent, pas les autorisations : aucune
     * porte n'a été fermée au passage.
     */
    public function test_un_dossier_depose_reste_consultable_par_son_proprietaire(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);

        $application->forceFill(['status' => ApplicationStatus::SUBMITTED])->save();

        $this->actingAs($candidat)
            ->get("/candidate/application/{$application->getKey()}/eligibility")
            ->assertOk();
    }

    // — Cloisonnement ——————————————————————————————————————————————

    /**
     * Les deux points d'entrée résolvent le dossier depuis la **session**.
     *
     * La requête ne porte aucun identifiant : il n'y a rien à modifier dans
     * l'URL pour atteindre le dossier d'un autre. Deux candidats, deux dossiers,
     * dans la même campagne — chacun arrive chez lui.
     */
    public function test_les_entrees_resolvent_le_dossier_du_candidat_connecte(): void
    {
        $campagne = $this->campagne();
        $premier = $this->candidat();
        $dossierDuPremier = $this->brouillon($premier, $campagne);

        $second = $this->candidat();
        $dossierDuSecond = $this->brouillon($second, $campagne);

        $this->assertNotSame($dossierDuPremier->getKey(), $dossierDuSecond->getKey());

        $this->actingAs($second)->get('/candidate/application')
            ->assertRedirect("/candidate/application/{$dossierDuSecond->getKey()}/eligibility");

        $this->actingAs($second)->get('/candidate/profile')
            ->assertRedirect("/candidate/application/{$dossierDuSecond->getKey()}/profile");

        $this->actingAs($premier)->get('/candidate/profile')
            ->assertRedirect("/candidate/application/{$dossierDuPremier->getKey()}/profile");
    }

    /** @return array<string, array{string}> */
    public static function sectionsDuParcours(): array
    {
        return [
            'eligibilite' => ['eligibility'],
            'profil' => ['profile'],
            'structure' => ['team'],
            'defi' => ['challenge'],
            'solution' => ['solution'],
            'impact' => ['impact'],
            'plan' => ['implementation'],
        ];
    }

    /** Les policies existantes ne sont ni contournées ni affaiblies. */
    #[DataProvider('sectionsDuParcours')]
    public function test_un_candidat_ne_navigue_pas_vers_le_dossier_d_un_autre_en_changeant_l_url(string $section): void
    {
        $campagne = $this->campagne();
        $proprietaire = $this->candidat();
        $application = $this->brouillon($proprietaire, $campagne);

        $this->actingAs($this->candidat())
            ->get("/candidate/application/{$application->getKey()}/{$section}")
            ->assertForbidden();
    }

    #[DataProvider('entreesDeNavigation')]
    public function test_un_visiteur_n_atteint_aucune_entree_de_navigation(string $entree): void
    {
        $this->campagne();

        $this->get($entree)->assertRedirect('/login');
    }

    /** @return array<string, array{UserRole}> */
    public static function rolesInternes(): array
    {
        return [
            'administrateur' => [UserRole::ADMIN],
            'evaluateur' => [UserRole::EVALUATOR],
            'jury' => [UserRole::JURY],
        ];
    }

    /** L'espace candidat n'est pas une porte d'entrée pour un rôle interne. */
    #[DataProvider('rolesInternes')]
    public function test_un_role_interne_n_ouvre_pas_les_entrees_candidat(UserRole $role): void
    {
        $this->campagne();
        $interne = User::factory()->role($role)->create();

        $this->actingAs($interne)->get('/candidate/profile')->assertForbidden();
        $this->actingAs($interne)->get('/candidate/application')->assertForbidden();
    }

    // — Stabilité de la reprise ————————————————————————————————————

    /**
     * Deux passages consécutifs par le point d'entrée mènent au même endroit.
     *
     * C'est la garantie qu'un rafraîchissement ne déplace pas le candidat : la
     * cible est calculée depuis l'état stocké, pas depuis un compteur de visites.
     */
    public function test_la_reprise_est_stable_d_un_appel_a_l_autre(): void
    {
        $candidat = $this->candidat();
        $campagne = $this->campagne();
        $application = $this->brouillon($candidat, $campagne);
        $application->forceFill(['current_step' => ApplicationSection::SOLUTION])->save();

        $attendu = "/candidate/application/{$application->getKey()}/solution";

        $this->actingAs($candidat)->get('/candidate/application')->assertRedirect($attendu);
        $this->actingAs($candidat)->get('/candidate/application')->assertRedirect($attendu);
    }
}
