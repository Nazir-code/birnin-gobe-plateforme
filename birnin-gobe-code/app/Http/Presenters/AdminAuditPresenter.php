<?php

namespace App\Http\Presenters;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditTargetType;
use App\Domain\Audit\AuditWeight;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Met un événement du journal en forme pour l'administration.
 *
 * Le journal stocke des clés stables et deux documents JSON ; l'écran doit
 * afficher des phrases. Toute la traduction se fait ici, et nulle part ailleurs
 * — surtout pas dans React, qui finirait par tenir sa propre table de libellés
 * à côté de celle du domaine.
 *
 * **Rien n'est deviné.** Une action que `AuditAction` ne connaît pas, un type de
 * cible qu'`AuditTargetType` ne connaît pas, un acteur dont le compte a été
 * supprimé : chacun de ces trois cas arrive, et chacun a une sortie explicite
 * plutôt qu'un vide. Un journal qui masque ce qu'il ne comprend pas est pire
 * qu'un journal qui l'affiche brut.
 *
 * **Les acteurs sont résolus en une fois.** `actor_id` n'a pas de clé étrangère
 * — c'est délibéré, un compte supprimé ne doit pas effacer sa trace — donc
 * aucune relation Eloquent ne les charge. Sans la carte passée au constructeur,
 * chaque ligne interrogerait la table des utilisateurs, et une page de trente
 * événements ferait trente et une requêtes.
 */
final readonly class AdminAuditPresenter
{
    /** @param Collection<int, User> $acteurs Indexée par identifiant. */
    public function __construct(private Collection $acteurs) {}

    /**
     * Construit le présentateur pour une page d'événements.
     *
     * @param  iterable<AuditEvent>  $evenements
     */
    public static function pour(iterable $evenements): self
    {
        $identifiants = [];

        foreach ($evenements as $evenement) {
            if ($evenement->actor_id !== null) {
                $identifiants[] = $evenement->actor_id;
            }
        }

        return new self(
            User::query()
                ->whereIn('id', array_unique($identifiants))
                ->get(['id', 'name', 'email', 'role'])
                ->keyBy('id'),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     action: string,
     *     actionLabel: string,
     *     weight: string,
     *     actor: array{id: int|null, name: string, email: string|null, known: bool},
     *     target: array{type: string, typeLabel: string, id: string, url: string|null},
     *     changes: list<array{field: string, before: string|null, after: string|null}>,
     *     source: string|null,
     *     reason: string|null,
     *     occurredAt: string|null,
     * }
     */
    public function row(AuditEvent $evenement): array
    {
        $action = AuditAction::tryFrom((string) $evenement->action);
        $type = AuditTargetType::tryFrom((string) $evenement->target_type);
        $cible = (string) $evenement->target_id;

        return [
            'id' => (int) $evenement->getKey(),
            'action' => (string) $evenement->action,
            // Une action inconnue s'affiche telle qu'elle est stockée : le
            // journal reste lisible même si le code a changé depuis.
            'actionLabel' => $action?->label() ?? (string) $evenement->action,
            'weight' => ($action?->weight() ?? AuditWeight::ROUTINE)->value,
            'actor' => $this->acteur($evenement),
            'target' => [
                'type' => (string) $evenement->target_type,
                'typeLabel' => $type?->label() ?? $this->nomCourt((string) $evenement->target_type),
                'id' => $cible,
                'url' => $type?->url($cible),
            ],
            'changes' => $this->changements($evenement),
            'source' => $this->texte($evenement->technical_source),
            'reason' => $this->texte($evenement->reason),
            'occurredAt' => $evenement->created_at?->toIso8601String(),
        ];
    }

    /**
     * L'auteur de l'action, ou ce qu'il en reste.
     *
     * Un compte supprimé garde ses événements : c'est l'intérêt même du
     * journal. L'écran doit alors dire « compte supprimé » et donner
     * l'identifiant, plutôt que de laisser une case vide qui se lirait comme
     * une action sans auteur — ce qui n'est pas la même chose.
     *
     * @return array{id: int|null, name: string, email: string|null, known: bool}
     */
    private function acteur(AuditEvent $evenement): array
    {
        $identifiant = $evenement->actor_id === null ? null : (int) $evenement->actor_id;

        if ($identifiant === null) {
            return ['id' => null, 'name' => 'Système', 'email' => null, 'known' => false];
        }

        $utilisateur = $this->acteurs->get($identifiant);

        if (! $utilisateur instanceof User) {
            return [
                'id' => $identifiant,
                'name' => 'Compte supprimé (#'.$identifiant.')',
                'email' => null,
                'known' => false,
            ];
        }

        return [
            'id' => $identifiant,
            'name' => $utilisateur->name,
            'email' => $utilisateur->email,
            'known' => true,
        ];
    }

    /**
     * Ce qui a changé, champ par champ.
     *
     * Les deux documents n'ont pas les mêmes clés selon l'action — une création
     * n'a pas d'avant, un retrait de pièce n'a pas d'après. On prend donc
     * l'union des clés, dans l'ordre où elles apparaissent, et chaque valeur
     * est aplatie en une chaîne lisible.
     *
     * Aucune de ces valeurs n'est une donnée personnelle : les cas d'usage n'y
     * écrivent que des statuts, des identifiants, des noms de fichiers et des
     * paramètres de campagne. Le jour où l'un d'eux y verserait autre chose, ce
     * serait à la source qu'il faudrait le corriger, pas à l'affichage.
     *
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    private function changements(AuditEvent $evenement): array
    {
        $avant = is_array($evenement->old_value) ? $evenement->old_value : [];
        $apres = is_array($evenement->new_value) ? $evenement->new_value : [];

        $cles = array_keys($avant + $apres);
        $lignes = [];

        foreach ($cles as $cle) {
            $before = array_key_exists($cle, $avant) ? $this->aplatir($avant[$cle]) : null;
            $after = array_key_exists($cle, $apres) ? $this->aplatir($apres[$cle]) : null;

            // Une clé présente des deux côtés avec la même valeur n'est pas un
            // changement : l'afficher noierait ceux qui en sont.
            if ($before !== null && $before === $after) {
                continue;
            }

            $lignes[] = ['field' => (string) $cle, 'before' => $before, 'after' => $after];
        }

        return $lignes;
    }

    /**
     * Ramène une valeur JSON à une ligne de texte.
     *
     * Les documents restent peu profonds — un statut, un numéro, une liste de
     * régions, un sous-objet de critères. Au-delà de ce que cette méthode sait
     * rendre, on affiche le JSON compact plutôt que de tronquer en silence.
     */
    private function aplatir(mixed $valeur): string
    {
        if ($valeur === null) {
            return '—';
        }

        if (is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }

        if (is_scalar($valeur)) {
            return (string) $valeur;
        }

        if (is_array($valeur) && array_is_list($valeur) && $valeur === array_filter($valeur, 'is_scalar')) {
            return $valeur === [] ? '—' : implode(', ', array_map(strval(...), $valeur));
        }

        return json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }

    /** `App\Models\Truc` → `Truc`, pour un type que l'enum ne connaît pas. */
    private function nomCourt(string $classe): string
    {
        $position = strrpos($classe, '\\');

        return $position === false ? $classe : substr($classe, $position + 1);
    }

    private function texte(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }

        $propre = trim($valeur);

        return $propre === '' ? null : $propre;
    }
}
