<?php

namespace App\Providers;

use App\Models\Application;
use App\Policies\ApplicationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Déclarée explicitement plutôt que laissée à la découverte par
        // convention de nommage : un renommage de classe casserait alors le
        // contrôle d'accès en silence, sans qu'aucune erreur ne soit levée.
        Gate::policy(Application::class, ApplicationPolicy::class);
    }
}
