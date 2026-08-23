<?php

namespace App\Http\Requests\Admin;

use App\Domain\Campaign\CampaignStatus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

/**
 * Validation serveur du formulaire de campagne.
 *
 * L'écran propose une liste de statuts et un sélecteur de fuseau, mais rien de
 * cela n'engage : la requête peut être forgée. Ce qui entre en base est ce que
 * cette classe accepte.
 *
 * L'autorisation n'est pas refaite ici : elle est portée par `auth` +
 * `role:admin` sur le groupe de routes (ADR-003).
 */
final class SaveCampaignRequest extends FormRequest
{
    /**
     * Le code est mis en forme avant d'être validé.
     *
     * Sans cela, la règle `regex` refuserait « bg-2027 » alors que l'intention
     * est sans ambiguïté — et le message d'erreur porterait sur la casse, ce qui
     * n'apprend rien à l'administrateur.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $campagne = $this->route('campaign');

        return [
            // Le code identifie l'édition et sert de référence externe : on le
            // borne à un alphabet sans ambiguïté plutôt que d'accepter
            // n'importe quelle chaîne.
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9-]*$/',
                Rule::unique('campaigns', 'code')->ignore($campagne),
            ],
            'name' => ['required', 'string', 'max:160'],
            'status' => ['required', new Enum(CampaignStatus::class)],
            // `timezone` valide l'identifiant contre la base IANA de PHP : le
            // fuseau décide de l'instant réel des bornes, une valeur inventée
            // décalerait silencieusement la clôture.
            'timezone' => ['required', 'string', 'timezone'],
            'opens_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'closes_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ouverture = $this->instant('opens_at');
            $cloture = $this->instant('closes_at');

            // Comparaison sur les instants, pas sur les chaînes : les deux
            // bornes sont lues dans le fuseau de la campagne, et c'est leur
            // ordre réel qui compte.
            if ($ouverture !== null && $cloture !== null && $cloture <= $ouverture) {
                $validator->errors()->add(
                    'closes_at',
                    __('La clôture doit être postérieure à l\'ouverture.'),
                );
            }

            // Une fenêtre sans début mais avec une fin laisserait `ActiveCampaign`
            // considérer la campagne ouverte depuis toujours. Acceptable pour une
            // campagne sans fin, pas pour une campagne sans début.
            if ($ouverture === null && $cloture !== null) {
                $validator->errors()->add(
                    'opens_at',
                    __('Une date de clôture exige une date d\'ouverture.'),
                );
            }
        });
    }

    /**
     * Données normalisées, prêtes pour `SaveCampaign`.
     *
     * @return array{code: string, name: string, status: CampaignStatus, timezone: string, opens_at: ?DateTimeImmutable, closes_at: ?DateTimeImmutable}
     */
    public function payload(): array
    {
        return [
            // Déjà normalisé par `prepareForValidation()`.
            'code' => (string) $this->input('code'),
            'name' => trim((string) $this->input('name')),
            'status' => CampaignStatus::from((string) $this->input('status')),
            'timezone' => (string) $this->input('timezone'),
            'opens_at' => $this->instant('opens_at'),
            'closes_at' => $this->instant('closes_at'),
        ];
    }

    /**
     * Convertit une saisie `Y-m-d\TH:i` en instant absolu.
     *
     * Le champ HTML `datetime-local` ne transporte aucun fuseau : « 23:59 » est
     * une heure murale. C'est le fuseau **de la campagne** qui lui donne un sens,
     * pas celui du serveur ni celui du navigateur de l'administrateur — un
     * gestionnaire à Paris qui fixe la clôture au 30 novembre 23:59 la fixe à
     * Niamey, pas chez lui.
     */
    private function instant(string $champ): ?DateTimeImmutable
    {
        $saisie = $this->input($champ);

        if (! is_string($saisie) || $saisie === '') {
            return null;
        }

        $fuseau = (string) $this->input('timezone');

        if ($fuseau === '' || ! in_array($fuseau, DateTimeZone::listIdentifiers(), strict: true)) {
            // Le fuseau est invalide : la règle `timezone` le signalera déjà.
            // On ne devine pas, on laisse la validation faire son travail.
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $saisie, new DateTimeZone($fuseau));

        if ($instant === false) {
            return null;
        }

        // Ramené à UTC avant d'être confié au modèle. Le cast de date sérialise
        // « Y-m-d H:i:s » sans décalage : PostgreSQL interpréterait alors cette
        // heure murale dans le fuseau de la session, et 08:00 à Niamey serait
        // enregistré comme 08:00 UTC — une heure d'écart, silencieuse.
        // Le format ne porte pas les secondes : on les remet à zéro plutôt que
        // d'hériter de l'instant courant.
        return $instant->setTime((int) $instant->format('H'), (int) $instant->format('i'))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
