<?php

use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\ApplicationController as AdminApplicationController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\CampaignEligibilityController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Candidate\ApplicationController;
use App\Http\Controllers\Candidate\AttachmentsSectionController;
use App\Http\Controllers\Candidate\ChallengeSectionController;
use App\Http\Controllers\Candidate\DashboardController;
use App\Http\Controllers\Candidate\EligibilitySectionController;
use App\Http\Controllers\Candidate\ImpactSectionController;
use App\Http\Controllers\Candidate\ImplementationSectionController;
use App\Http\Controllers\Candidate\ProfileSectionController;
use App\Http\Controllers\Candidate\SolutionSectionController;
use App\Http\Controllers\Candidate\SubmitApplicationController;
use App\Http\Controllers\Candidate\TeamSectionController;
use App\Http\Controllers\Public\HomeController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Séparation des espaces — contrainte d'architecture non négociable
|--------------------------------------------------------------------------
|
|   public    → portail, aucune authentification
|   candidate → espace du candidat        : auth + role:candidate
|   interne   → administration, évaluation, jury : auth + role:<rôle interne>
|
| Le portail public et l'espace candidat n'exposent aucun lien vers l'espace
| interne, et la protection n'est plus seulement visuelle : le middleware `role`
| répond 403. Voir docs/decisions/ADR-003-separation-des-espaces.md.
|
| Reste à faire : vérification d'e-mail et réinitialisation de mot de passe
| (Phase 1B), puis les policies de ressources et le cadrage par campagne quand
| les candidatures seront persistées.
|
*/

/*
| Espace public
*/
Route::get('/', HomeController::class)->name('home');

