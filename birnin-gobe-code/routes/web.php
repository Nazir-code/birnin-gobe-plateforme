<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
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
*/
Route::middleware(['auth', 'role:candidate'])
    ->prefix('candidate')
    ->name('candidate.')
    ->group(function (): void {
        Route::get('/dashboard', fn () => Inertia::render('Candidate/Dashboard'))->name('dashboard');
        Route::get('/application/challenge', fn () => Inertia::render('Candidate/Application/Challenge'))->name('application.challenge');
    });

/*
| Espaces internes — administration, évaluation, jury
|
| Volontairement séparés. Leur existence n'est annoncée nulle part dans
| l'interface publique ou candidate. Les écrans eux-mêmes ne sont pas encore
| développés : seule la fondation de contrôle d'accès est posée ici.
|
| Aucun compte interne n'est créable par l'inscription publique.
*/
Route::middleware(['auth', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');
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
