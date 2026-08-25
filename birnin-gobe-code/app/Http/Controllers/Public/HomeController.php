<?php

namespace App\Http\Controllers\Public;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ProjectTheme;
use App\Domain\Auth\UserRole;
use App\Domain\Campaign\ActiveCampaign;
use App\Models\Application;
use App\Models\Campaign;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Page d'accueil publique.
 *
 * La route rendait `Public/Home` sans rien lui passer, et l'écran comblait le
 * vide avec `resources/js/data/demo.ts` : un nom d'édition, une date de clôture
 * au 30 juin 2026 et un décompte figé à 24 j 12 h 45 m 30 s. Des valeurs de
 * maquette, présentées au public comme des informations officielles.
 *
 * C'est le genre d'erreur qui ne se rattrape pas : un candidat qui lit une date
 * limite fausse sur le site officiel et dépose après la vraie clôture n'a rien
 * fait de mal. La page lit donc désormais la campagne, ou dit qu'il n'y en a pas.
 *
 * De la campagne, l'écran ne reçoit que ce qu'une page publique doit savoir : le
 * nom, le code, la clôture et le fuseau dans lequel elle s'entend. Ni paramètres
 * d'éligibilité, ni configuration interne.
 *
 * `null` est une réponse à part entière : hors période de dépôt, l'écran
 * l'annonce et ferme son appel à candidature plutôt que d'inventer un décompte.
 *
 * **Les chiffres clés, eux, sont comptés.** La page en affichait quatre —
 * « 5 000+ jeunes impactés », « 1 200+ projets accompagnés » et les suivants —
 * qui ne venaient d'aucune mesure. Ils ont été retirés faute de source ; ils
 * reviennent ici parce qu'il en existe désormais une. Trois `count()`, aucune
 * relation chargée, aucune boucle : la page d'accueil est la plus visitée du
 * site et n'a pas à peser sur la base.
 */
final class HomeController
{
    public function __invoke(ActiveCampaign $campagnes): Response
    {
        $active = $campagnes->resolve();

        return Inertia::render('Public/Home', [
            'campaign' => $active === null ? null : $this->campagne($active),
            'stats' => $this->chiffres(),
            'themes' => $this->thematiques(),
            'criteria' => $this->criteres(),
        ]);
    }

    /**
     * Ce que la plateforme peut affirmer, et rien d'autre.
     *
     * Les comptes internes — administration, évaluation, jury — sont exclus du
     * nombre de candidats : les compter gonflerait l'affichage avec des
     * personnes qui ne candidatent pas. Le filtre porte donc sur le rôle, jamais
     * sur un simple `users`.
     *
     * Zéro est une valeur légitime et doit s'afficher telle quelle. Une
     * plateforme qui vient d'ouvrir n'a pas de candidat ; le dire est exact, et
     * plus honnête qu'un nombre arrondi qui ferait croire à une affluence.
     *
     * @return array{candidates: int, draftApplications: int, submittedApplications: int, themes: int}
     */
    private function chiffres(): array
    {
        return [
            'candidates' => User::query()->where('role', UserRole::CANDIDATE->value)->count(),
            'draftApplications' => Application::query()->where('status', ApplicationStatus::DRAFT->value)->count(),
            'submittedApplications' => Application::query()->where('status', ApplicationStatus::SUBMITTED->value)->count(),
            // Le compte suit la liste servie juste en dessous : impossible que
            // le chiffre annonce et les cartes affichees divergent.
            'themes' => count($this->thematiques()),
        ];
    }

    /**
     * Les quatre thématiques officielles du concours.
     *
     * **Servies par le serveur, et non écrites dans le composant.** Le contrat
     * du dépôt est explicite : le contenu dynamique — thématiques, critères,
     * FAQ, textes d'aide — vient de la configuration, jamais d'une constante
     * React. Les cinq thématiques génériques qui figuraient sur la page
     * (« Agroalimentaire », « Numérique », « Énergie & Environnement »…)
     * venaient de `data/demo.ts` et ne correspondaient à aucun document du
     * concours.
     *
     * Le contenu lui-même a quitté ce contrôleur pour `ProjectTheme` : la
     * candidature demande désormais au candidat sous quelle thématique il
     * concourt, et deux listes séparées auraient fini par diverger. Le portail
     * et le formulaire lisent la même enum ; l'ordre et les textes sont
     * inchangés.
     *
     * @return list<array{key: string, title: string, problems: string, results: string}>
     */
    private function thematiques(): array
    {
        return ProjectTheme::content();
    }

    /**
     * Les huit critères d'évaluation, avec leur question directrice.
     *
     * **À ne pas confondre avec l'éligibilité**, et la page le dit explicitement.
     * L'éligibilité décide qui a le droit de déposer — âge, nationalité, zone,
     * forme de candidature — et `EvaluateEligibility` la calcule campagne par
     * campagne. Ces critères-ci disent comment un dossier recevable sera jugé
     * ensuite. Les présenter sur la même page sans le préciser ferait croire à
     * un candidat qu'il doit satisfaire les huit pour avoir le droit de
     * candidater.
     *
     * Aucun moteur d'éligibilité n'est touché : ceci est du contenu.
     *
     * @return list<array{key: string, title: string, question: string}>
     */
    private function criteres(): array
    {
        return [
            ['key' => 'pertinence', 'title' => 'Pertinence', 'question' => 'La solution répond-elle précisément au défi et aux usages prioritaires ?'],
            ['key' => 'impact', 'title' => 'Impact usager', 'question' => 'Le bénéfice attendu est-il mesurable, utile et inclusif ?'],
            ['key' => 'faisabilite', 'title' => 'Faisabilité', 'question' => 'Le MVP peut-il fonctionner avec les ressources, données et délais disponibles ?'],
            ['key' => 'technique', 'title' => 'Qualité technique', 'question' => 'L’architecture, les performances, les données et la documentation sont-elles solides ?'],
            ['key' => 'innovation', 'title' => 'Innovation utile', 'question' => 'La proposition améliore-t-elle significativement l’existant sans complexité inutile ?'],
            ['key' => 'securite', 'title' => 'Sécurité', 'question' => 'Les accès, données, sauvegardes et risques sont-ils correctement maîtrisés ?'],
            ['key' => 'durabilite', 'title' => 'Durabilité', 'question' => 'Maintenance, support, interopérabilité et coût total sont-ils crédibles ?'],
            ['key' => 'equipe', 'title' => 'Équipe et pitch', 'question' => 'L’équipe réunit-elle les compétences et la capacité d’exécution requises ?'],
        ];
    }

    /**
     * @return array{name: string, code: string, opensAt: string|null, closesAt: string|null, timezone: string}
     */
    private function campagne(Campaign $campagne): array
    {
        return [
            'name' => $campagne->name,
            'code' => $campagne->code,
            // ISO 8601 avec décalage : le navigateur en tire l'instant exact, et
            // le décompte reste juste où que se trouve le visiteur. Le fuseau
            // part à côté pour que la date affichée reste celle annoncée par le
            // concours, et non celle du téléphone de qui la lit.
            'opensAt' => $campagne->opens_at?->toIso8601String(),
            'closesAt' => $campagne->closes_at?->toIso8601String(),
            'timezone' => $campagne->timezone ?: config('app.timezone'),
        ];
    }
}
