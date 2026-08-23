<?php

namespace App\Http\Requests\Admin;

use App\Domain\Candidate\CandidateType;
use App\Domain\Eligibility\EligibilitySettings;
use App\Domain\Reference\NigerRegion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation serveur des paramètres d'éligibilité (ADR-010).
 *
 * L'écran propose des cases à cocher et des listes fermées ; rien de cela
 * n'engage, la requête peut être forgée. Ce qui entre dans `settings` est ce
 * que cette classe accepte — et ce qui y entre décide du verdict annoncé à
 * chaque candidat de la campagne.
 *
 * Deux points méritent d'être lus avant d'y toucher :
 *
 * - **Le vide n'est pas une valeur.** Un champ laissé vide n'est pas « 0 »,
 *   n'est pas « aucune région » et n'est pas « non » : il est absent, donc le
 *   critère reste non publié (ADR-007). Les règles sont toutes `nullable`, et
 *   `payload()` renvoie `null` — jamais un défaut de convenance.
 *
 * - **Le lien avec le Niger a trois états.** Il arrive comme la chaîne `'true'`
 *   ou `'false'`, ou pas du tout. `null` signifie « la campagne ne s'est pas
 *   prononcée », `false` signifie « aucune condition » — ce qui est une
 *   décision, et n'a pas le même effet sur le candidat. Toute autre valeur est
 *   refusée plutôt que ramenée silencieusement à l'un des trois états.
 */
final class SaveEligibilitySettingsRequest extends FormRequest
{
    /**
     * Un booléen JSON est accepté au même titre que sa forme textuelle : le
     * formulaire envoie des chaînes, un client d'API enverrait des booléens, et
     * les deux disent exactement la même chose.
     */
    protected function prepareForValidation(): void
    {
        $lien = $this->input('requires_niger_link');

        if (is_bool($lien)) {
            $this->merge(['requires_niger_link' => $lien ? 'true' : 'false']);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            // 120 ans n'est pas une règle métier : c'est la borne au-delà de
            // laquelle une saisie est forcément une faute de frappe.
            'age_min' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_max' => ['nullable', 'integer', 'min:0', 'max:120'],
            'age_reference_date' => ['nullable', 'date_format:Y-m-d'],

            'requires_niger_link' => ['nullable', 'string', 'in:true,false'],

            // Le référentiel des régions est celui du serveur (ISO 3166-2:NE) :
            // une zone hors liste n'est pas une zone du Niger.
            'regions' => ['nullable', 'array'],
            'regions.*' => ['distinct', Rule::enum(NigerRegion::class)],

            'candidate_types' => ['nullable', 'array'],
            'candidate_types.*' => ['distinct', Rule::enum(CandidateType::class)],

            // Une équipe de zéro personne n'existe pas ; le plafond arrête les
            // saisies aberrantes sans rien décider du seuil réel.
            'team_size_min' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'team_size_max' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ageMin = $this->entier('age_min');
            $ageMax = $this->entier('age_max');

            if ($ageMin !== null && $ageMax !== null && $ageMax < $ageMin) {
                $validator->errors()->add(
                    'age_max',
                    __('L\'âge maximum ne peut pas être inférieur à l\'âge minimum.'),
                );
            }

            // Une date de référence seule ne s'applique à rien : le moteur ne
            // calcule un âge que s'il a une borne à lui opposer. Enregistrer la
            // date sans borne laisserait croire que le critère d'âge est publié,
            // alors que le candidat continuerait de lire « sous réserve ».
            if ($ageMin === null && $ageMax === null && $this->filled('age_reference_date')) {
                $validator->errors()->add(
                    'age_reference_date',
                    __('Une date de référence ne s\'applique qu\'à une tranche d\'âge : indiquez un âge minimum ou maximum.'),
                );
            }

            $tailleMin = $this->entier('team_size_min');
            $tailleMax = $this->entier('team_size_max');

            if ($tailleMin !== null && $tailleMax !== null && $tailleMax < $tailleMin) {
                $validator->errors()->add(
                    'team_size_max',
                    __('L\'effectif maximum ne peut pas être inférieur à l\'effectif minimum.'),
                );
            }
        });
    }

    /** Paramètres normalisés, prêts pour `SaveEligibilitySettings`. */
    public function reglages(): EligibilitySettings
    {
        return EligibilitySettings::fromValidated([
            'age_min' => $this->entier('age_min'),
            'age_max' => $this->entier('age_max'),
            'age_reference_date' => $this->filled('age_reference_date') ? (string) $this->input('age_reference_date') : null,
            'requires_niger_link' => match ($this->input('requires_niger_link')) {
                'true' => true,
                'false' => false,
                default => null,
            },
            'regions' => $this->listeDEnums('regions', NigerRegion::class),
            'candidate_types' => $this->listeDEnums('candidate_types', CandidateType::class),
            'team_size_min' => $this->entier('team_size_min'),
            'team_size_max' => $this->entier('team_size_max'),
        ]);
    }

    private function entier(string $champ): ?int
    {
        return $this->filled($champ) ? (int) $this->input($champ) : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>
     *
     * Nom volontairement distinct d'`enums()` : `Illuminate\Http\Request` en
     * expose déjà une, publique, et la redéclarer en privé est une erreur fatale.
     */
    private function listeDEnums(string $champ, string $enum): array
    {
        $valeurs = $this->input($champ);

        if (! is_array($valeurs)) {
            return [];
        }

        // La validation a déjà refusé toute valeur hors référentiel : ce filtre
        // ne rattrape rien, il donne son type à la liste.
        return array_values(array_filter(array_map(
            static fn (mixed $valeur) => is_string($valeur) ? $enum::tryFrom($valeur) : null,
            $valeurs,
        )));
    }
}
