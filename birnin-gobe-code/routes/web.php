<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Séparation des espaces — contrainte d'architecture non négociable
|--------------------------------------------------------------------------
|
| Trois espaces distincts, chacun avec son propre flux d'accès :
|
|   public    → portail, aucune authentification
|   candidate → espace du candidat, authentification candidat
|   internal  → administration, évaluation, jury — accès interne séparé
|
| Le portail public et l'espace candidat ne doivent exposer aucun lien vers
| l'espace interne. Voir docs/decisions/ADR-003-separation-des-espaces.md.
|
| ATTENTION — état actuel : l'authentification n'est pas implémentée. Les
| groupes ci-dessous portent les responsabilités et la place des middlewares,
| mais AUCUNE protection n'est active. Toutes ces routes sont publiquement
| joignables. Ne pas confondre cette structure avec une sécurité effective :
| masquer un lien n'est pas une autorisation.
|
*/

/*
| Espace public
*/
Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');

/*
| Espace candidat
|
| À brancher quand l'authentification existera :
|   ->middleware(['auth', 'verified', 'role:candidate'])
| plus le cadrage par campagne et les policies de ressources.
*/
Route::prefix('candidate')->name('candidate.')->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Candidate/Dashboard'))->name('dashboard');
    Route::get('/application/challenge', fn () => Inertia::render('Candidate/Application/Challenge'))->name('application.challenge');
});

/*
| Espaces internes — administration, évaluation, jury
|
| Volontairement séparés de l'espace candidat. Leur existence technique ne doit
| jamais être annoncée dans l'interface publique ou candidate.
|
| À brancher quand l'authentification existera :
|   ->middleware(['auth', 'verified', 'role:admin'])       pour l'administration
|   ->middleware(['auth', 'verified', 'role:evaluator'])   pour l'évaluation
|   ->middleware(['auth', 'verified', 'role:jury'])        pour le jury
|
| Un candidat qui saisit ces URL manuellement doit alors recevoir un 403.
| Aujourd'hui il obtient la page : c'est le blocage principal avant toute
| mise en ligne avec de vraies données.
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');
});

Route::prefix('evaluator')->name('evaluator.')->group(function (): void {
    Route::get('/assignments', fn () => Inertia::render('Evaluator/Assignments'))->name('assignments');
});
