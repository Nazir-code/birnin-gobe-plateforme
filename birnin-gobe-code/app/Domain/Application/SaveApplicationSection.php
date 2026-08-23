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
     * Progression = sections achevées **du parcours ouvert**, sur les neuf.
     *
     * Volontairement grossière, et honnête. Deux restrictions, pour deux
     * raisons différentes :
     *
     *  - seules les sections **achevées** comptent : ouvrir un écran n'est pas
     *    le remplir, et `completed_at` est la seule preuve du contraire ;
     *  - seules les sections du **parcours ouvert** comptent. « Défi » est
     *    développé mais se trouve derrière « Structure / équipe », qui ne l'est
     *    pas : l'annoncer comme un neuvième de dossier fait laisserait croire
     *    que le parcours avance alors qu'il est encore fermé à l'étape 3.
     *
     * Les réponses de « Défi » ne sont ni effacées ni ignorées pour autant :
     * elles restent en base et reprendront leur place dans le compte le jour où
     * l'étape 3 ouvrira. L'écran le dit au candidat plutôt que de le laisser
     * deviner pourquoi son pourcentage ne bouge pas.
     */
    private function progression(Application $application): int
    {
        $surLeParcours = array_map(
            static fn (ApplicationSection $section): string => $section->value,
            ApplicationSection::openPath(),
        );

        $achevees = ApplicationSectionAnswers::query()
            ->where('application_id', $application->getKey())
            ->whereNotNull('completed_at')
            ->whereIn('section', $surLeParcours)
            ->count();

        return (int) round($achevees / ApplicationSection::total() * 100);
    }
}
