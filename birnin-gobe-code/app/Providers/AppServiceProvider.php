<?php

namespace App\Providers;

use App\Domain\Application\Scanning\ClamAvScanner;
use App\Domain\Application\Scanning\UnavailableScanner;
use App\Domain\Application\Scanning\VirusScanner;
use App\Listeners\RefermerLaTraceDEnvoi;
use App\Models\Application;
use App\Policies\ApplicationPolicy;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * L'analyseur antivirus, résolu depuis la configuration.
     *
     * Lié dans `register()` et non dans `boot()` : le job d'analyse le demande
     * par injection, et une liaison posée trop tard laisserait Laravel
     * instancier `VirusScanner` par réflexion — impossible sur une interface.
     *
     * **Aucune branche ne rend un analyseur permissif.** Sans configuration, on
     * obtient `UnavailableScanner`, qui rend « indisponible » et laisse donc les
     * téléchargements fermés. Voir `config/scanning.php` : l'interrupteur
     * n'ouvre rien quand il est sur « off ».
     */
    public function register(): void
    {
        $this->app->bind(VirusScanner::class, static function (): VirusScanner {
            if (! config('scanning.enabled')) {
                return new UnavailableScanner('Aucun analyseur antivirus n’est configuré pour cet environnement.');
            }

            return new ClamAvScanner(
                host: (string) config('scanning.host'),
                port: (int) config('scanning.port'),
                timeout: (int) config('scanning.timeout'),
                maxBytes: (int) config('scanning.max_bytes'),
            );
        });
    }

    public function boot(): void
    {
        // Déclarée explicitement plutôt que laissée à la découverte par
        // convention de nommage : un renommage de classe casserait alors le
        // contrôle d'accès en silence, sans qu'aucune erreur ne soit levée.
        Gate::policy(Application::class, ApplicationPolicy::class);

        // Même raison pour l'écouteur : la découverte automatique des
        // événements repose sur le type déclaré en signature, et une trace
        // d'envoi qui cesserait silencieusement de se refermer laisserait
        // croire à un répartiteur en panne alors que tout fonctionne.
        Event::listen(NotificationSent::class, RefermerLaTraceDEnvoi::class);
    }
}