/*
| Accès candidat — réservé aux visiteurs non connectés
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);

    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // La limitation est portée par le contrôleur, clé sur e-mail + IP
    // plutôt que sur la seule adresse : voir AuthenticatedSessionController.
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
| Espace candidat
|
| Deux niveaux d'autorisation, et il faut les deux :
|   `role:candidate`        protège l'ESPACE — qui a le droit d'être ici ;
|   `can:...,application`   protège la RESSOURCE — quel dossier est le sien.
|
| Le premier laisse passer tous les candidats, y compris vers le dossier d'un
| autre. Le second est porté par ApplicationPolicy, déclaré sur la route plutôt
| que vérifié dans les contrôleurs : une route ajoutée sans sa déclaration se
| voit à la relecture, un `if` oublié dans une méthode non.
*/
Route::middleware(['auth', 'role:candidate'])
    ->prefix('candidate')
    ->name('candidate.')
    ->group(function (): void {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Création du brouillon. POST : cette requête écrit en base.
        Route::post('/application', [ApplicationController::class, 'store'])->name('application.store');
        // Entrée « Ma candidature » de la navigation : redirige, n'écrit rien.
        Route::get('/application', [ApplicationController::class, 'show'])->name('application.entry');

        // Entrée « Mon profil » de la navigation. Le profil du candidat est
        // l'etape 2 de sa candidature : cette route y mene, elle ne cree pas un
        // second systeme de profil. Redirige, n'ecrit rien.
        Route::get('/profile', [ApplicationController::class, 'profile'])->name('profile.entry');

        // Etape 1 — l'auto-test d'eligibilite. Aucune barriere d'eligibilite
        // sur ses propres routes : c'est ici qu'on corrige ses reponses.
        Route::get('/application/{application}/eligibility', [EligibilitySectionController::class, 'edit'])
            ->middleware('can:view,application')
            ->name('application.eligibility');

        Route::patch('/application/{application}/eligibility', [EligibilitySectionController::class, 'update'])
            ->middleware('can:update,application')
            ->name('application.eligibility.update');

        // Sections posterieures a l'eligibilite : fermees tant qu'une regle
        // bloquante est declenchee (cahier des charges 5.2). Declare sur la
        // route, comme `can:` — voir EnsureApplicationIsEligible. Elle vaut pour
        // toutes les sections ci-dessous, de « Profil » au « Plan de mise en
        // oeuvre ».

        // Etape 2 — profil du candidat.
        Route::get('/application/{application}/profile', [ProfileSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.profile');

        Route::patch('/application/{application}/profile', [ProfileSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.profile.update');

        // Etape 3 — structure / equipe. Son ouverture referme le trou du
        // parcours : « Defi » (etape 4) rejoint mecaniquement le parcours
        // ouvert. Voir ApplicationSection::isOnOpenPath() et ADR-011.
        Route::get('/application/{application}/team', [TeamSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.team');

        Route::patch('/application/{application}/team', [TeamSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.team.update');

        // Etape 4 — defi.
        Route::get('/application/{application}/challenge', [ChallengeSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.challenge');

        Route::patch('/application/{application}/challenge', [ChallengeSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.challenge.update');

        // Etape 5 — solution proposee (cahier des charges 5.2 etape 5, 7.1
        // rubriques Identification et Solution).
        Route::get('/application/{application}/solution', [SolutionSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.solution');

        Route::patch('/application/{application}/solution', [SolutionSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.solution.update');

        // Etape 6 — impact et viabilite (5.2 etape 6, 7.1 rubriques Impact,
        // Inclusion et Viabilite). Description par le candidat, jamais notation.
        Route::get('/application/{application}/impact', [ImpactSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.impact');

        Route::patch('/application/{application}/impact', [ImpactSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.impact.update');

        // Etape 7 — plan de mise en oeuvre (5.2 etape 7, 7.1 rubrique
        // Execution). Derniere etape du parcours ouvert a ce jour.
        Route::get('/application/{application}/implementation', [ImplementationSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.implementation');

        Route::patch('/application/{application}/implementation', [ImplementationSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.implementation.update');

        // Etape 8 — pieces et declarations (5.2 etape 8, 7.2 pieces, 7.3
        // declarations). Derniere etape de contenu : « Relecture / envoi »
        // (etape 9) n'est pas developpee ici, et aucun bouton de depot ne vit
        // sur cet ecran.
        Route::get('/application/{application}/attachments', [AttachmentsSectionController::class, 'edit'])
            ->middleware(['can:view,application', 'eligible'])
            ->name('application.attachments');

        // Les declarations : meme chemin que les sept sections precedentes.
        Route::patch('/application/{application}/attachments', [AttachmentsSectionController::class, 'update'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.attachments.update');

        // Les pieces : leurs propres routes. Un fichier se depose une fois, se
        // remplace ou se retire — il n'a rien a faire dans la sauvegarde
        // automatique, qui repartirait a chaque frappe.
        //
        // `can:update` porte les deux gardes : le dossier est le sien, et il est
        // encore un brouillon. Un televersement apres soumission tombe donc en
        // 403 sans jamais atteindre le disque.
        Route::post('/application/{application}/attachments/documents', [AttachmentsSectionController::class, 'storeDocument'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.attachments.documents.store');

        Route::delete('/application/{application}/attachments/documents/{type}', [AttachmentsSectionController::class, 'destroyDocument'])
            ->middleware(['can:update,application', 'eligible'])
            ->name('application.attachments.documents.destroy');

        // Telechargement par le proprietaire. `can:view` et non `can:update` :
        // un dossier soumis reste consultable par celui qui l'a depose, pieces
        // comprises. Aucun chemin de stockage ne transite — la piece est
        // designee par son type.
        Route::get('/application/{application}/attachments/documents/{type}', [AttachmentsSectionController::class, 'downloadDocument'])
            ->middleware('can:view,application')
            ->name('application.attachments.documents.download');

        // Depot officiel. `can:update` porte deja les deux gardes qui comptent :
        // le dossier est le sien, et il est encore un brouillon — un second
        // envoi tombe donc en 403 sans jamais atteindre le domaine.
        //
        // Pas de middleware `eligible` ici, a dessein : il redirige vers l'etape
        // 1, ce qui convient a une navigation mais pas a un depot. Le refus doit
        // nommer son motif, et `SubmitApplication` le fait — eligibilite
        // comprise. Voir SubmissionReadiness.
        Route::post('/application/{application}/submit', SubmitApplicationController::class)
            ->middleware('can:update,application')
            ->name('application.submit');
    });

/*
| Espaces internes — administration, évaluation, jury
|
| Volontairement séparés. Leur existence n'est annoncée nulle part dans
| l'interface publique ou candidate — /admin/login compris : la page est
| joignable, jamais liée depuis un écran public ou candidat.
|
| Aucun compte interne n'est créable par l'inscription publique : le seul
| chemin de création est `php artisan admin:create` (ADR-006). Évaluation et
| jury n'ont pas encore d'accès interne, seule leur règle d'accès est posée.
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    // Accès interne. Aucune inscription : les comptes internes sont
    // provisionnés par `php artisan admin:create` (ADR-006).
    Route::get('/login', [AdminSessionController::class, 'create'])->name('login');
    Route::post('/login', [AdminSessionController::class, 'store']);

    Route::middleware(['auth', 'role:admin'])->group(function (): void {
        Route::post('/logout', [AdminSessionController::class, 'destroy'])->name('logout');

        Route::get('/dashboard', AdminDashboardController::class)->name('dashboard');

        // Administration des campagnes (ADR-008). Pas de route de suppression :
        // `applications.campaign_id` est en cascade, supprimer une campagne
        // emporterait les dossiers déposés. L'archivage tient ce rôle.
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');

        // Critères d'éligibilité de la campagne (ADR-010). Écran séparé du
        // formulaire de campagne : ce que le comité de pilotage arbitre ne se
        // modifie pas au même moment que le nom et les dates de l'édition.
        Route::get('/campaigns/{campaign}/eligibility', [CampaignEligibilityController::class, 'edit'])
            ->name('campaigns.eligibility.edit');
        Route::put('/campaigns/{campaign}/eligibility', [CampaignEligibilityController::class, 'update'])
            ->name('campaigns.eligibility.update');

        // Consultation des candidatures (Admin Phase 3). Deux routes, toutes
        // deux en lecture : tant qu'un dossier n'est pas soumis, le candidat en
        // reste propriétaire. Aucune route d'écriture ne doit rejoindre ce
        // groupe sans le workflow d'admissibilité qui la justifierait.
        Route::get('/applications', [AdminApplicationController::class, 'index'])->name('applications.index');
        Route::get('/applications/{application}', [AdminApplicationController::class, 'show'])
            ->name('applications.show');

        // Lecture d'une piece jointe. Le 8.1 demande que le controle signale
        // les « pieces illisibles » : encore faut-il pouvoir les ouvrir. C'est
        // la seule route documentaire cote administration, et elle ne fait que
        // lire — aucun remplacement, aucune suppression, aucun statut.
        Route::get('/applications/{application}/documents/{type}', [AdminApplicationController::class, 'downloadDocument'])
            ->name('applications.documents.download');
    });
});

Route::middleware(['auth', 'role:evaluator'])
    ->prefix('evaluator')
    ->name('evaluator.')
    ->group(function (): void {
        Route::get('/assignments', fn () => Inertia::render('Evaluator/Assignments'))->name('assignments');
    });

Route::middleware(['auth', 'role:jury'])
    ->prefix('jury')
    ->name('jury.')
    ->group(function (): void {
        // L'espace jury n'a pas encore d'écran. Le groupe existe pour que la
        // règle d'accès soit posée dès maintenant et testable.
        Route::get('/dashboard', fn () => Inertia::render('Evaluator/Assignments'))->name('dashboard');
    });
