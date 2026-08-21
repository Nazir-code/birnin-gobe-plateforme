<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Public/Home'))->name('home');

// Prototype routes. Production: protect with auth/verified + resource policies + campaign scope.
Route::prefix('candidate')->group(function (): void {
    Route::get('/dashboard', fn () => Inertia::render('Candidate/Dashboard'))->name('candidate.dashboard');
    Route::get('/application/challenge', fn () => Inertia::render('Candidate/Application/Challenge'))->name('candidate.application.challenge');
});

Route::get('/admin/dashboard', fn () => Inertia::render('Admin/Dashboard'))->name('admin.dashboard');
Route::get('/evaluator/assignments', fn () => Inertia::render('Evaluator/Assignments'))->name('evaluator.assignments');
