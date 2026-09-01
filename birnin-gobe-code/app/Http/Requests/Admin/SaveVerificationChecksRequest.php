<?php

namespace App\Http\Requests\Admin;

use App\Domain\Verification\VerificationControl;
use App\Domain\Verification\VerificationOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation serveur de la grille d'admissibilité (§10.2).
 *
 * Contrairement aux filtres de la file, **ici on refuse, on ne réduit pas.**
 * Un filtre illisible peut être ignoré sans conséquence ; une coche illisible
 * ne peut pas l'être, parce que le vérificateur croirait avoir enregistré un
 * verdict qui n'a pas été écrit. Une erreur de validation est le seul message
 * honnête.
 *
 * Deux contrôles ne peuvent pas se faire par les règles de Laravel seules et
 * vivent dans `after()` :
 *
 *  - **le verdict doit appartenir au contrôle** : `VerificationOutcome` est un
 *    enum commun aux sept familles, donc `Rule::enum` le laisserait passer
 *    n'importe où. C'est `VerificationControl::accepts()` qui tranche, la même
 *    méthode que le cas d'usage — jamais une seconde liste ;
 *  - **un verdict qui n'est pas « le contrôle est passé » exige une
 *    observation**, et l'erreur doit désigner le champ fautif pour que l'écran
 *    la place sous la bonne ligne.
 *
 * Le cas d'usage revérifie les deux : cette classe sert l'écran, elle ne
 * protège pas le domaine. `SaveVerificationChecks` est appelable hors HTTP.
 */
final class SaveVerificationChecksRequest extends FormRequest
{
    /** Une observation est une phrase de contrôle, pas un rapport. */
    private const OBSERVATION_MAX = 1000;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'checks' => ['required', 'array', 'min:1'],
            'checks.*.control' => ['required', Rule::enum(VerificationControl::class)],
            'checks.*.outcome' => ['required', Rule::enum(VerificationOutcome::class)],
            'checks.*.observation' => ['nullable', 'string', 'max:'.self::OBSERVATION_MAX],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validateur): void {
                foreach ((array) $this->input('checks', []) as $rang => $saisie) {
                    $controle = VerificationControl::tryFrom((string) ($saisie['control'] ?? ''));
                    $verdict = VerificationOutcome::tryFrom((string) ($saisie['outcome'] ?? ''));

                    if ($controle === null || $verdict === null) {
                        continue; // Déjà signalé par les règles ci-dessus.
                    }

                    if (! $controle->accepts($verdict)) {
                        $validateur->errors()->add(
                            "checks.{$rang}.outcome",
                            'Ce verdict n’appartient pas au contrôle « '.$controle->label().' ».',
                        );

                        continue;
                    }

                    if ($verdict->requiresObservation() && trim((string) ($saisie['observation'] ?? '')) === '') {
                        $validateur->errors()->add(
                            "checks.{$rang}.observation",
                            'Une observation est exigée : « '.$verdict->label().' » doit être expliqué.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * La grille, indexée par contrôle, telle que le cas d'usage l'attend.
     *
     * L'indexation par contrôle est faite ici plutôt que dans le domaine : elle
     * dédoublonne au passage une saisie qui enverrait deux fois la même ligne,
     * ce qui est une propriété de la requête, pas de la règle métier.
     *
     * @return array<string, array{outcome: VerificationOutcome, observation: ?string}>
     */
    public function grille(): array
    {
        $grille = [];

        foreach ((array) $this->input('checks', []) as $saisie) {
            $controle = VerificationControl::tryFrom((string) ($saisie['control'] ?? ''));
            $verdict = VerificationOutcome::tryFrom((string) ($saisie['outcome'] ?? ''));

            if ($controle === null || $verdict === null) {
                continue;
            }

            $observation = trim((string) ($saisie['observation'] ?? ''));

            $grille[$controle->value] = [
                'outcome' => $verdict,
                'observation' => $observation === '' ? null : $observation,
            ];
        }

        return $grille;
    }
}
