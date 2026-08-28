<?php

namespace App\Domain\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * La requête du journal d'audit.
 *
 * Même forme que `ApplicationIndexQuery`, et pour les mêmes raisons : filtres,
 * tri et pagination se tiennent, et les avoir sous les yeux ensemble est ce qui
 * évite qu'un filtre parte sans son index ou qu'un tri accepte un nom de
 * colonne venu de l'URL.
 *
 * Trois partis pris propres à ce journal :
 *
 * 1. **Tout se filtre dans PostgreSQL.** La table est faite pour croître sans
 *    limite — un événement par action, jamais purgé — et sera vite la plus
 *    grosse du schéma. Rien ne peut être filtré après pagination.
 *
 * 2. **Le tri se limite à la date.** Un journal se lit dans l'ordre du temps ;
 *    trier par acteur ou par action donnerait des pages qui ne veulent rien
 *    dire. Deux sens, et c'est tout. L'identifiant départage les événements
 *    d'une même seconde, sans quoi la pagination pourrait montrer deux fois la
 *    même ligne.
 *
 * 3. **L'acteur n'est pas une clé étrangère**, et la migration l'assume :
 *    `actor_id` est un entier nullable sans contrainte. Un compte peut être
 *    supprimé sans effacer la trace de ce qu'il a fait — c'est même l'intérêt
 *    d'un journal. La jointure est donc volontairement externe, et la liste des
 *    acteurs proposée au filtre ne contient que ceux qui existent encore.
 *
 * Ce que cette classe **ne fait pas** : chercher dans `old_value` et
 * `new_value`. Ce sont des documents JSON dont la forme dépend de l'action ; y
 * promettre une recherche plein texte serait promettre un index qui n'existe
 * pas, et livrer un balayage de table à chaque frappe.
 */
final readonly class AuditIndexQuery
{
    /** Assez pour lire une séquence, assez peu pour ne pas noyer la page. */
    public const PER_PAGE = 30;

    /** Les deux sens de lecture. Toute autre valeur retombe sur `recent`. */
    public const SORTS = ['recent', 'ancien'];

    public function __construct(
        public ?AuditAction $action = null,
        public ?AuditTargetType $targetType = null,
        public ?int $actorId = null,
        public ?string $targetId = null,
        public ?string $since = null,
        public ?string $until = null,
        public string $sort = 'recent',
    ) {}

    public function paginate(): LengthAwarePaginator
    {
        return $this->builder()
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Le nombre total d'événements, filtres exclus.
     *
     * Distingue « le journal est vide » de « aucun événement ne correspond ».
     * Ce sont deux écrans, et les confondre ferait chercher une panne là où il
     * n'y a qu'un filtre trop étroit.
     */
    public static function total(): int
    {
        return AuditEvent::query()->count();
    }

    /**
     * Les acteurs proposés au filtre.
     *
     * Tirés des événements eux-mêmes, pas de la table des utilisateurs : un
     * administrateur qui n'a rien fait n'a pas à encombrer la liste. Les
     * comptes supprimés en sortent — leurs événements restent lisibles, mais on
     * ne peut plus filtrer sur un nom qui n'existe plus.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function actorOptions(): array
    {
        $identifiants = AuditEvent::query()
            ->whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id');

        return User::query()
            ->whereIn('id', $identifiants)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $acteur): array => [
                'value' => (string) $acteur->getKey(),
                'label' => $acteur->name,
            ])
            ->all();
    }

    private function builder(): Builder
    {
        $requete = AuditEvent::query();

        if ($this->action !== null) {
            $requete->where('action', $this->action->value);
        }

        if ($this->targetType !== null) {
            $requete->where('target_type', $this->targetType->value);
        }

        if ($this->actorId !== null) {
            $requete->where('actor_id', $this->actorId);
        }

        if ($this->targetId !== null) {
            $requete->where('target_id', $this->targetId);
        }

        // Bornes incluses toutes les deux : « du 3 au 5 » doit contenir le 5
        // entier, et non s'arrêter à son premier instant.
        if ($this->since !== null) {
            $requete->where('created_at', '>=', $this->since.' 00:00:00');
        }

        if ($this->until !== null) {
            $requete->where('created_at', '<=', $this->until.' 23:59:59');
        }

        $sens = $this->sort === 'ancien' ? 'asc' : 'desc';

        return $requete->orderBy('created_at', $sens)->orderBy('id', $sens);
    }
}
