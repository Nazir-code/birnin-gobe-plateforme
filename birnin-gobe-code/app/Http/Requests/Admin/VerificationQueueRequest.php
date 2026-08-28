<?php

namespace App\Http\Requests\Admin;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Verification\VerificationQueueQuery;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Les filtres de la file de vérification, ramenés à des valeurs sûres.
 *
 * Même politique que `AuditIndexRequest` : **validation par réduction, pas par
 * refus**. Un paramètre illisible est ignoré, la file s'ouvre sans lui, et le
 * formulaire le montre vide. Une file de travail dont on partage l'adresse dans
 * un fil d'équipe doit s'ouvrir même si un paramètre a été tronqué au
 * copier-coller.
 *
 * Rien ne sort d'ici qui ne soit un enum du domaine, un entier, ou l'une des
 * valeurs énumérées par `VerificationQueueQuery`.
 */
final class VerificationQueueRequest extends FormRequest
{
    /** Une recherche plus longue qu'un nom complet est une erreur de collage. */
    private const SEARCH_MAX = 120;

    public function toQueueQuery(): VerificationQueueQuery
    {
        return new VerificationQueueQuery(
            campaignId: $this->identifiant('campaign'),
            status: ApplicationStatus::tryFrom($this->chaine('status')),
            search: $this->recherche(),
            scope: in_array($this->chaine('scope'), VerificationQueueQuery::SCOPES, strict: true)
                ? $this->chaine('scope')
                : 'ouverts',
            sort: in_array($this->chaine('sort'), VerificationQueueQuery::SORTS, strict: true)
                ? $this->chaine('sort')
                : 'attente',
        );
    }

    /**
     * Les filtres tels que l'écran doit les réafficher.
     *
     * Reconstruits depuis la requête retenue, jamais depuis l'URL : le
     * formulaire montre ce qui a été appliqué, pas ce qui a été demandé.
     *
     * @return array{campaign: string, status: string, search: string, scope: string, sort: string}
     */
    public function filters(): array
    {
        $requete = $this->toQueueQuery();

        return [
            'campaign' => $requete->campaignId === null ? '' : (string) $requete->campaignId,
            'status' => $requete->status?->value ?? '',
            'search' => $requete->search ?? '',
            'scope' => $requete->scope,
            'sort' => $requete->sort,
        ];
    }

    /**
     * Vrai dès qu'un filtre restreint la file.
     *
     * Le périmètre et le tri en sont exclus : `ouverts` et `attente` sont l'état
     * normal d'une file, pas une restriction dont il faudrait proposer la levée.
     */
    public function hasActiveFilters(): bool
    {
        $filtres = $this->filters();
        unset($filtres['sort'], $filtres['scope']);

        return array_filter($filtres) !== [];
    }

    private function recherche(): ?string
    {
        $terme = mb_substr($this->chaine('search'), 0, self::SEARCH_MAX);

        return $terme === '' ? null : $terme;
    }

    private function identifiant(string $champ): ?int
    {
        $valeur = $this->input($champ);

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    private function chaine(string $champ): string
    {
        $valeur = $this->input($champ);

        return is_string($valeur) ? trim($valeur) : '';
    }
}
