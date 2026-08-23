<?php

namespace App\Domain\Application;

use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use Illuminate\Support\Facades\DB;

/**
 * Enregistre les réponses d'une section et met à jour l'avancement.
 *
 * Le contenu est remplacé, pas fusionné : l'écran envoie toujours la section
 * entière, et une fusion ferait survivre indéfiniment une réponse que le
 * candidat vient d'effacer.
 *
 * Aucun événement d'audit ici. La sauvegarde de brouillon se déclenche toutes
 * les quelques secondes pendant la saisie ; en tracer chacune noierait le
 * journal métier — qui doit rester lisible lors d'un contrôle — sous du bruit
 * de frappe. `updated_at` et les journaux techniques portent cette information.
 */
final readonly class SaveApplicationSection
{
    /**
     * @param  array<string, mixed>  $answers
     */
    public function handle(Application $application, ApplicationSection $section, array $answers, bool $complete): Application
    {
        return DB::transaction(function () use ($application, $section, $answers, $complete): Application {
            $ligne = ApplicationSectionAnswers::query()->firstOrNew([
                'application_id' => $application->getKey(),
                'section' => $section->value,
            ]);

            $ligne->answers = $answers;
            // La date d'achèvement d'origine est conservée tant que la section
            // reste complète : c'est une date d'événement, pas un drapeau.
            $ligne->completed_at = $complete ? ($ligne->completed_at ?? now()) : null;
            $ligne->save();

            $application->forceFill([
                'current_step' => $section->value,
                'completion_percent' => $this->progression($application),
            ])->save();

            return $application->refresh();
        });
    }

    /**
     * Progression, déléguée à `ApplicationProgress`.
     *
     * La règle vivait ici. Elle en est sortie quand l'administration a eu
     * besoin de la même : deux implémentations d'un même pourcentage finissent
     * toujours par diverger, et un dossier n'a qu'un avancement.
     */
    private function progression(Application $application): int
    {
        return ApplicationProgress::forApplication($application);
    }
}
