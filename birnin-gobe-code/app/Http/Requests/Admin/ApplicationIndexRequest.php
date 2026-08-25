<?php

namespace App\Http\Requests\Admin;

use App\Domain\Application\ApplicationIndexQuery;
use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ProjectTheme;
use App\Domain\Candidate\CandidateType;
use App\Domain\Reference\NigerRegion;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Les filtres de la liste des candidatures, ramenés à des valeurs sûres.
 *
 * Une liste est une surface d'entrée comme une autre : ses filtres arrivent par
 * la barre d'adresse, donc de n'importe où. Aucun d'eux n'atteint le SQL sans
 * être passé par son référentiel — les statuts de l'enum, les régions du Niger,
 * les formes de candidature — et le tri par une liste blanche.
 *
 * **La validation se fait par réduction, pas par refus**, et c'est propre à un
 * écran de consultation : une valeur non reconnue est ignorée, la liste
 * s'affiche sans ce filtre, et le formulaire le montre vide — ce qui explique à
 * l'administrateur pourquoi il n'est pas appliqué. Un lien partagé dont un
 * paramètre a été tronqué doit ouvrir la liste, pas une page d'erreur.
 *
 * Ce parti pris ne vaut que pour la lecture. Toute écriture — campagne,
 * critères d'éligibilité — refuse une saisie invalide et le dit
 * (`SaveCampaignRequest`, `SaveEligibilitySettingsRequest`).
 *
 * La sécurité ne repose donc pas sur `rules()` mais sur le fait que rien ne
 * sort d'ici qui ne soit un enum du domaine, un entier, ou une chaîne de
 * recherche bornée et échappée par le constructeur de requêtes.
 */
final class ApplicationIndexRequest extends FormRequest
{
    /** Au-delà, ce n'est plus une recherche : le terme est tronqué, pas refusé. */
    private const SEARCH_MAX = 120;

    /**
     * La requête de liste, construite à partir des seuls filtres reconnus.
     *
     * `toIndexQuery` et non `query` : `Illuminate\Http\Request` expose déjà une
     * méthode `query()`, et la redéclarer avec une autre signature est une
     * erreur fatale.
     */
    public function toIndexQuery(): ApplicationIndexQuery
    {
        return new ApplicationIndexQuery(
            campaignId: $this->identifiant('campaign'),
            status: ApplicationStatus::tryFrom($this->chaine('status')),
            candidateType: CandidateType::tryFrom($this->chaine('type')),
            region: NigerRegion::tryFrom($this->chaine('region')),
            theme: ProjectTheme::tryFrom($this->chaine('theme')),
            search: $this->recherche(),
            sort: in_array($this->chaine('sort'), ApplicationIndexQuery::SORTS, strict: true)
                ? $this->chaine('sort')
                : 'recent',
        );
    }

    /**
     * Les filtres tels que l'écran doit les réafficher.
     *
     * Reconstruits depuis la requête et non depuis l'URL : le formulaire montre
     * ce qui a été retenu, pas ce qui a été demandé. Un paramètre ignoré
     * réapparaît vide.
     *
     * @return array{campaign: string, status: string, type: string, region: string, theme: string, q: string, sort: string}
     */
    public function filters(): array
    {
        $requete = $this->toIndexQuery();

        return [
            'campaign' => $requete->campaignId === null ? '' : (string) $requete->campaignId,
            'status' => $requete->status?->value ?? '',
            'type' => $requete->candidateType?->value ?? '',
            'region' => $requete->region?->value ?? '',
            'theme' => $requete->theme?->value ?? '',
            'q' => $requete->search ?? '',
            'sort' => $requete->sort,
        ];
    }

    /** Vrai dès qu'un filtre est actif : sert à distinguer les deux états vides. */
    public function hasActiveFilters(): bool
    {
        $filtres = $this->filters();
        unset($filtres['sort']);

        return array_filter($filtres) !== [];
    }

    /** Un identifiant est un entier positif ; « abc » ou « 0 » ne filtrent rien. */
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

    private function recherche(): ?string
    {
        $terme = mb_substr($this->chaine('q'), 0, self::SEARCH_MAX);

        return $terme === '' ? null : $terme;
    }
}
