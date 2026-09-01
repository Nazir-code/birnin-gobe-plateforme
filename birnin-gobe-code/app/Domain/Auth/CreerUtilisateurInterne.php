<?php

namespace App\Domain\Auth;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditWriter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Création d'un compte interne — administrateur ou évaluateur (ADR-006, ADR-022).
 *
 * **Un cas d'usage, deux appelants.** La logique vivait dans une commande
 * Artisan, donc hors de portée d'un contrôleur : ouvrir la création aux
 * administrateurs depuis le back-office aurait dupliqué les règles de mot de
 * passe, d'unicité et de rôle. ADR-006 dit ce qu'il advient de deux copies
 * d'une règle de sécurité. La commande et le contrôleur appellent désormais
 * cette classe, qui est le seul endroit où un compte interne naît.
 *
 * **Le rôle est un argument du cas d'usage, jamais une donnée de formulaire.**
 * Les appelants le fixent — `evaluator:create` en dur, le contrôleur
 * d'administration en dur lui aussi. Aucun champ de requête ne l'atteint, et
 * `role` reste hors de `$fillable` : les deux barrières d'ADR-004 tiennent
 * toujours.
 *
 * **La validation n'est pas ici.** Chaque appelant valide dans son idiome — un
 * `Validator` pour la commande, un `FormRequest` pour le contrôleur — parce que
 * les messages et le rendu d'erreur diffèrent entre un terminal et un
 * formulaire. Ce que cette classe garantit est ce qui suit la validation :
 * l'unicité tenue par la base, le rôle imposé, l'audit écrit.
 *
 * **Sans mot de passe, le compte existe mais personne n'y entre.** Un compte
 * créé par invitation reçoit une valeur aléatoire que nul ne connaît — ni
 * l'administrateur qui l'a créé, ni le destinataire. Seul le lien d'invitation
 * ouvre l'accès. C'est ce qui fait qu'un compte notant des candidatures n'a
 * qu'un seul détenteur.
 */
final readonly class CreerUtilisateurInterne
{
    public function __construct(private AuditWriter $audit) {}

    /**
     * @param  string|null  $motDePasse  null pour un compte créé par invitation
     */
    public function handle(
        string $nom,
        string $email,
        UserRole $role,
        ?string $motDePasse = null,
        ?User $acteur = null,
    ): User {
        return DB::transaction(function () use ($nom, $email, $role, $motDePasse, $acteur): User {
            $utilisateur = new User;
            $utilisateur->fill([
                'name' => trim($nom),
                // Normalisée ici comme à la validation : deux adresses qui ne
                // diffèrent que par la casse sont la même personne.
                'email' => Str::lower(trim($email)),
                // Une valeur que personne ne connaît, et qui ne sert jamais :
                // l'invitation est le seul chemin d'entrée. `Str::random(64)`
                // plutôt qu'une chaîne vide, qu'un `Hash::check` pourrait
                // valider dans certaines implémentations.
                'password' => $motDePasse ?? Str::random(64),
            ]);
            // Imposé par l'appelant, jamais lu depuis une saisie.
            $utilisateur->role = $role;
            // Haché par le cast `password` du modèle (argon2id).
            $utilisateur->save();

            // **Habiliter quelqu'un est une décision, pas une écriture
            // technique.** Le §13.3 recense qui a décidé quoi ; créer un compte
            // qui notera des candidatures en fait partie. L'acteur est nul
            // quand la commande tourne en ligne de commande — personne n'est
            // authentifié — et `AuditWriter` sait l'écrire ainsi plutôt que
            // d'attribuer le geste à quelqu'un qui ne l'a pas fait.
            $this->audit->write(
                actorId: $acteur?->getKey(),
                action: AuditAction::INTERNAL_USER_CREATED->value,
                targetType: User::class,
                targetId: (string) $utilisateur->getKey(),
                oldValue: null,
                newValue: [
                    'role' => $role->value,
                    'email' => $utilisateur->email,
                    'par_invitation' => $motDePasse === null,
                ],
                reason: null,
            );

            return $utilisateur;
        });
    }
}
