<?php

namespace App\Console\Commands;

use App\Domain\Application\ApplicationSection;
use App\Models\Application;
use App\Models\ApplicationSectionAnswers;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Fixture de bout en bout : achève une section qui n'a pas encore d'écran.
 *
 * **Pourquoi cette commande existe.** L'étape 9 se teste de bout en bout — un
 * candidat remplit son dossier, le relit, le dépose — mais l'étape 8
 * « Pièces / déclarations » est développée sur une autre branche et n'a pas
 * encore de formulaire. Sans elle, aucun dossier n'atteint la recevabilité, et
 * le scénario le plus important de cette phase ne serait pas éprouvé du tout.
 *
 * Elle écrit donc la ligne que l'écran manquant écrira : des réponses et un
 * `completed_at`. Rien de plus. `SubmissionReadiness` ne regarde que cette date,
 * si bien que le jour où l'étape 8 aura son formulaire, le test passera par lui
 * et cette commande disparaîtra du scénario sans qu'aucune règle ne change.
 *
 * **Ce qu'elle ne fait pas** : créer un compte, contourner une authentification,
 * modifier un statut, produire un numéro de dépôt. Elle ne sait qu'achever une
 * section d'un dossier existant, désigné par l'adresse de son propriétaire.
 *
 * Elle refuse de s'exécuter hors `local` et `testing` : une commande qui
 * complète des sections n'a rien à faire sur une production, même entre les
 * mains d'un administrateur.
 */
final class AcheverSectionPourE2E extends Command
{
    protected $signature = 'e2e:achever-section
        {email : Adresse du candidat propriétaire du dossier}
        {section : Clé de la section, par exemple attachments}';

    protected $description = 'Achève une section de candidature pour les scénarios de bout en bout (hors production).';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Commande réservée aux environnements local et testing.');

            return self::FAILURE;
        }

        $section = ApplicationSection::tryFrom((string) $this->argument('section'));

        if ($section === null) {
            $this->error('Section inconnue : '.$this->argument('section'));

            return self::FAILURE;
        }

        $candidat = User::query()->where('email', $this->argument('email'))->first();

        if ($candidat === null) {
            $this->error('Aucun compte pour '.$this->argument('email'));

            return self::FAILURE;
        }

        $dossier = Application::query()
            ->where('candidate_id', $candidat->getKey())
            ->latest('id')
            ->first();

        if ($dossier === null) {
            $this->error('Ce compte n’a aucune candidature.');

            return self::FAILURE;
        }

        $ligne = ApplicationSectionAnswers::query()->firstOrNew([
            'application_id' => $dossier->getKey(),
            'section' => $section->value,
        ]);

        $ligne->answers = ['fixture_e2e' => 'Section achevée par la fixture de bout en bout.'];
        $ligne->completed_at ??= now();
        $ligne->save();

        $this->info(sprintf('Section « %s » achevée pour la candidature %d.', $section->label(), $dossier->getKey()));

        return self::SUCCESS;
    }
}
