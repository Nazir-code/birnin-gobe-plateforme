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
     * Progression = sections achevées sur les neuf du formulaire.
     *
     * Volontairement grossière, et honnête : tant qu'une seule section est
     * branchée, le maximum atteignable est 1/9. Une pondération par nombre de
     * champs supposerait connaître les huit sections restantes, ce qui n'est
     * pas le cas.
     */
    private function progression(Application $application): int
    {
        $achevees = ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->count();

        return (int) round($achevees / ApplicationSection::total() * 100);
    }
}
