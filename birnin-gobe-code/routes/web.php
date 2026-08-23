<?php

use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\CampaignController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Candidate\ApplicationController;
use App\Http\Controllers\Candidate\ChallengeSectionController;
use App\Http\Controllers\Candidate\DashboardController;
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
Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');

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

        Route::get('/application/{application}/challenge', [ChallengeSectionController::class, 'edit'])
            ->middleware('can:view,application')
            ->name('application.challenge');

        Route::patch('/application/{application}/challenge', [ChallengeSectionController::class, 'update'])
            ->middleware('can:update,application')
            ->name('application.challenge.update');
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

        // Administration des campagnes (ADR-007). Pas de route de suppression :
        // `applications.campaign_id` est en cascade, supprimer une campagne
        // emporterait les dossiers déposés. L'archivage tient ce rôle.
        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
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
