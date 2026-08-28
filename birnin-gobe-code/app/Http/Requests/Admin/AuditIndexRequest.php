<?php

namespace App\Http\Requests\Admin;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditIndexQuery;
use App\Domain\Audit\AuditTargetType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Les filtres du journal d'audit, ramenés à des valeurs sûres.
 *
 * Même principe que `ApplicationIndexRequest`, et la même raison de l'appliquer
 * ici : les filtres arrivent par la barre d'adresse, donc de n'importe où.
 *
 * **La validation se fait par réduction, pas par refus.** Une valeur non
 * reconnue est ignorée, la liste s'affiche sans ce filtre, et le formulaire le
 * montre vide — ce qui explique à l'administrateur pourquoi il n'est pas
 * appliqué. Un lien de journal partagé dans un compte rendu d'incident, dont un
 * paramètre a été tronqué au copier-coller, doit ouvrir le journal plutôt
 * qu'une page d'erreur.
 *
 * Rien ne sort d'ici qui ne soit un enum du domaine, un entier, ou une date au
 * format `AAAA-MM-JJ` vérifié.
 */
final class AuditIndexRequest extends FormRequest
{
    /** Un identifiant de cible est stocké en texte, mais reste un nombre. */
    private const TARGET_ID_MAX = 20;

    public function toIndexQuery(): AuditIndexQuery
    {
        [$depuis, $jusqua] = $this->intervalle();

        return new AuditIndexQuery(
            action: AuditAction::tryFrom($this->chaine('action')),
            targetType: AuditTargetType::tryFrom($this->chaine('target')),
            actorId: $this->identifiant('actor'),
            targetId: $this->cible(),
            since: $depuis,
            until: $jusqua,
            sort: in_array($this->chaine('sort'), AuditIndexQuery::SORTS, strict: true)
                ? $this->chaine('sort')
                : 'recent',
        );
    }

    /**
     * Les filtres tels que l'écran doit les réafficher.
     *
     * Reconstruits depuis la requête, jamais depuis l'URL : le formulaire
     * montre ce qui a été retenu, pas ce qui a été demandé.
     *
     * @return array{action: string, target: string, actor: string, id: string, since: string, until: string, sort: string}
     */
    public function filters(): array
    {
        $requete = $this->toIndexQuery();

        return [
            'action' => $requete->action?->value ?? '',
            'target' => $requete->targetType?->value ?? '',
            'actor' => $requete->actorId === null ? '' : (string) $requete->actorId,
            'id' => $requete->targetId ?? '',
            'since' => $requete->since ?? '',
            'until' => $requete->until ?? '',
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

    /**
     * Les deux bornes de dates, remises dans l'ordre si besoin.
     *
     * Un intervalle saisi à l'envers ne rend pas zéro ligne sans explication :
     * il est retourné. C'est la même politique de réduction que le reste — on
     * comprend l'intention plutôt que de punir la saisie.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function intervalle(): array
    {
        $depuis = $this->jour('since');
        $jusqua = $this->jour('until');

        if ($depuis !== null && $jusqua !== null && $depuis > $jusqua) {
            return [$jusqua, $depuis];
        }

        return [$depuis, $jusqua];
    }

    /**
     * Une date est un jour du calendrier, pas une chaîne qui y ressemble.
     *
     * `jour` et non `date` : `Illuminate\Http\Request` expose déjà une méthode
     * `date()` publique, et la redéclarer en privé est une erreur fatale — la
     * même chausse-trape que `toIndexQuery` face à `query()`.
     */
    private function jour(string $champ): ?string
    {
        $valeur = $this->chaine($champ);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) !== 1) {
            return null;
        }

        [$annee, $mois, $jour] = array_map('intval', explode('-', $valeur));

        return checkdate($mois, $jour, $annee) ? $valeur : null;
    }

    /** `target_id` est une colonne de texte, mais tout ce qu'on y écrit est un entier. */
    private function cible(): ?string
    {
        $valeur = mb_substr($this->chaine('id'), 0, self::TARGET_ID_MAX);

        return ctype_digit($valeur) ? $valeur : null;
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
